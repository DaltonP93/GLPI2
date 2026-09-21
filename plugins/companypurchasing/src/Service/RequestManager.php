<?php

/**
 * Orquestador del NÚCLEO de compras (P2D-1): CRUD controlado de borrador, numeración al abandonar
 * DRAFT, importes exactos y auditoría. Fail-closed en ACL y multi-entidad.
 *
 * CONCURRENCIA + ATOMICIDAD: TODAS las mutaciones de una solicitud (createDraft/updateDraft/addLine/
 * updateLine/removeLine/submitDraft) confirman su cambio de datos Y su evento de auditoría JUNTOS, en una
 * transacción local sobre tablas propias del plugin (si la auditoría o un write fallan → ROLLBACK, sin
 * estado parcial). Las mutaciones sobre una solicitud EXISTENTE se serializan además bajo un **lock común
 * por solicitud** (`request_<id>`): adquirir → recargar FRESCO → entidad+ACL → DRAFT si corresponde →
 * BEGIN {mutar → recomputar total → audit} COMMIT → liberar. Esto impide "edito un DRAFT que otro worker
 * ya envió", los totales stale por mutaciones simultáneas de líneas, y solicitudes/líneas huérfanas.
 *
 * NO integra `companyworkflow` (P2D-2): el `domain_state` es un snapshot/cache local; DRAFT es el
 * estado inicial y `submitDraft()` es el evento local "abandona DRAFT". La AUTORIDAD de estados será
 * `companyworkflow`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;

final class RequestManager
{
    private NumberingService $numbering;
    private Audit $audit;
    private AdvisoryLock $lock;

    public function __construct(?NumberingService $numbering = null, ?Audit $audit = null, ?AdvisoryLock $lock = null)
    {
        $this->numbering = $numbering ?? new NumberingService();
        $this->audit     = $audit ?? new Audit();
        $this->lock      = $lock ?? new AdvisoryLock();
    }

    // ---------------------------------------------------------------- CREATE

    /**
     * Crea una solicitud en estado DRAFT (sin número visible aún).
     *
     * @param array<string,mixed> $in
     * @return int id de la solicitud
     * @throws \RuntimeException|\InvalidArgumentException
     */
    public function createDraft(array $in): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $entity = (int) ($in['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        $this->assertRight(Request::RIGHT_CREATE_REQUEST);
        $this->assertEntity($entity);

        $currency = strtoupper((string) ($in['currency_code'] ?? PluginConfig::defaultCurrency()));
        if (!CurrencyPolicy::isWellFormed($currency)) {
            throw new \InvalidArgumentException('moneda inválida');
        }

        // IDENTIDAD DEL SOLICITANTE (C): por política, el solicitante es SIEMPRE el usuario autenticado.
        // Intentar declarar otro solicitante se rechaza (crear "en nombre de" requerirá un derecho propio
        // en una fase posterior; CREATE_REQUEST por sí solo NO lo concede).
        $me = (int) (Session::getLoginUserID() ?: 0);
        if (array_key_exists('users_id_requester', $in)
            && (int) $in['users_id_requester'] !== 0
            && (int) $in['users_id_requester'] !== $me
        ) {
            throw new \RuntimeException('no se permite crear en nombre de otro solicitante en P2D-1');
        }

        // Departamento: 0 = sin departamento; >0 el Group debe existir y ser APLICABLE a la entidad de
        // la solicitud (misma entidad o ancestro recursivo), no sólo "visible por la sesión".
        $deptId = (int) ($in['groups_id_department'] ?? 0);
        $this->assertReferenceForEntity(\Group::class, $deptId, $entity);

        // Referencias a maestros NATIVOS: 0 = sin referencia; >0 debe existir y ser aplicable a la entidad.
        $supplierId = (int) ($in['suppliers_id_suggested'] ?? 0);
        $budgetId   = (int) ($in['budgets_id'] ?? 0);
        $this->assertReferenceForEntity(\Supplier::class, $supplierId, $entity);
        $this->assertReferenceForEntity(\Budget::class, $budgetId, $entity);

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $corr = bin2hex(random_bytes(16));

        // ATÓMICO: la solicitud y su evento REQUEST_CREATED confirman JUNTOS (ambos en tablas propias).
        // Si la auditoría no persiste → ROLLBACK: no queda una solicitud huérfana que el caller creería
        // no haber creado.
        $DB->beginTransaction();
        try {
            $req = new Request();
            // `number`/`number_seq` se OMITEN: toman el DEFAULT NULL (varios borradores conviven sin chocar
            // con los UNIQUE; MySQL admite múltiples NULL). Se asignan en submitDraft().
            $id = (int) $req->add([
                'entities_id'          => $entity,
                // `is_recursive` NO queda bajo control libre del solicitante (C): baseline de dominio = 0.
                'is_recursive'         => 0,
                'users_id_requester'   => $me,
                'groups_id_department' => $deptId,
                'category'             => substr((string) ($in['category'] ?? ''), 0, 190),
                'destination'          => substr((string) ($in['destination'] ?? ''), 0, 255),
                'reason'               => (string) ($in['reason'] ?? ''),
                'observations'         => (string) ($in['observations'] ?? ''),
                'suppliers_id_suggested' => $supplierId,
                'budgets_id'           => $budgetId,
                'currency_code'        => $currency,
                'amount_estimated'     => Money::zero($currency, PluginConfig::currencyScaleOverrides())->amount(),
                'domain_state'         => Request::STATE_DRAFT,
                'scopes_version'       => 0,
                'workflow_instances_id' => 0,
                'correlation_id'       => $corr,
                'users_id_creator'     => $me,
                'lock_version'         => 0,
                'date_creation'        => $now,
                'date_mod'             => $now,
            ]);
            if ($id <= 0) {
                throw new \RuntimeException('no se pudo crear la solicitud');
            }
            $this->audit->record($id, PurchasingEvent::EV_REQUEST_CREATED, $entity, ['currency' => $currency], $corr);
            $DB->commit();
        } catch (\Throwable $e) {
            $this->safeRollback($DB);
            throw $e;
        }
        return $id;
    }

    // ---------------------------------------------------------------- UPDATE header

    /** @param array<string,mixed> $in */
    public function updateDraft(int $id, array $in): void
    {
        $this->withRequestLock($id, function () use ($id, $in): void {
            /** @var \DBmysql $DB */
            global $DB;

            $req = $this->loadForEdit($id); // recarga FRESCA bajo lock + checks (isDraft)
            $entity = (int) $req->fields['entities_id'];

            // IDENTIDAD INMUTABLE (P2D-1): un intento de cambiar el solicitante se RECHAZA explícitamente
            // (no se ignora en silencio). No existe "editar en nombre de otro".
            if (array_key_exists('users_id_requester', $in)
                && (int) $in['users_id_requester'] !== (int) $req->fields['users_id_requester']
            ) {
                throw new \RuntimeException('users_id_requester es inmutable en P2D-1');
            }
            // `is_recursive` no está bajo control del solicitante: un intento != 0 se RECHAZA (baseline 0).
            if (array_key_exists('is_recursive', $in) && (int) $in['is_recursive'] !== 0) {
                throw new \RuntimeException('is_recursive no está bajo control del solicitante (baseline 0)');
            }

            $fields = [];
            foreach ([
                'groups_id_department' => 'int',
                'category'             => 'str190',
                'destination'          => 'str255',
                'reason'               => 'text',
                'observations'         => 'text',
                'suppliers_id_suggested' => 'int',
                'budgets_id'           => 'int',
            ] as $key => $type) {
                if (!array_key_exists($key, $in)) {
                    continue;
                }
                $fields[$key] = match ($type) {
                    'int'    => (int) $in[$key],
                    'str190' => substr((string) $in[$key], 0, 190),
                    'str255' => substr((string) $in[$key], 0, 255),
                    default  => (string) $in[$key],
                };
            }
            if ($fields === []) {
                return;
            }
            // Validar referencias nativas si cambian (0 = sin referencia; >0 debe existir y ser APLICABLE a
            // la entidad de la solicitud, no sólo visible por la sesión).
            if (array_key_exists('groups_id_department', $fields)) {
                $this->assertReferenceForEntity(\Group::class, (int) $fields['groups_id_department'], $entity);
            }
            if (array_key_exists('suppliers_id_suggested', $fields)) {
                $this->assertReferenceForEntity(\Supplier::class, (int) $fields['suppliers_id_suggested'], $entity);
            }
            if (array_key_exists('budgets_id', $fields)) {
                $this->assertReferenceForEntity(\Budget::class, (int) $fields['budgets_id'], $entity);
            }
            // ATÓMICO: la mutación de cabecera y su evento REQUEST_UPDATED confirman JUNTOS.
            $DB->beginTransaction();
            try {
                $this->applyUpdate($req, $fields);
                $this->audit->record($id, PurchasingEvent::EV_REQUEST_UPDATED, $entity, ['fields' => array_keys($fields)], (string) $req->fields['correlation_id']);
                $DB->commit();
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
        });
    }

    // ---------------------------------------------------------------- LINES

    /**
     * @param array<string,mixed> $line
     * @return int id de la línea
     */
    public function addLine(int $reqId, array $line): int
    {
        return (int) $this->withRequestLock($reqId, function () use ($reqId, $line): int {
            /** @var \DBmysql $DB */
            global $DB;

            $req = $this->loadForEdit($reqId);
            $currency = (string) $req->fields['currency_code'];
            $overrides = PluginConfig::currencyScaleOverrides();

            // Validaciones puras ANTES de abrir transacción (pueden lanzar sin dejar nada a medias).
            $isInv = (int) (($line['is_inventoriable'] ?? 0) ? 1 : 0);
            $qty   = $this->validateQuantity($line['quantity'] ?? null, $isInv === 1);
            $unitPrice = Money::of((string) ($line['estimated_unit_price'] ?? '0'), $currency, $overrides);
            $lineTotal = $unitPrice->timesInt($qty);

            $lineNo = isset($line['line_no']) ? (int) $line['line_no'] : $this->nextLineNo($reqId);
            $this->assertLineNoFree($reqId, $lineNo, 0);

            $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

            // ATÓMICO: alta de línea + recálculo del total + evento LINE_ADDED confirman JUNTOS.
            $DB->beginTransaction();
            try {
                $item = new RequestItem();
                $lineId = (int) $item->add([
                    'requests_id'          => $reqId,
                    'line_no'              => $lineNo,
                    'description'          => substr((string) ($line['description'] ?? ''), 0, 255),
                    'category'             => substr((string) ($line['category'] ?? ''), 0, 190),
                    'quantity'             => $qty,
                    'unit'                 => substr((string) ($line['unit'] ?? ''), 0, 30),
                    'is_inventoriable'     => $isInv,
                    'currency_code'        => $currency,
                    'estimated_unit_price' => $unitPrice->amount(),
                    'estimated_line_total' => $lineTotal->amount(),
                    'notes'                => (string) ($line['notes'] ?? ''),
                    'date_creation'        => $now,
                    'date_mod'             => $now,
                ]);
                if ($lineId <= 0) {
                    throw new \RuntimeException('no se pudo crear la línea');
                }
                $this->recomputeEstimated($req);
                $this->audit->record($reqId, PurchasingEvent::EV_LINE_ADDED, (int) $req->fields['entities_id'], ['line_id' => $lineId, 'line_no' => $lineNo], (string) $req->fields['correlation_id']);
                $DB->commit();
                return $lineId;
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
        });
    }

    /** @param array<string,mixed> $line */
    public function updateLine(int $lineId, array $line): void
    {
        $reqId = $this->lineRequestId($lineId);
        $this->withRequestLock($reqId, function () use ($reqId, $lineId, $line): void {
            /** @var \DBmysql $DB */
            global $DB;

            $req = $this->loadForEdit($reqId);
            // Recargar la línea FRESCA bajo lock y confirmar que pertenece a esta solicitud.
            $item = new RequestItem();
            if (!$item->getFromDB($lineId) || (int) $item->fields['requests_id'] !== $reqId) {
                throw new \RuntimeException('línea inexistente');
            }
            $currency = (string) $req->fields['currency_code'];
            $overrides = PluginConfig::currencyScaleOverrides();

            // Cálculo/validación pura ANTES de abrir transacción (puede lanzar sin dejar nada a medias).
            $isInv = array_key_exists('is_inventoriable', $line)
                ? (int) (($line['is_inventoriable'] ? 1 : 0))
                : (int) $item->fields['is_inventoriable'];
            $qty = array_key_exists('quantity', $line)
                ? $this->validateQuantity($line['quantity'], $isInv === 1)
                : (int) $item->fields['quantity'];
            // Precio provisto → `of()` (escala de la moneda). NO provisto → valor ALMACENADO → `ofStored()`.
            $unitPrice = array_key_exists('estimated_unit_price', $line)
                ? Money::of((string) $line['estimated_unit_price'], $currency, $overrides)
                : Money::ofStored((string) $item->fields['estimated_unit_price'], $currency, $overrides);
            $lineTotal = $unitPrice->timesInt($qty);

            $fields = [
                'id'                   => $lineId,
                'is_inventoriable'     => $isInv,
                'quantity'             => $qty,
                'estimated_unit_price' => $unitPrice->amount(),
                'estimated_line_total' => $lineTotal->amount(),
                'date_mod'             => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ];
            foreach (['description' => 'str255', 'category' => 'str190', 'unit' => 'str30', 'notes' => 'text'] as $k => $t) {
                if (array_key_exists($k, $line)) {
                    $fields[$k] = match ($t) {
                        'str255' => substr((string) $line[$k], 0, 255),
                        'str190' => substr((string) $line[$k], 0, 190),
                        'str30'  => substr((string) $line[$k], 0, 30),
                        default  => (string) $line[$k],
                    };
                }
            }
            if (array_key_exists('line_no', $line)) {
                $newNo = (int) $line['line_no'];
                $this->assertLineNoFree($reqId, $newNo, $lineId);
                $fields['line_no'] = $newNo;
            }
            // ATÓMICO: actualización de línea + recálculo del total + evento LINE_UPDATED confirman JUNTOS.
            $DB->beginTransaction();
            try {
                if (!$item->update($fields)) {
                    throw new \RuntimeException('no se pudo actualizar la línea');
                }
                $this->recomputeEstimated($req);
                $this->audit->record($reqId, PurchasingEvent::EV_LINE_UPDATED, (int) $req->fields['entities_id'], ['line_id' => $lineId], (string) $req->fields['correlation_id']);
                $DB->commit();
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
        });
    }

    public function removeLine(int $lineId): void
    {
        $reqId = $this->lineRequestId($lineId);
        $this->withRequestLock($reqId, function () use ($reqId, $lineId): void {
            /** @var \DBmysql $DB */
            global $DB;

            $req = $this->loadForEdit($reqId);
            $item = new RequestItem();
            if (!$item->getFromDB($lineId) || (int) $item->fields['requests_id'] !== $reqId) {
                throw new \RuntimeException('línea inexistente');
            }
            // ATÓMICO: baja de línea + recálculo del total + evento LINE_REMOVED confirman JUNTOS.
            $DB->beginTransaction();
            try {
                // Ningún write fallido puede volverse éxito en silencio: se comprueba el resultado de delete().
                if (!$item->delete(['id' => $lineId], true)) {
                    throw new \RuntimeException('no se pudo eliminar la línea');
                }
                $this->recomputeEstimated($req);
                $this->audit->record($reqId, PurchasingEvent::EV_LINE_REMOVED, (int) $req->fields['entities_id'], ['line_id' => $lineId], (string) $req->fields['correlation_id']);
                $DB->commit();
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
        });
    }

    // ---------------------------------------------------------------- SUBMIT (leaves DRAFT)

    /**
     * Abandona DRAFT por primera vez: reserva el número (transacción independiente; el hueco se permite
     * si lo posterior falla) y luego, en UNA transacción local sobre tablas propias, pasa a PENDING e
     * inserta el evento `REQUEST_SUBMITTED` (idempotente por `idempotency_key = request-submit:<id>`).
     * Serializado por el lock común de la solicitud. Idempotente ante reenvío. Devuelve el número.
     */
    public function submitDraft(int $id): int
    {
        return (int) $this->withRequestLock($id, function () use ($id): int {
            /** @var \DBmysql $DB */
            global $DB;

            $req = new Request();
            if (!$req->getFromDB($id)) {
                throw new \RuntimeException('solicitud inexistente');
            }
            $entity = (int) $req->fields['entities_id'];
            $this->assertRight(Request::RIGHT_EDIT_DRAFT);
            $this->assertEntity($entity);
            if (!$this->canView($req)) {
                throw new \RuntimeException('sin permiso sobre la solicitud');
            }

            // IDEMPOTENCIA: ya no es borrador y tiene número → devolver el MISMO (sin nuevo número/evento).
            if (!$req->isDraft()) {
                $existing = (int) ($req->fields['number_seq'] ?? 0);
                if ($existing > 0) {
                    return $existing;
                }
                throw new \RuntimeException('la solicitud ya no es un borrador enviable (estado: ' . (string) $req->fields['domain_state'] . ')');
            }

            // FAIL-CLOSED de scopes ANTES de reservar número: si la versión vigente falta/corrupta, la
            // solicitud sigue en DRAFT y NO consume número.
            $scopesVersion = PluginConfig::currentScopesVersion();
            ScopeCatalog::assertVersionComplete($scopesVersion);

            $year   = (int) date('Y', strtotime((string) ($_SESSION['glpi_currenttime'] ?? 'now')) ?: time());
            // Reserva de número: transacción PROPIA e independiente (si lo de abajo falla, queda un hueco
            // permitido, jamás reciclado).
            $seq    = $this->numbering->assign($entity, NumberingService::SCOPE_REQUEST, $year);
            $number = NumberingService::formatNumber(NumberingService::SCOPE_REQUEST, $year, $seq);

            // FRONTERA ATÓMICA (D): DRAFT→PENDING + evento REQUEST_SUBMITTED confirman JUNTOS (ambos sobre
            // tablas propias del plugin). Si el evento no persiste, se hace ROLLBACK y la solicitud NO
            // queda PENDING (garantía: PENDING ⇔ existe exactamente un REQUEST_SUBMITTED durable).
            $DB->beginTransaction();
            try {
                $ok = $req->update([
                    'id'             => $id,
                    'number'         => $number,
                    'number_seq'     => $seq,
                    'number_scope'   => NumberingService::SCOPE_REQUEST,
                    'number_year'    => $year,
                    'domain_state'   => Request::STATE_PENDING,
                    'scopes_version' => $scopesVersion,
                    'lock_version'   => (int) $req->fields['lock_version'] + 1,
                    'date_mod'       => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ]);
                if (!$ok) {
                    throw new \RuntimeException('no se pudo actualizar la solicitud');
                }
                // Idempotencia durable del evento: UNIQUE(idempotency_key). Un retry/recovery jamás duplica.
                $this->audit->record(
                    $id,
                    PurchasingEvent::EV_REQUEST_SUBMITTED,
                    $entity,
                    ['number' => $number, 'seq' => $seq, 'scopes_version' => $scopesVersion],
                    (string) $req->fields['correlation_id'],
                    'request-submit:' . $id
                );
                $DB->commit();
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
            return $seq;
        });
    }

    // ---------------------------------------------------------------- READ / helpers

    /** Carga una solicitud aplicando ACL de VISTA (fail-closed). */
    public function getViewable(int $id): Request
    {
        $req = new Request();
        if (!$req->getFromDB($id)) {
            throw new \RuntimeException('solicitud inexistente');
        }
        if (!$this->canView($req)) {
            throw new \RuntimeException('sin permiso para ver la solicitud');
        }
        return $req;
    }

    public function canView(Request $req): bool
    {
        $entity = (int) $req->fields['entities_id'];
        if (!Session::haveAccessToEntity($entity)) {
            return false;
        }
        if (Session::haveRight(Request::$rightname, Request::RIGHT_VIEW_ENTITY)) {
            return true;
        }
        if (Session::haveRight(Request::$rightname, Request::RIGHT_VIEW_OWN)) {
            return (int) $req->fields['users_id_requester'] === (int) (Session::getLoginUserID() ?: 0);
        }
        return false;
    }

    /** @return array<int,RequestItem> */
    public function loadItems(int $reqId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => RequestItem::getTable(),
            'WHERE'  => ['requests_id' => $reqId],
            'ORDER'  => 'line_no ASC, id ASC',
        ]) as $row) {
            $it = new RequestItem();
            if ($it->getFromDB((int) $row['id'])) {
                $out[] = $it;
            }
        }
        return $out;
    }

    // ---------------------------------------------------------------- internals

    /**
     * Ejecuta `$fn` bajo el LOCK COMÚN de la solicitud (`request_<id>`): serializa todas las mutaciones.
     *
     * @return mixed lo que devuelva `$fn`
     */
    private function withRequestLock(int $reqId, \Closure $fn): mixed
    {
        if ($reqId <= 0) {
            throw new \RuntimeException('solicitud inválida');
        }
        $name = AdvisoryLock::name('request_' . $reqId);
        if (!$this->lock->acquire($name, 10)) {
            throw new \RuntimeException('no se pudo obtener el lock de la solicitud (reintente)');
        }
        try {
            return $fn();
        } finally {
            $this->lock->release($name);
        }
    }

    private function lineRequestId(int $lineId): int
    {
        $item = new RequestItem();
        if (!$item->getFromDB($lineId)) {
            throw new \RuntimeException('línea inexistente');
        }
        return (int) $item->fields['requests_id'];
    }

    private function loadForEdit(int $id): Request
    {
        $req = new Request();
        if (!$req->getFromDB($id)) {
            throw new \RuntimeException('solicitud inexistente');
        }
        $this->assertRight(Request::RIGHT_EDIT_DRAFT);
        $this->assertEntity((int) $req->fields['entities_id']);
        if (!$this->canView($req)) {
            throw new \RuntimeException('sin permiso sobre la solicitud');
        }
        if (!$req->isDraft()) {
            throw new \RuntimeException('la solicitud no es un borrador editable');
        }
        return $req;
    }

    /** @param array<string,mixed> $fields */
    private function applyUpdate(Request $req, array $fields): void
    {
        $fields['id']           = (int) $req->getID();
        $fields['lock_version'] = (int) $req->fields['lock_version'] + 1;
        $fields['date_mod']     = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        if (!$req->update($fields)) {
            throw new \RuntimeException('no se pudo actualizar la solicitud');
        }
    }

    private function recomputeEstimated(Request $req): void
    {
        $currency = (string) $req->fields['currency_code'];
        $overrides = PluginConfig::currencyScaleOverrides();
        $total = Money::zero($currency, $overrides);
        foreach ($this->loadItems((int) $req->getID()) as $it) {
            $total = $total->plus(Money::ofStored((string) $it->fields['estimated_line_total'], $currency, $overrides));
        }
        // Ningún write fallido puede volverse éxito en silencio: se comprueba el resultado del update.
        $ok = $req->update([
            'id'               => (int) $req->getID(),
            'amount_estimated' => $total->amount(),
            'date_mod'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
        if (!$ok) {
            throw new \RuntimeException('no se pudo recalcular el total estimado de la solicitud');
        }
    }

    private function validateQuantity(mixed $raw, bool $inventoriable): int
    {
        return QuantityPolicy::validate($raw, $inventoriable);
    }

    private function nextLineNo(int $reqId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $max = 0;
        foreach ($DB->request([
            'SELECT' => 'line_no',
            'FROM'   => RequestItem::getTable(),
            'WHERE'  => ['requests_id' => $reqId],
            'ORDER'  => 'line_no DESC',
            'LIMIT'  => 1,
        ]) as $row) {
            $max = (int) $row['line_no'];
        }
        return $max + 1;
    }

    private function assertLineNoFree(int $reqId, int $lineNo, int $exceptId): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => RequestItem::getTable(),
            'WHERE'  => ['requests_id' => $reqId, 'line_no' => $lineNo],
            'LIMIT'  => 1,
        ]) as $row) {
            if ((int) $row['id'] !== $exceptId) {
                throw new \InvalidArgumentException("line_no {$lineNo} duplicado en la solicitud");
            }
        }
    }

    private function assertRight(int $bit): void
    {
        if (!Session::haveRight(Request::$rightname, $bit)) {
            throw new \RuntimeException('permiso denegado (ACL)');
        }
    }

    private function assertEntity(int $entitiesId): void
    {
        if (!Session::haveAccessToEntity($entitiesId)) {
            throw new \RuntimeException('sin acceso a la entidad');
        }
    }

    /**
     * Valida una referencia a un maestro NATIVO de GLPI (Group/Supplier/Budget) contra la entidad de la
     * SOLICITUD (no contra la sesión):
     *   - `0` = sin referencia (OK).
     *   - `>0` debe EXISTIR y ser APLICABLE a la entidad de la solicitud según la semántica NATIVA de
     *     entidades/recursividad de GLPI (misma entidad, o entidad ANCESTRO con `is_recursive`).
     *
     * No alcanza con `Session::haveAccessToEntity($refEntity)`: un usuario con acceso simultáneo a A y B
     * NO debe poder adjuntar a una solicitud de A un objeto exclusivo de la rama B. No se duplican
     * maestros ni se consulta el core por SQL directo saltando sus reglas.
     */
    private function assertReferenceForEntity(string $itemtype, int $id, int $requestEntityId): void
    {
        if ($id <= 0) {
            return;
        }
        if (!class_exists($itemtype)) {
            throw new \RuntimeException("tipo de referencia inválido: {$itemtype}");
        }
        /** @var \CommonDBTM $obj */
        $obj = new $itemtype();
        if (!$obj->getFromDB($id)) {
            throw new \InvalidArgumentException("referencia inexistente: {$itemtype}#{$id}");
        }
        // Un maestro sin dimensión de entidad (no esperado para Group/Supplier/Budget) no se restringe.
        if (!isset($obj->fields['entities_id'])) {
            return;
        }
        $refEntity    = (int) $obj->fields['entities_id'];
        $refRecursive = (bool) ($obj->fields['is_recursive'] ?? false);
        if (!self::isEntityApplicable($refEntity, $refRecursive, $requestEntityId)) {
            throw new \RuntimeException("referencia no aplicable a la entidad de la solicitud: {$itemtype}#{$id}");
        }
    }

    /**
     * ¿Un objeto en la entidad `$refEntity` (con recursividad `$refRecursive`) es APLICABLE a la entidad
     * `$requestEntity`, según la semántica NATIVA del árbol de entidades de GLPI?
     *   - misma entidad → siempre aplicable;
     *   - recursivo → aplicable si `$refEntity` es la propia o un ANCESTRO de `$requestEntity` (se hereda
     *     hacia abajo). Se resuelve con las utilidades nativas del árbol (`getSonsOf`/`getAncestorsOf`);
     *   - de otra RAMA (ni misma entidad ni ancestro recursivo) → NO aplicable.
     * Fail-closed: si no hubiera utilidad nativa del árbol, sólo se admite la misma entidad.
     */
    private static function isEntityApplicable(int $refEntity, bool $refRecursive, int $requestEntity): bool
    {
        if ($refEntity === $requestEntity) {
            return true; // misma entidad: siempre aplicable
        }
        if (!$refRecursive) {
            return false; // no recursivo: sólo su propia entidad
        }
        // Recursivo hacia ABAJO: la referencia aplica si su entidad es ancestro de la de la solicitud.
        if (function_exists('getSonsOf')) {
            $sons = getSonsOf('glpi_entities', $refEntity); // incluye $refEntity + descendientes
            if (is_array($sons)) {
                return in_array($requestEntity, array_map('intval', array_values($sons)), true);
            }
        }
        if (function_exists('getAncestorsOf')) {
            $ancestors = getAncestorsOf('glpi_entities', $requestEntity); // ancestros de la solicitud
            if (is_array($ancestors)) {
                return in_array($refEntity, array_map('intval', array_values($ancestors)), true);
            }
        }
        return false; // sin utilidad nativa del árbol → fail-closed (sólo misma entidad, ya cubierta)
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
