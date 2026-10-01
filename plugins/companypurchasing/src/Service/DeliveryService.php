<?php

/**
 * ENTREGA física y CIERRE administrativo de Compras (P2D-4; ADR-0023 §2–§6).
 *
 * `deliver()` (`RIGHT_DELIVER` + entidad), con `idempotency_key` OBLIGATORIA, en UNA transacción:
 *   BEGIN → `SELECT … FOR UPDATE` de las unidades en ORDEN ESTABLE (id) → lote existente por clave (lectura con lock,
 *   DESPUÉS del lock de las unidades: así dos entregas de las mismas unidades se serializan en las unidades y nunca
 *   cruzan gap-locks del índice de la clave, que provocaban deadlocks) → validar contra lo LEÍDO BAJO LOCK
 *   (pertenencia, `physical_state = RECEIVED`, no entregada) → gate de inventario
 *   (inventariable ⇒ handoff del outbox PROPIO en DONE) → INSERT lote → UPDATE unidades (condicionado; affectedRows
 *   exacto) → `delivery_seq` += 1 → auditoría → COMMIT.
 * Cualquier fallo ⇒ ROLLBACK completo (jamás una entrega parcial accidental). Misma clave + misma entrada ⇒ el MISMO
 * lote; misma clave con otra entrada ⇒ conflicto. Dos entregas concurrentes de la misma unidad se SERIALIZAN por el
 * `FOR UPDATE`: la segunda espera el COMMIT de la primera y ve la unidad ya entregada en su validación (resultado
 * controlado `unit_not_deliverable`). La saga del motor (RECEIVED → DELIVERED cuando TODO está entregado) corre
 * DESPUÉS del COMMIT (nunca `WorkflowApi::transition()` dentro de la transacción) y converge por la Acción automática
 * si el proceso cae; jamás se deshace una entrega física porque el motor falló.
 *
 * `closeRequest()` (`RIGHT_MANAGE_PURCHASING` + entidad): motor en DELIVERED, todo recibido y entregado, inventario
 * DONE e integridad limpia ⇒ `close` (estado FINAL del motor). Idempotente: ya CLOSED ⇒ sin segunda transición ni
 * eventos duplicados.
 *
 * NO consulta tablas de companyintegrations, NO escribe a Snipe-IT, NO crea activos ni códigos QR.
 *
 * No es `final`: los tests deterministas inyectan pausas/caídas en `afterUnitsLocked()`, `afterDeliveryCommit()` y
 * `afterCloseTransition()`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\DeliveryBatch;
use GlpiPlugin\Companypurchasing\Model\OutboxEntry;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\ReceiptUnit;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;

class DeliveryService
{
    public const MAX_NOTES_LENGTH = 4000;

    protected WorkflowGateway $wf;
    protected Audit $audit;
    protected AdvisoryLock $lock;
    protected ReceivingSync $sync;
    protected ApprovalOrchestrator $orch;

    public function __construct(
        ?WorkflowGateway $wf = null,
        ?Audit $audit = null,
        ?AdvisoryLock $lock = null,
        ?ReceivingSync $sync = null,
        ?ApprovalOrchestrator $orch = null
    ) {
        $this->wf    = $wf ?? new WorkflowGateway();
        $this->audit = $audit ?? new Audit();
        $this->lock  = $lock ?? new AdvisoryLock();
        $this->sync  = $sync ?? new ReceivingSync($this->wf, $this->audit, $this->lock);
        $this->orch  = $orch ?? new ApprovalOrchestrator($this->wf, null, null, $this->audit, $this->lock);
    }

    // ================================================================ entrega

    /**
     * Registra una entrega física (lote) de forma atómica e idempotente.
     *
     * @param array<int,string> $receiptUnitUuids
     * @return array{status:string, batch_id:int, units:array<int,string>, sync:array<string,mixed>}
     * @throws DeliveryException|\InvalidArgumentException
     */
    public function deliver(int $requestId, array $receiptUnitUuids, int $recipientUserId, string $idempotencyKey, string $notes = ''): array
    {
        if (preg_match(ReceivingService::IDEMPOTENCY_PATTERN, $idempotencyKey) !== 1) {
            throw new DeliveryException(DeliveryException::INVALID, 'idempotency_key obligatoria (8–190, [A-Za-z0-9._:-]) (fail-closed)');
        }
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_DELIVER)) {
            throw new DeliveryException(DeliveryException::ACL, 'permiso denegado (DELIVER)');
        }
        $req = $this->loadRequest($requestId);
        $this->assertEntity($req);
        $entity = (int) $req->fields['entities_id'];
        try {
            $uuids = DeliveryRules::normalizeUuids($receiptUnitUuids, PluginConfig::deliveryMaxUnitsPerBatch());
        } catch (\InvalidArgumentException $e) {
            throw new DeliveryException(DeliveryException::INVALID, $e->getMessage());
        }
        $notes = trim($notes);
        if (mb_strlen($notes) > self::MAX_NOTES_LENGTH || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $notes) === 1) {
            throw new DeliveryException(DeliveryException::INVALID, 'notas inválidas');
        }
        $inputSha = DeliveryRules::inputHash($requestId, $uuids, $recipientUserId, $notes);

        // Reintento de la MISMA operación ⇒ el MISMO lote (antes de cualquier otra validación de estado).
        $replay = $this->replay($idempotencyKey, $requestId, $inputSha);
        if ($replay !== null) {
            return $replay;
        }
        $this->assertRecipient($recipientUserId, $entity);
        if (empty($req->fields['purchase_started_at'])) {
            throw new DeliveryException(DeliveryException::STATE, 'la compra no fue iniciada: no se entrega (fail-closed)');
        }
        if (!$this->orch->integrityStatus($requestId)['clean']) {
            throw new DeliveryException(DeliveryException::STATE, 'integridad de aprobación no limpia: no se entrega (fail-closed)');
        }
        // Una sincronización previa pendiente (p. ej. la recepción final) converge antes de entregar.
        $this->syncBestEffort($requestId);
        $inst = $this->wf->loadInstance((int) ($req->fields['workflow_instances_id'] ?? 0));
        $state = $inst !== null && $this->wf->isOpen($inst) ? $this->wf->stateCode($inst) : '';
        if (!in_array($state, PurchasingWorkflow::DELIVERING_STATES, true)) {
            throw new DeliveryException(DeliveryException::STATE, "el estado del workflow ({$state}) no admite entregas: sólo con TODO recibido (fail-closed)");
        }
        if (!$this->wf->definitionHasAction($inst, PurchasingWorkflow::A_DELIVER_COMPLETE)) {
            throw new DeliveryException(DeliveryException::LEGACY_DEFINITION, 'la instancia usa una versión anterior de la definición sin fase de entrega: se reporta, no se migra (fail-closed)');
        }

        /** @var \DBmysql $DB */
        global $DB;
        $now   = $this->now();
        $actor = (int) (Session::getLoginUserID() ?: 0);
        $corr  = (string) ($req->fields['correlation_id'] ?? '');

        for ($attempt = 1; ; $attempt++) {
            try {
                $batchId = $this->deliverTx($req, $requestId, $uuids, $recipientUserId, $idempotencyKey, $notes, $inputSha, $now, $actor, $corr);
                break;
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                // Carrera con la MISMA clave: el otro proceso confirmó primero ⇒ mismo lote (o conflicto si otra entrada).
                $replay = $this->replay($idempotencyKey, $requestId, $inputSha, false);
                if ($replay !== null) {
                    return $replay;
                }
                // Deadlock / espera de lock de InnoDB: se reintenta UNA vez la transacción completa (recomendación de
                // MariaDB/MySQL); la revalidación bajo lock decide el resultado controlado.
                if ($attempt < 2 && preg_match('/\b(1213|1205)\b|deadlock|lock wait timeout/i', $e->getMessage()) === 1) {
                    continue;
                }
                throw $e;
            }
        }
        if ($batchId < 0) {
            return $this->replay($idempotencyKey, $requestId, $inputSha)
                ?? throw new DeliveryException(DeliveryException::CONCURRENCY_CONFLICT, 'lote de entrega inconsistente (fail-closed)');
        }

        $this->afterDeliveryCommit($batchId);
        return ['status' => 'recorded', 'batch_id' => $batchId, 'units' => $uuids, 'sync' => $this->syncBestEffort($requestId)];
    }

    /**
     * Transacción ATÓMICA de la entrega. Devuelve el id del lote creado, o -1 si la clave ya tenía lote (replay).
     *
     * @param array<int,string> $uuids
     */
    private function deliverTx(Request $req, int $requestId, array $uuids, int $recipientUserId, string $idempotencyKey, string $notes,
        string $inputSha, string $now, int $actor, string $corr): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $entity = (int) $req->fields['entities_id'];
        $units = ReceiptUnit::getTable();
        $DB->beginTransaction();
        try {
            // (a) LOCK de las unidades en ORDEN ESTABLE (id): la serialización de entregas concurrentes ocurre AQUÍ.
            $in = implode(',', array_map(static fn (string $u): string => "'" . $DB->escape($u) . "'", $uuids));
            $res = $DB->doQuery("SELECT * FROM `{$units}` WHERE `requests_id` = " . $requestId
                . " AND `receipt_unit_uuid` IN ({$in}) ORDER BY `id` FOR UPDATE");
            $locked = [];
            while ($res !== false && ($row = $DB->fetchAssoc($res))) {
                $locked[(string) $row['receipt_unit_uuid']] = $row;
            }
            if (count($locked) !== count($uuids)) {
                $missing = array_values(array_diff($uuids, array_keys($locked)));
                throw new DeliveryException(DeliveryException::UNIT_NOT_DELIVERABLE, 'unidad inexistente o ajena a la solicitud (fail-closed)',
                    ['units' => array_map([DeliveryRules::class, 'shortUuid'], $missing)]);
            }
            $this->afterUnitsLocked(array_map(static fn (array $r): int => (int) $r['id'], array_values($locked)));

            // (b) ¿La clave ya fue confirmada por otro proceso? (lectura CON lock, DESPUÉS del lock de las unidades)
            $res = $DB->doQuery('SELECT `id` FROM `' . DeliveryBatch::getTable() . "` WHERE `idempotency_key` = '" . $DB->escape($idempotencyKey) . "' FOR UPDATE");
            if ($res !== false && $DB->numrows($res) > 0) {
                $this->safeRollback($DB);
                return -1;
            }

            // (c) Validación contra lo leído BAJO LOCK: cada unidad RECEIVED y nunca entregada.
            $taken = [];
            foreach ($locked as $uuid => $row) {
                if ((string) $row['physical_state'] !== ReceiptUnit::PHYSICAL_RECEIVED || $row['delivery_batches_id'] !== null) {
                    $taken[] = DeliveryRules::shortUuid((string) $uuid);
                }
            }
            if ($taken !== []) {
                throw new DeliveryException(DeliveryException::UNIT_NOT_DELIVERABLE, 'unidad ya entregada o no entregable: ' . implode(',', $taken), ['units' => $taken]);
            }
            // (d) Gate de inventario: inventariable ⇒ su handoff (outbox PROPIO de Compras) DONE.
            $statuses = $this->outboxStatuses(array_keys(array_filter($locked, static fn (array $r): bool => (int) $r['is_inventoriable'] === 1)));
            $blocked = [];
            foreach ($locked as $uuid => $row) {
                $gate = DeliveryRules::gateStatus((int) $row['is_inventoriable'] === 1, $statuses[$uuid] ?? null);
                if ($gate !== DeliveryRules::GATE_DELIVERABLE) {
                    $blocked[DeliveryRules::shortUuid((string) $uuid)] = $gate;
                }
            }
            if ($blocked !== []) {
                throw new DeliveryException(DeliveryException::INVENTORY_GATE, 'inventario no confirmado (DONE) para: ' . implode(',', array_keys($blocked)), ['gates' => $blocked]);
            }
            // (e) Lote.
            $DB->insert(DeliveryBatch::getTable(), [
                'requests_id'        => $requestId,
                'entities_id'        => $entity,
                'idempotency_key'    => $idempotencyKey,
                'input_sha256'       => $inputSha,
                'actor_users_id'     => $actor,
                'recipient_users_id' => $recipientUserId,
                'delivered_at'       => $now,
                'notes'              => $notes,
                'units_count'        => count($uuids),
                'correlation_id'     => $corr,
                'date_creation'      => $now,
            ]);
            $batchId = (int) $DB->insertId();
            if ($batchId <= 0) {
                throw new DeliveryException(DeliveryException::CONCURRENCY_CONFLICT, 'no se pudo registrar el lote de entrega');
            }
            // (f) Unidades → DELIVERED, condicionado (defensa en profundidad: el lock ya serializó).
            $ids = array_map(static fn (array $r): int => (int) $r['id'], array_values($locked));
            $DB->update($units, [
                'physical_state'        => ReceiptUnit::PHYSICAL_DELIVERED,
                'delivery_batches_id'   => $batchId,
                'delivered_at'          => $now,
                'delivered_to_users_id' => $recipientUserId,
                'date_mod'              => $now,
            ], ['id' => $ids, 'requests_id' => $requestId, 'physical_state' => ReceiptUnit::PHYSICAL_RECEIVED, 'delivery_batches_id' => null]);
            if ($DB->affectedRows() !== count($ids)) {
                throw new DeliveryException(DeliveryException::CONCURRENCY_CONFLICT, 'conflicto de concurrencia al entregar (reintente)');
            }
            // (g) Marcador durable de sincronización con el motor + auditoría, en la MISMA transacción.
            $DB->update(Request::getTable(), ['delivery_seq' => new \Glpi\DBAL\QueryExpression('`delivery_seq` + 1')], ['id' => $requestId]);
            if ($DB->affectedRows() !== 1) {
                throw new DeliveryException(DeliveryException::CONCURRENCY_CONFLICT, 'no se pudo registrar el marcador de sincronización de entrega');
            }
            $this->audit->record($requestId, PurchasingEvent::EV_DELIVERY_RECORDED, $entity, [
                'batch_id' => $batchId, 'idempotency_key' => $idempotencyKey, 'units' => count($uuids),
                'recipient_users_id' => $recipientUserId,
            ], $corr, 'delivery:' . $idempotencyKey);
            $DB->commit();
        } catch (\Throwable $e) {
            $this->safeRollback($DB);
            throw $e;
        }
        return $batchId;
    }

    // ================================================================ cierre

    /**
     * Cierre administrativo (idempotente).
     *
     * @return array{status:string, state:string}
     * @throws DeliveryException
     */
    public function closeRequest(int $requestId, string $comment = ''): array
    {
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_MANAGE_PURCHASING)) {
            throw new DeliveryException(DeliveryException::ACL, 'permiso denegado (MANAGE_PURCHASING)');
        }
        $req = $this->loadRequest($requestId);
        $this->assertEntity($req);
        if (mb_strlen($comment) > self::MAX_NOTES_LENGTH) {
            throw new DeliveryException(DeliveryException::INVALID, 'comentario demasiado largo');
        }
        // Una entrega confirmada con la saga pendiente converge primero (RECEIVED → DELIVERED).
        $this->syncBestEffort($requestId);

        return (array) $this->lock->withRequestLock($requestId, function () use ($requestId, $comment): array {
            $req  = $this->loadRequest($requestId);
            $inst = $this->wf->loadInstance((int) ($req->fields['workflow_instances_id'] ?? 0));
            if ($inst === null || (string) $inst->fields['itemtype'] !== Request::class || (int) $inst->fields['items_id'] !== $requestId) {
                throw new DeliveryException(DeliveryException::STATE, 'la solicitud no tiene instancia de workflow (fail-closed)');
            }
            $state = $this->wf->stateCode($inst);
            if (!$this->wf->isOpen($inst) && $state === PurchasingWorkflow::S_CLOSED) {
                // Ya cerrada (p. ej. reintento tras una caída posterior a la transición): sin segunda transición.
                $this->recordClosed($req, 0, $comment);
                (new StateProjection($this->wf, $this->audit))->sync($req);
                return ['status' => 'already_closed', 'state' => PurchasingWorkflow::S_CLOSED];
            }
            $blockers = $this->blockers($req, $inst);
            if ($blockers !== []) {
                throw new DeliveryException(DeliveryException::NOT_READY_TO_CLOSE, 'la solicitud no está lista para cerrar: ' . implode(',', $blockers), ['blockers' => $blockers]);
            }
            $lockBefore = (int) $inst->fields['lock_version'];
            $res = $this->wf->transition($inst, PurchasingWorkflow::A_CLOSE, [
                'comment'               => $comment !== '' ? $comment : 'close',
                // Condición de la definición: sólo este código la aporta (requisitos ya verificados).
                'fields'                => ['close_bound' => 1],
                'expected_lock_version' => $lockBefore,
            ]);
            if (!$res->success) {
                throw new DeliveryException(DeliveryException::STATE, 'el motor rechazó el cierre: ' . $res->code . ' — ' . $res->message);
            }
            $this->afterCloseTransition($requestId);
            $this->recordClosed($req, $lockBefore, $comment);
            (new StateProjection($this->wf, $this->audit))->sync($req);
            return ['status' => 'closed', 'state' => PurchasingWorkflow::S_CLOSED];
        });
    }

    /**
     * Motivos que bloquean el cierre (lectura con ACL de vista). Vacío ⇒ lista para cerrar.
     *
     * @return array{state:string, blockers:array<int,string>}
     */
    public function closeReadiness(int $requestId): array
    {
        $req = $this->loadRequest($requestId);
        if (!(new RequestManager())->canView($req)) {
            throw new DeliveryException(DeliveryException::ACL, 'sin permiso para ver la solicitud');
        }
        $inst = $this->wf->loadInstance((int) ($req->fields['workflow_instances_id'] ?? 0));
        if ($inst === null) {
            return ['state' => '', 'blockers' => [DeliveryRules::BLOCK_STATE]];
        }
        return ['state' => $this->wf->stateCode($inst), 'blockers' => $this->blockers($req, $inst)];
    }

    /**
     * Unidades de la solicitud con su gate de inventario (lectura con ACL: vista de la solicitud, RECEIVE o DELIVER;
     * entidad). NO expone `last_error`, tokens de lease ni datos técnicos del handoff.
     *
     * @return array<int,array<string,mixed>>
     */
    public function unitsWithGate(int $requestId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $req = $this->loadRequest($requestId);
        $this->assertEntity($req);
        if (!(new RequestManager())->canView($req)
            && !Session::haveRight(Request::$rightname, Request::RIGHT_DELIVER)
            && !Session::haveRight(Request::$rightname, Request::RIGHT_RECEIVE)) {
            throw new DeliveryException(DeliveryException::ACL, 'sin permiso para ver las unidades');
        }
        $rows = [];
        foreach ($DB->request(['FROM' => ReceiptUnit::getTable(), 'WHERE' => ['requests_id' => $requestId], 'ORDER' => 'id ASC']) as $row) {
            $rows[] = $row;
        }
        $statuses = $this->outboxStatuses(array_map(static fn (array $r): string => (string) $r['receipt_unit_uuid'],
            array_filter($rows, static fn (array $r): bool => (int) $r['is_inventoriable'] === 1)));
        $lines = [];
        foreach ($DB->request(['SELECT' => ['id', 'line_no', 'description'], 'FROM' => RequestItem::getTable(), 'WHERE' => ['requests_id' => $requestId]]) as $l) {
            $lines[(int) $l['id']] = $l;
        }
        $out = [];
        foreach ($rows as $r) {
            $uuid = (string) $r['receipt_unit_uuid'];
            $delivered = (string) $r['physical_state'] === ReceiptUnit::PHYSICAL_DELIVERED;
            $out[] = [
                'id'                    => (int) $r['id'],
                'receipt_unit_uuid'     => $uuid,
                'short_uuid'            => DeliveryRules::shortUuid($uuid),
                'items_id'              => (int) $r['items_id'],
                'line_no'               => (int) ($lines[(int) $r['items_id']]['line_no'] ?? 0),
                'description'           => (string) ($lines[(int) $r['items_id']]['description'] ?? ''),
                'unit_index'            => (int) $r['unit_index'],
                'serial'                => $r['serial'] === null ? '' : (string) $r['serial'],
                'is_inventoriable'      => (int) $r['is_inventoriable'] === 1,
                'physical_state'        => (string) $r['physical_state'],
                'outbox_status'         => (int) $r['is_inventoriable'] === 1 ? ($statuses[$uuid] ?? null) : null,
                'gate'                  => $delivered ? DeliveryRules::GATE_ALREADY_DELIVERED
                    : DeliveryRules::gateStatus((int) $r['is_inventoriable'] === 1, $statuses[$uuid] ?? null),
                'delivered_at'          => (string) ($r['delivered_at'] ?? ''),
                'delivered_to_users_id' => (int) ($r['delivered_to_users_id'] ?? 0),
                'delivery_batches_id'   => (int) ($r['delivery_batches_id'] ?? 0),
            ];
        }
        return $out;
    }

    // ================================================================ puntos de inyección (tests)

    /** Dentro de la transacción, con las unidades YA bloqueadas (tests de serialización por lock). @param array<int,int> $unitIds */
    protected function afterUnitsLocked(array $unitIds): void
    {
    }

    /** Entrega CONFIRMADA, antes de la saga del motor (tests de caída). */
    protected function afterDeliveryCommit(int $batchId): void
    {
    }

    /** Cierre CONFIRMADO por el motor, antes de auditar/proyectar (tests de caída/idempotencia). */
    protected function afterCloseTransition(int $requestId): void
    {
    }

    // ================================================================ internals

    /** @return array<int,string> motivos que bloquean el cierre */
    private function blockers(Request $req, \GlpiPlugin\Companyworkflow\Model\Instance $inst): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $requestId = (int) $req->getID();
        $lines = [];
        foreach ($DB->request(['SELECT' => ['ordered_qty', 'received_qty'], 'FROM' => RequestItem::getTable(), 'WHERE' => ['requests_id' => $requestId]]) as $l) {
            $lines[] = ['ordered_qty' => (int) $l['ordered_qty'], 'received_qty' => (int) $l['received_qty']];
        }
        $units = [];
        foreach ($DB->request(['SELECT' => ['receipt_unit_uuid', 'physical_state', 'is_inventoriable'], 'FROM' => ReceiptUnit::getTable(), 'WHERE' => ['requests_id' => $requestId]]) as $u) {
            $units[(string) $u['receipt_unit_uuid']] = $u;
        }
        $statuses = $this->outboxStatuses(array_keys(array_filter($units, static fn (array $u): bool => (int) $u['is_inventoriable'] === 1)));
        $facts = [];
        foreach ($units as $uuid => $u) {
            $facts[] = ['physical_state' => (string) $u['physical_state'], 'is_inventoriable' => (int) $u['is_inventoriable'] === 1, 'outbox_status' => $statuses[$uuid] ?? null];
        }
        $clean = false;
        try {
            $clean = $this->orch->integrityStatus($requestId)['clean'];
        } catch (\Throwable) {
            $clean = false; // fail-closed
        }
        return DeliveryRules::closeBlockers(
            $lines,
            $facts,
            $this->wf->isOpen($inst) ? $this->wf->stateCode($inst) : '',
            $this->wf->definitionHasAction($inst, PurchasingWorkflow::A_CLOSE),
            $clean
        );
    }

    private function recordClosed(Request $req, int $lockBefore, string $comment): void
    {
        $this->audit->recordOnce((int) $req->getID(), PurchasingEvent::EV_REQUEST_CLOSED, (int) $req->fields['entities_id'], [
            'workflow_lock_version' => $lockBefore, 'comment' => $comment,
        ], (string) ($req->fields['correlation_id'] ?? ''), 'request-close:' . (int) $req->getID());
    }

    /**
     * Estado del handoff (outbox PROPIO de Compras) por uuid. Sólo `status`: jamás `last_error`, tokens ni payload.
     *
     * @param array<int,string> $uuids
     * @return array<string,string>
     */
    private function outboxStatuses(array $uuids): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($uuids === []) {
            return [];
        }
        $out = [];
        foreach ($DB->request(['SELECT' => ['receipt_unit_uuid', 'status'], 'FROM' => OutboxEntry::getTable(), 'WHERE' => ['receipt_unit_uuid' => array_values($uuids)]]) as $r) {
            $out[(string) $r['receipt_unit_uuid']] = (string) $r['status'];
        }
        return $out;
    }

    /**
     * Destinatario válido y APLICABLE: usuario activo, no borrado, con perfil en la entidad de la solicitud (o en
     * un ancestro recursivo) según la relación NATIVA `Profile_User`.
     */
    private function assertRecipient(int $userId, int $entity): void
    {
        $u = new \User();
        if ($userId <= 0 || !$u->getFromDB($userId) || (int) ($u->fields['is_active'] ?? 0) !== 1 || (int) ($u->fields['is_deleted'] ?? 0) === 1) {
            throw new DeliveryException(DeliveryException::RECIPIENT, 'destinatario inexistente o inactivo (fail-closed)');
        }
        $entities = array_map('intval', (array) \Profile_User::getUserEntities($userId, true));
        if (!in_array($entity, $entities, true)) {
            throw new DeliveryException(DeliveryException::RECIPIENT, 'el destinatario no pertenece a la entidad de la solicitud (fail-closed)');
        }
    }

    /**
     * Si la clave ya tiene lote: misma solicitud + misma entrada ⇒ lo devuelve (re-ejecutando la saga); si no ⇒
     * conflicto. Sin lote ⇒ null.
     *
     * @return array{status:string, batch_id:int, units:array<int,string>, sync:array<string,mixed>}|null
     */
    private function replay(string $key, int $requestId, string $inputSha, bool $runSync = true): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $batch = new DeliveryBatch();
        if (!$batch->getFromDBByCrit(['idempotency_key' => $key])) {
            return null;
        }
        if ((int) $batch->fields['requests_id'] !== $requestId || !hash_equals((string) $batch->fields['input_sha256'], $inputSha)) {
            throw new DeliveryException(DeliveryException::IDEMPOTENCY_CONFLICT, 'idempotency_key ya usada para OTRA entrega (conflicto; fail-closed)');
        }
        $uuids = [];
        foreach ($DB->request(['SELECT' => ['receipt_unit_uuid'], 'FROM' => ReceiptUnit::getTable(),
                               'WHERE' => ['delivery_batches_id' => (int) $batch->getID()], 'ORDER' => 'receipt_unit_uuid ASC']) as $row) {
            $uuids[] = (string) $row['receipt_unit_uuid'];
        }
        return ['status' => 'replayed', 'batch_id' => (int) $batch->getID(), 'units' => $uuids,
                'sync' => $runSync ? $this->syncBestEffort($requestId) : []];
    }

    /** @return array<string,mixed> */
    private function syncBestEffort(int $requestId): array
    {
        try {
            return $this->sync->sync($requestId);
        } catch (\Throwable $e) {
            return ['status' => ReceivingSync::ST_PENDING, 'error' => $e->getMessage()];
        }
    }

    private function loadRequest(int $requestId): Request
    {
        $req = new Request();
        if ($requestId <= 0 || !$req->getFromDB($requestId)) {
            throw new DeliveryException(DeliveryException::STATE, 'solicitud inexistente');
        }
        return $req;
    }

    private function assertEntity(Request $req): void
    {
        if (!Session::haveAccessToEntity((int) $req->fields['entities_id'])) {
            throw new DeliveryException(DeliveryException::ENTITY, 'sin acceso a la entidad de la solicitud');
        }
    }

    private function now(): string
    {
        return (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
    }

    private function safeRollback(\DBmysql $DB): void
    {
        try {
            if (!method_exists($DB, 'inTransaction') || $DB->inTransaction()) {
                $DB->rollBack();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }
}
