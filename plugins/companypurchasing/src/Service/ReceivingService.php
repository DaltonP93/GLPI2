<?php

/**
 * RECEPCIÓN física de Compras (P2D-3; gate §5/§6): inicio de compra + recepción parcial atómica.
 *
 * `startPurchase()` (Compras, `MANAGE_PURCHASING`): exige solicitud ÍNTEGRAMENTE aprobada (motor en APPROVED,
 * sin marcas ni deriva) y una definición que conozca la fase de compra; en UNA transacción local CONGELA
 * `ordered_qty` (= cantidad aprobada), el precio final y el costo atribuible de cada línea
 * (`CostAllocator` con la política de costo PINNEADA), fija el proveedor/cotización de la compra y marca
 * `purchase_started_at`. Desde ahí no se admite cambiar cantidades, cotizaciones ni precios (fail-closed).
 * Después, la saga `ReceivingSync` mueve el motor APPROVED → IN_PURCHASE.
 *
 * `receive()` (`RIGHT_RECEIVE` + entidad): con `idempotency_key` OBLIGATORIA, en UNA transacción:
 *   BEGIN → lote existente por clave (lectura con lock) → `SELECT … FOR UPDATE` de las líneas (orden de id)
 *   → validar ordenado/recibido/seriales → INSERT lote → por unidad: UUID v4 (CSPRNG), costo exacto, INSERT
 *   unidad, y si la línea es inventariable INSERT outbox (payload inmutable + hash) → `received_qty` +=
 *   (condicionado) → `receiving_seq` += 1 → auditoría → COMMIT.
 * Cualquier fallo ⇒ ROLLBACK completo (nunca una unidad sin su handoff). Misma clave + misma entrada ⇒ el
 * MISMO lote (nunca otro); misma clave con otra entrada ⇒ conflicto. Dos recepciones concurrentes sobre la
 * misma línea se serializan por el `FOR UPDATE`: la segunda revalida contra el `received_qty` ya confirmado y
 * falla de forma controlada si excede lo ordenado. La saga del motor corre DESPUÉS del COMMIT (nunca
 * `WorkflowApi::transition()` dentro de la transacción de recepción).
 *
 * NO escribe a Snipe-IT, NO crea activos GLPI ni Infocom, NO invoca companyqr (eso es SI-4 / P2D-4).
 *
 * No es `final`: los tests deterministas inyectan fallos en `beforeOutboxInsert()` y `afterReceiptCommit()`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\OutboxEntry;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\ReceiptBatch;
use GlpiPlugin\Companypurchasing\Model\ReceiptUnit;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;

class ReceivingService
{
    /** Formato de la clave de idempotencia de la operación (mismo contrato que `invalidateApprovals`). */
    public const IDEMPOTENCY_PATTERN = '/^[A-Za-z0-9._:-]{8,190}$/';

    /** Máximo representable en `items.ordered_qty`/`received_qty` (INT UNSIGNED): nunca se trunca en silencio. */
    public const MAX_ORDERED_QTY = 4294967295;

    public const MAX_SERIAL_LENGTH = 190;
    public const MAX_NOTES_LENGTH  = 4000;

    protected ApprovalOrchestrator $orch;
    protected WorkflowGateway $wf;
    protected Audit $audit;
    protected AdvisoryLock $lock;
    protected ReceivingSync $sync;
    protected CostPolicyStore $costPolicies;

    public function __construct(
        ?ApprovalOrchestrator $orch = null,
        ?WorkflowGateway $wf = null,
        ?Audit $audit = null,
        ?AdvisoryLock $lock = null,
        ?ReceivingSync $sync = null
    ) {
        $this->wf           = $wf ?? new WorkflowGateway();
        $this->audit        = $audit ?? new Audit();
        $this->lock         = $lock ?? new AdvisoryLock();
        $this->orch         = $orch ?? new ApprovalOrchestrator($this->wf, null, null, $this->audit, $this->lock);
        $this->sync         = $sync ?? new ReceivingSync($this->wf, $this->audit, $this->lock);
        $this->costPolicies = new CostPolicyStore();
    }

    // ================================================================ inicio de compra

    /**
     * Inicia la compra (idempotente). Devuelve el estado de la saga del motor.
     *
     * @return array{status:string, started_now:bool, sync:array<string,mixed>}
     */
    public function startPurchase(int $requestId, string $comment = ''): array
    {
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_MANAGE_PURCHASING)) {
            throw new \RuntimeException('permiso denegado (MANAGE_PURCHASING)');
        }
        $startedNow = (bool) $this->lock->withRequestLock($requestId, function () use ($requestId, $comment): bool {
            /** @var \DBmysql $DB */
            global $DB;
            $req = $this->loadRequest($requestId);
            $this->assertEntity($req);
            if (!empty($req->fields['purchase_started_at'])) {
                return false; // ya iniciada: la saga converge abajo (reintento idempotente)
            }
            $inst = $this->wf->loadInstance((int) ($req->fields['workflow_instances_id'] ?? 0));
            if ($inst === null || !$this->wf->isOpen($inst)) {
                throw new \RuntimeException('la solicitud no tiene una instancia de workflow abierta (fail-closed)');
            }
            if ($this->wf->stateCode($inst) !== PurchasingWorkflow::S_APPROVED) {
                throw new \RuntimeException('sólo se inicia la compra de una solicitud APROBADA (fail-closed)');
            }
            if (!in_array(PurchasingWorkflow::A_START_PURCHASE, $this->wf->availableActions($inst), true)) {
                throw new \RuntimeException('la instancia usa una versión anterior de la definición sin fase de compra: se reporta, no se migra (fail-closed)');
            }
            $integrity = $this->orch->integrityStatus($requestId);
            if (!$integrity['fully_approved']) {
                throw new \RuntimeException('integridad de aprobación no limpia: reparar antes de comprar (fail-closed)');
            }
            $terms = $this->orch->quotes()->commercialTerms($req);
            if ($terms === null) {
                throw new \RuntimeException('sin cotización seleccionada (fail-closed)');
            }
            $policyId = $this->costPolicies->pinCurrent();
            $policy = $this->costPolicies->load($policyId);
            $currency = (string) $req->fields['currency_code'];
            $overrides = PluginConfig::currencyScaleOverrides();
            $math = $terms['math'];
            $alloc = CostAllocator::allocate($math['lines'], (string) $math['discounts'], (string) $math['taxes'], (string) $math['freight'], $currency, $policy, $overrides);
            foreach ($alloc as $a) {
                if ($a['quantity'] > self::MAX_ORDERED_QTY) {
                    throw new \RuntimeException('cantidad de línea fuera de rango para la recepción (fail-closed)');
                }
            }
            $now = $this->now();

            $DB->beginTransaction();
            try {
                $lines = [];
                foreach ($alloc as $a) {
                    $DB->update(RequestItem::getTable(), [
                        'ordered_qty'         => $a['quantity'],
                        'received_qty'        => 0,
                        'purchase_unit_price' => $a['final_unit_price'],
                        'line_cost_total'     => $a['line_cost'],
                        'date_mod'            => $now,
                    ], ['id' => $a['line_id'], 'requests_id' => $requestId, 'ordered_qty' => 0, 'received_qty' => 0]);
                    if ($DB->affectedRows() !== 1) {
                        throw new \RuntimeException('no se pudo congelar la línea ' . $a['line_id'] . ' (fail-closed)');
                    }
                    $lines[] = ['items_id' => $a['line_id'], 'ordered_qty' => $a['quantity'], 'final_unit_price' => $a['final_unit_price'],
                                'line_cost' => $a['line_cost'], 'discounts' => $a['discounts'], 'taxes' => $a['taxes'], 'freight' => $a['freight']];
                }
                $DB->update(Request::getTable(), [
                    'purchase_started_at'   => $now,
                    'purchase_quotes_id'    => (int) $terms['quote_id'],
                    'purchase_suppliers_id' => (int) $terms['suppliers_id'],
                    'cost_policies_id'      => $policyId,
                    'receiving_seq'         => new \Glpi\DBAL\QueryExpression('`receiving_seq` + 1'),
                    'date_mod'              => $now,
                ], ['id' => $requestId, 'purchase_started_at' => null]);
                if ($DB->affectedRows() !== 1) {
                    throw new \RuntimeException('la compra ya fue iniciada por otro proceso (reintente)');
                }
                $this->audit->record($requestId, PurchasingEvent::EV_PURCHASE_STARTED, (int) $req->fields['entities_id'], [
                    'quote_id' => (int) $terms['quote_id'], 'suppliers_id' => (int) $terms['suppliers_id'],
                    'cost_policies_id' => $policyId, 'cost_policy' => $policy->toArray(), 'currency' => $currency,
                    'lines' => $lines, 'comment' => $comment,
                ], (string) $req->fields['correlation_id'], 'purchase-start:' . $requestId);
                $DB->commit();
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
            return true;
        });
        return ['status' => 'started', 'started_now' => $startedNow, 'sync' => $this->syncBestEffort($requestId)];
    }

    // ================================================================ recepción

    /**
     * Registra una recepción física (lote) de forma atómica e idempotente.
     *
     * @param array<int,array{items_id:int, quantity:int|string, serials?:array<int,string>}> $lines
     * @param array{notes?:string, documents_id?:int} $meta
     * @return array{status:string, batch_id:int, units:array<int,string>, outbox:int, sync:array<string,mixed>}
     */
    public function receive(int $requestId, string $idempotencyKey, array $lines, array $meta = []): array
    {
        if (preg_match(self::IDEMPOTENCY_PATTERN, $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException('idempotency_key obligatoria (8–190, [A-Za-z0-9._:-]) (fail-closed)');
        }
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_RECEIVE)) {
            throw new \RuntimeException('permiso denegado (RECEIVE)');
        }
        $req = $this->loadRequest($requestId);
        $this->assertEntity($req);
        $entity = (int) $req->fields['entities_id'];
        $input = $this->normalizeInput($lines, $meta, $entity);
        $inputSha = hash('sha256', json_encode(['request_id' => $requestId] + $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        // Reintento de la MISMA operación ⇒ el MISMO lote (antes de cualquier otra validación de estado).
        $replay = $this->replay($idempotencyKey, $requestId, $inputSha);
        if ($replay !== null) {
            return $replay;
        }

        if (empty($req->fields['purchase_started_at']) || (int) ($req->fields['cost_policies_id'] ?? 0) <= 0) {
            throw new \RuntimeException('la compra no fue iniciada: no se recibe (fail-closed)');
        }
        $integrity = $this->orch->integrityStatus($requestId);
        if (!$integrity['clean']) {
            throw new \RuntimeException('integridad de aprobación no limpia: no se recibe (fail-closed)');
        }
        // Si una saga previa quedó pendiente (p. ej. APPROVED con la compra ya iniciada), se intenta converger.
        $this->syncBestEffort($requestId);
        $inst = $this->wf->loadInstance((int) ($req->fields['workflow_instances_id'] ?? 0));
        $state = $inst !== null && $this->wf->isOpen($inst) ? $this->wf->stateCode($inst) : '';
        if (!in_array($state, PurchasingWorkflow::RECEIVING_STATES, true)) {
            throw new \RuntimeException("el estado del workflow ({$state}) no admite recepción (fail-closed)");
        }
        $policy = $this->costPolicies->forRequest($req); // pinneada + hash verificado (fail-closed)

        /** @var \DBmysql $DB */
        global $DB;
        $currency  = (string) $req->fields['currency_code'];
        $overrides = PluginConfig::currencyScaleOverrides();
        $now       = $this->now();
        $receivedAtIso = date('c', (int) strtotime($now));
        $actor     = (int) (Session::getLoginUserID() ?: 0);
        $corr      = (string) ($req->fields['correlation_id'] ?? '');
        $uuids = [];
        $outboxCount = 0;

        $DB->beginTransaction();
        try {
            // (a) ¿La clave ya fue confirmada por otro proceso mientras tanto? (lectura CON lock, dato vigente)
            $res = $DB->doQuery('SELECT `id` FROM `' . ReceiptBatch::getTable() . "` WHERE `idempotency_key` = '" . $DB->escape($idempotencyKey) . "' FOR UPDATE");
            if ($res !== false && $DB->numrows($res) > 0) {
                $this->safeRollback($DB);
                return $this->replay($idempotencyKey, $requestId, $inputSha) ?? throw new \RuntimeException('lote inconsistente (fail-closed)');
            }
            // (b) LOCK de las líneas (orden de id ⇒ sin deadlocks entre recepciones concurrentes).
            $ids = array_map('intval', array_keys($input['lines']));
            sort($ids);
            $locked = [];
            $res = $DB->doQuery('SELECT * FROM `' . RequestItem::getTable() . '` WHERE `requests_id` = ' . $requestId
                . ' AND `id` IN (' . implode(',', $ids) . ') ORDER BY `id` FOR UPDATE');
            while ($res !== false && ($row = $DB->fetchAssoc($res))) {
                $locked[(int) $row['id']] = $row;
            }
            if (count($locked) !== count($ids)) {
                throw new \RuntimeException('línea inexistente o ajena a la solicitud (fail-closed)');
            }
            // (c) Validación contra los contadores VIGENTES (bajo lock).
            foreach ($ids as $itemsId) {
                $row = $locked[$itemsId];
                $qty = $input['lines'][$itemsId]['quantity'];
                $ordered = (int) $row['ordered_qty'];
                $received = (int) $row['received_qty'];
                if ($ordered < 1) {
                    throw new \RuntimeException("línea {$itemsId} sin cantidad ordenada congelada (fail-closed)");
                }
                if ($received + $qty > $ordered) {
                    throw new \RuntimeException("sobre-recepción rechazada en la línea {$itemsId}: ordenado {$ordered}, recibido {$received}, solicitado {$qty}");
                }
                $serials = $input['lines'][$itemsId]['serials'];
                if ($serials !== [] && countElementsInTable(ReceiptUnit::getTable(), ['items_id' => $itemsId, 'serial' => $serials]) > 0) {
                    throw new \RuntimeException("serial ya recibido en la línea {$itemsId} (fail-closed)");
                }
            }
            // (d) Lote.
            $DB->insert(ReceiptBatch::getTable(), [
                'requests_id'      => $requestId,
                'entities_id'      => $entity,
                'idempotency_key'  => $idempotencyKey,
                'input_sha256'     => $inputSha,
                'actor_users_id'   => $actor,
                'received_at'      => $now,
                'notes'            => $input['notes'],
                'documents_id'     => $input['documents_id'],
                'cost_policies_id' => $policy->id(),
                'units_count'      => array_sum(array_column($input['lines'], 'quantity')),
                'correlation_id'   => $corr,
                'date_creation'    => $now,
            ]);
            $batchId = (int) $DB->insertId();
            if ($batchId <= 0) {
                throw new \RuntimeException('no se pudo registrar el lote de recepción');
            }
            // (e) Unidades (+ outbox si inventariable) con costo exacto por ordinal.
            $detailLines = [];
            foreach ($ids as $itemsId) {
                $row = $locked[$itemsId];
                $qty = $input['lines'][$itemsId]['quantity'];
                $serials = $input['lines'][$itemsId]['serials'];
                $ordered = (int) $row['ordered_qty'];
                $received = (int) $row['received_qty'];
                $lineCost = Money::ofStored((string) $row['line_cost_total'], $currency, $overrides)->amount();
                $inventoriable = (int) $row['is_inventoriable'] === 1;
                for ($i = 1; $i <= $qty; $i++) {
                    $ordinal = $received + $i;
                    $uuid = self::uuidV4();
                    $serial = $serials[$i - 1] ?? null;
                    $unitCost = CostAllocator::unitCost($lineCost, $ordered, $ordinal, $currency, $overrides);
                    $DB->insert(ReceiptUnit::getTable(), [
                        'receipt_batches_id' => $batchId,
                        'requests_id'        => $requestId,
                        'items_id'           => $itemsId,
                        'entities_id'        => $entity,
                        'receipt_unit_uuid'  => $uuid,
                        'serial'             => $serial,
                        'currency_code'      => $currency,
                        'unit_cost'          => $unitCost,
                        'is_inventoriable'   => $inventoriable ? 1 : 0,
                        'physical_state'     => ReceiptUnit::PHYSICAL_RECEIVED,
                        'unit_index'         => $ordinal,
                        'correlation_key'    => 'purchase:' . $requestId . ':item:' . (int) $row['line_no'] . ':unit:' . $ordinal,
                        'date_creation'      => $now,
                        'date_mod'           => $now,
                    ]);
                    $unitId = (int) $DB->insertId();
                    if ($unitId <= 0) {
                        throw new \RuntimeException('no se pudo registrar la unidad recibida');
                    }
                    $uuids[] = $uuid;
                    if ($inventoriable) {
                        $payload = HandoffPayload::build([
                            'receipt_unit_uuid' => $uuid,
                            'request_id'        => $requestId,
                            'request_number'    => (string) ($req->fields['number'] ?? ''),
                            'item_id'           => $itemsId,
                            'entity_id'         => $entity,
                            'serial'            => $serial,
                            'description'       => (string) $row['description'],
                            'category'          => (string) $row['category'],
                            'supplier_id'       => (int) ($req->fields['purchase_suppliers_id'] ?? 0),
                            'currency'          => $currency,
                            'unit_cost'         => $unitCost,
                            'received_at'       => $receivedAtIso,
                            'correlation_id'    => $corr,
                        ]);
                        $outboxCount++;
                        $this->beforeOutboxInsert($uuid, $outboxCount);
                        $DB->insert(OutboxEntry::getTable(), [
                            'receipt_unit_uuid' => $uuid,
                            'receipt_units_id'  => $unitId,
                            'requests_id'       => $requestId,
                            'entities_id'       => $entity,
                            'payload_version'   => HandoffPayload::SCHEMA_VERSION,
                            'payload_json'      => HandoffPayload::canonical($payload),
                            'payload_sha256'    => HandoffPayload::hash($payload),
                            'status'            => OutboxEntry::STATUS_PENDING,
                            'attempts'          => 0,
                            'date_creation'     => $now,
                            'date_mod'          => $now,
                        ]);
                        if ((int) $DB->insertId() <= 0) {
                            throw new \RuntimeException('no se pudo registrar el handoff de inventario');
                        }
                    }
                }
                // (f) Contador condicionado al valor leído bajo lock (defensa en profundidad).
                $DB->update(RequestItem::getTable(), ['received_qty' => $received + $qty, 'date_mod' => $now],
                    ['id' => $itemsId, 'received_qty' => $received]);
                if ($DB->affectedRows() !== 1) {
                    throw new \RuntimeException('conflicto de concurrencia en el contador de recepción (reintente)');
                }
                $detailLines[] = ['items_id' => $itemsId, 'quantity' => $qty, 'received_before' => $received, 'received_after' => $received + $qty, 'ordered' => $ordered];
            }
            // (g) Marcador durable de sincronización con el motor + auditoría, en la MISMA transacción.
            $DB->update(Request::getTable(), ['receiving_seq' => new \Glpi\DBAL\QueryExpression('`receiving_seq` + 1')], ['id' => $requestId]);
            if ($DB->affectedRows() !== 1) {
                throw new \RuntimeException('no se pudo registrar el marcador de sincronización');
            }
            $this->audit->record($requestId, PurchasingEvent::EV_RECEIPT_RECORDED, $entity, [
                'batch_id' => $batchId, 'idempotency_key' => $idempotencyKey, 'lines' => $detailLines,
                'units' => count($uuids), 'outbox' => $outboxCount, 'cost_policies_id' => $policy->id(),
            ], $corr, 'receipt:' . $idempotencyKey);
            $DB->commit();
        } catch (\Throwable $e) {
            $this->safeRollback($DB);
            // Carrera con la MISMA clave: el otro proceso confirmó primero ⇒ mismo lote.
            $replay = $this->replay($idempotencyKey, $requestId, $inputSha, false);
            if ($replay !== null) {
                return $replay;
            }
            throw $e;
        }

        $this->afterReceiptCommit($batchId);
        return ['status' => 'recorded', 'batch_id' => $batchId, 'units' => $uuids, 'outbox' => $outboxCount, 'sync' => $this->syncBestEffort($requestId)];
    }

    /**
     * Unidades recibidas de una solicitud (lectura con ACL: `RIGHT_RECEIVE` o vista de la solicitud, y entidad).
     *
     * @return array<int,array<string,mixed>>
     */
    public function units(int $requestId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $req = $this->loadRequest($requestId);
        $this->assertEntity($req);
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_RECEIVE) && !(new RequestManager())->canView($req)) {
            throw new \RuntimeException('sin permiso para ver la recepción');
        }
        $out = [];
        foreach ($DB->request(['FROM' => ReceiptUnit::getTable(), 'WHERE' => ['requests_id' => $requestId], 'ORDER' => 'id ASC']) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    /** Punto de inyección de fallo para tests (dentro de la transacción, antes del INSERT del outbox n-ésimo). */
    protected function beforeOutboxInsert(string $uuid, int $n): void
    {
    }

    /** Punto de inyección de caída para tests (recepción CONFIRMADA, antes de la saga del motor). */
    protected function afterReceiptCommit(int $batchId): void
    {
    }

    /** UUID v4 aleatorio (RFC 4122) generado con CSPRNG (`random_bytes`). */
    public static function uuidV4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
    }

    // ================================================================ internals

    /**
     * Valida y normaliza la entrada (fail-closed): líneas únicas, cantidad entera ≥ 1 acotada, seriales
     * opcionales (tantos como unidades; únicos; imprimibles), notas y Document nativo opcional de la entidad.
     *
     * @param array<int,mixed> $lines
     * @param array<string,mixed> $meta
     * @return array{lines:array<int,array{quantity:int, serials:array<int,string>}>, notes:string, documents_id:int}
     */
    private function normalizeInput(array $lines, array $meta, int $entity): array
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('la recepción no tiene líneas');
        }
        $max = max(1, (int) PluginConfig::get('receipt_max_units_per_batch', '1000'));
        $out = [];
        $total = 0;
        foreach ($lines as $l) {
            if (!is_array($l)) {
                throw new \InvalidArgumentException('línea de recepción inválida');
            }
            $itemsId = (int) ($l['items_id'] ?? 0);
            if ($itemsId <= 0 || isset($out[$itemsId])) {
                throw new \InvalidArgumentException('línea de recepción inválida o repetida');
            }
            $qty = QuantityPolicy::validate($l['quantity'] ?? null, true);
            $total += $qty;
            if ($total > $max) {
                throw new \InvalidArgumentException("un lote admite a lo sumo {$max} unidades (fail-closed)");
            }
            $serials = [];
            if (array_key_exists('serials', $l) && $l['serials'] !== null && $l['serials'] !== []) {
                if (!is_array($l['serials']) || count($l['serials']) !== $qty) {
                    throw new \InvalidArgumentException('los seriales deben ser tantos como las unidades recibidas');
                }
                foreach ($l['serials'] as $s) {
                    $s = trim((string) $s);
                    if ($s === '' || mb_strlen($s) > self::MAX_SERIAL_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $s) === 1) {
                        throw new \InvalidArgumentException('serial inválido');
                    }
                    $serials[] = $s;
                }
                if (count(array_unique($serials)) !== count($serials)) {
                    throw new \InvalidArgumentException('seriales repetidos en el lote');
                }
            }
            $out[$itemsId] = ['quantity' => $qty, 'serials' => $serials];
        }
        ksort($out);
        $notes = trim((string) ($meta['notes'] ?? ''));
        if (mb_strlen($notes) > self::MAX_NOTES_LENGTH) {
            throw new \InvalidArgumentException('notas demasiado largas');
        }
        $doc = (int) ($meta['documents_id'] ?? 0);
        if ($doc < 0) {
            throw new \InvalidArgumentException('documento inválido');
        }
        if ($doc > 0) {
            // Remito/constancia = Document NATIVO aplicable a la entidad de la solicitud (nada cross-branch).
            (new ReferenceValidator())->assertReferenceForEntity(\Document::class, $doc, $entity);
        }
        return ['lines' => $out, 'notes' => $notes, 'documents_id' => $doc];
    }

    /**
     * Si la clave ya tiene lote: misma solicitud + misma entrada ⇒ lo devuelve (re-ejecutando la saga); si no
     * ⇒ conflicto. Sin lote ⇒ null.
     *
     * @return array{status:string, batch_id:int, units:array<int,string>, outbox:int, sync:array<string,mixed>}|null
     */
    private function replay(string $key, int $requestId, string $inputSha, bool $runSync = true): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $batch = new ReceiptBatch();
        if (!$batch->getFromDBByCrit(['idempotency_key' => $key])) {
            return null;
        }
        if ((int) $batch->fields['requests_id'] !== $requestId || !hash_equals((string) $batch->fields['input_sha256'], $inputSha)) {
            throw new \RuntimeException('idempotency_key ya usada para OTRA recepción (conflicto; fail-closed)');
        }
        $uuids = [];
        $outbox = 0;
        foreach ($DB->request(['SELECT' => ['receipt_unit_uuid', 'is_inventoriable'], 'FROM' => ReceiptUnit::getTable(),
                               'WHERE' => ['receipt_batches_id' => (int) $batch->getID()], 'ORDER' => 'id ASC']) as $row) {
            $uuids[] = (string) $row['receipt_unit_uuid'];
            $outbox += (int) $row['is_inventoriable'];
        }
        return ['status' => 'replayed', 'batch_id' => (int) $batch->getID(), 'units' => $uuids, 'outbox' => $outbox,
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
            throw new \RuntimeException('solicitud inexistente');
        }
        return $req;
    }

    private function assertEntity(Request $req): void
    {
        if (!Session::haveAccessToEntity((int) $req->fields['entities_id'])) {
            throw new \RuntimeException('sin acceso a la entidad de la solicitud');
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
