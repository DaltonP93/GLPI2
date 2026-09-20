<?php

/**
 * Orquestador del NÚCLEO de compras (P2D-1): CRUD controlado de borrador, numeración al abandonar
 * DRAFT, importes exactos y auditoría. Fail-closed en ACL y multi-entidad.
 *
 * NO integra `companyworkflow` (P2D-2): el `domain_state` es un snapshot/cache local; DRAFT es el
 * estado inicial y `submitDraft()` es el evento local "abandona DRAFT" (asigna número + pinnea la
 * versión de scopes). La AUTORIDAD de estados será `companyworkflow`.
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
        $entity = (int) ($in['entities_id'] ?? ($_SESSION['glpiactive_entity'] ?? 0));
        $this->assertRight(Request::RIGHT_CREATE_REQUEST);
        $this->assertEntity($entity);

        $currency = strtoupper((string) ($in['currency_code'] ?? PluginConfig::defaultCurrency()));
        if (!CurrencyPolicy::isWellFormed($currency)) {
            throw new \InvalidArgumentException('moneda inválida');
        }

        $me  = (int) (Session::getLoginUserID() ?: 0);
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $corr = bin2hex(random_bytes(16));

        // Referencias a maestros NATIVOS: 0 = sin referencia; >0 debe existir y ser visible en la entidad.
        $supplierId = (int) ($in['suppliers_id_suggested'] ?? 0);
        $budgetId   = (int) ($in['budgets_id'] ?? 0);
        $this->assertReference(\Supplier::class, $supplierId);
        $this->assertReference(\Budget::class, $budgetId);

        $req = new Request();
        // `number`/`number_seq` se OMITEN: toman el DEFAULT NULL (varios borradores conviven sin chocar
        // con los UNIQUE; MySQL admite múltiples NULL). Se asignan en submitDraft().
        $id = (int) $req->add([
            'entities_id'          => $entity,
            'is_recursive'         => (int) ($in['is_recursive'] ?? 0),
            'users_id_requester'   => (int) ($in['users_id_requester'] ?? $me),
            'groups_id_department' => (int) ($in['groups_id_department'] ?? 0),
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
        return $id;
    }

    // ---------------------------------------------------------------- UPDATE header

    /** @param array<string,mixed> $in */
    public function updateDraft(int $id, array $in): void
    {
        $req = $this->loadForEdit($id);
        $entity = (int) $req->fields['entities_id'];

        $fields = [];
        foreach ([
            'groups_id_department' => 'int',
            'category'             => 'str190',
            'destination'          => 'str255',
            'reason'               => 'text',
            'observations'         => 'text',
            'suppliers_id_suggested' => 'int',
            'budgets_id'           => 'int',
            'users_id_requester'   => 'int',
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
        // Validar referencias nativas si cambian (0 = sin referencia; >0 debe existir y ser visible).
        if (array_key_exists('suppliers_id_suggested', $fields)) {
            $this->assertReference(\Supplier::class, (int) $fields['suppliers_id_suggested']);
        }
        if (array_key_exists('budgets_id', $fields)) {
            $this->assertReference(\Budget::class, (int) $fields['budgets_id']);
        }
        $this->applyUpdate($req, $fields);
        $this->audit->record($id, PurchasingEvent::EV_REQUEST_UPDATED, $entity, ['fields' => array_keys($fields)], (string) $req->fields['correlation_id']);
    }

    // ---------------------------------------------------------------- LINES

    /**
     * @param array<string,mixed> $line
     * @return int id de la línea
     */
    public function addLine(int $reqId, array $line): int
    {
        $req = $this->loadForEdit($reqId);
        $currency = (string) $req->fields['currency_code'];
        $overrides = PluginConfig::currencyScaleOverrides();

        $isInv = (int) (($line['is_inventoriable'] ?? 0) ? 1 : 0);
        $qty   = $this->validateQuantity($line['quantity'] ?? null, $isInv === 1);
        $unitPrice = Money::of((string) ($line['estimated_unit_price'] ?? '0'), $currency, $overrides);
        $lineTotal = $unitPrice->timesInt($qty);

        $lineNo = isset($line['line_no']) ? (int) $line['line_no'] : $this->nextLineNo($reqId);
        $this->assertLineNoFree($reqId, $lineNo, 0);

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
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
        return $lineId;
    }

    /** @param array<string,mixed> $line */
    public function updateLine(int $lineId, array $line): void
    {
        $item = new RequestItem();
        if (!$item->getFromDB($lineId)) {
            throw new \RuntimeException('línea inexistente');
        }
        $reqId = (int) $item->fields['requests_id'];
        $req = $this->loadForEdit($reqId);
        $currency = (string) $req->fields['currency_code'];
        $overrides = PluginConfig::currencyScaleOverrides();

        $isInv = array_key_exists('is_inventoriable', $line)
            ? (int) (($line['is_inventoriable'] ? 1 : 0))
            : (int) $item->fields['is_inventoriable'];
        $qty = array_key_exists('quantity', $line)
            ? $this->validateQuantity($line['quantity'], $isInv === 1)
            : (int) $item->fields['quantity'];
        // Precio provisto por el usuario → `of()` (escala de la moneda). Precio NO provisto → se reusa
        // el valor YA ALMACENADO (DECIMAL 6dp que MySQL devuelve como "N.000000") → `ofStored()`.
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
        if (!$item->update($fields)) {
            throw new \RuntimeException('no se pudo actualizar la línea');
        }
        $this->recomputeEstimated($req);
        $this->audit->record($reqId, PurchasingEvent::EV_LINE_UPDATED, (int) $req->fields['entities_id'], ['line_id' => $lineId], (string) $req->fields['correlation_id']);
    }

    public function removeLine(int $lineId): void
    {
        $item = new RequestItem();
        if (!$item->getFromDB($lineId)) {
            throw new \RuntimeException('línea inexistente');
        }
        $reqId = (int) $item->fields['requests_id'];
        $req = $this->loadForEdit($reqId);
        $item->delete(['id' => $lineId], true);
        $this->recomputeEstimated($req);
        $this->audit->record($reqId, PurchasingEvent::EV_LINE_REMOVED, (int) $req->fields['entities_id'], ['line_id' => $lineId], (string) $req->fields['correlation_id']);
    }

    // ---------------------------------------------------------------- SUBMIT (leaves DRAFT)

    /**
     * Abandona DRAFT por primera vez: asigna el número visible (transaccional) y pinnea la versión de
     * scopes. NO integra companyworkflow (P2D-2). Devuelve el número asignado.
     */
    public function submitDraft(int $id): int
    {
        // Serialización por solicitud (advisory lock con nombre): dos workers no pueden enviar la MISMA
        // solicitud a la vez. Independiente de transacciones.
        $lockName = AdvisoryLock::name('submit_' . $id);
        if (!$this->lock->acquire($lockName, 10)) {
            throw new \RuntimeException('no se pudo obtener el lock de envío (reintente)');
        }
        try {
            // Re-lectura FRESCA bajo lock (otro worker pudo enviarla ya).
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

            // IDEMPOTENCIA: si ya NO es borrador y ya tiene número → devolver el MISMO número (no reserva
            // otro ni emite otro REQUEST_SUBMITTED). En otro estado sin número, es un error explícito.
            if (!$req->isDraft()) {
                $existing = (int) ($req->fields['number_seq'] ?? 0);
                if ($existing > 0) {
                    return $existing; // ya enviada: retry idempotente
                }
                throw new \RuntimeException('la solicitud ya no es un borrador enviable (estado: ' . (string) $req->fields['domain_state'] . ')');
            }

            // FAIL-CLOSED de scopes ANTES de reservar número: si la versión vigente falta/corrupta, la
            // solicitud sigue en DRAFT y NO consume número.
            $scopesVersion = PluginConfig::currentScopesVersion();
            ScopeCatalog::assertVersionComplete($scopesVersion);

            $year   = (int) date('Y', strtotime((string) ($_SESSION['glpi_currenttime'] ?? 'now')) ?: time());
            $seq    = $this->numbering->assign($entity, NumberingService::SCOPE_REQUEST, $year);
            $number = NumberingService::formatNumber(NumberingService::SCOPE_REQUEST, $year, $seq);

            if (!$req->update([
                'id'             => $id,
                'number'         => $number,
                'number_seq'     => $seq,
                'number_scope'   => NumberingService::SCOPE_REQUEST,
                'number_year'    => $year,
                'domain_state'   => Request::STATE_PENDING,
                'scopes_version' => $scopesVersion,
                'lock_version'   => (int) $req->fields['lock_version'] + 1,
                'date_mod'       => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ])) {
                throw new \RuntimeException('no se pudo enviar la solicitud');
            }
            $this->audit->record($id, PurchasingEvent::EV_REQUEST_SUBMITTED, $entity, ['number' => $number, 'seq' => $seq, 'scopes_version' => $scopesVersion], (string) $req->fields['correlation_id']);
            return $seq;
        } finally {
            $this->lock->release($lockName);
        }
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
        $req->update([
            'id'               => (int) $req->getID(),
            'amount_estimated' => $total->amount(),
            'date_mod'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
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
     * Valida una referencia a un maestro NATIVO de GLPI (Supplier/Budget) por su modelo soportado:
     *   - `0` = sin referencia (OK).
     *   - `>0` debe EXISTIR y ser VISIBLE en su entidad para la sesión actual (fail-closed).
     * No se duplican maestros ni se consulta el core saltando su modelo.
     */
    private function assertReference(string $itemtype, int $id): void
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
        if (isset($obj->fields['entities_id'])
            && !Session::haveAccessToEntity((int) $obj->fields['entities_id'], (bool) ($obj->fields['is_recursive'] ?? false))
        ) {
            throw new \RuntimeException("referencia no visible en la entidad: {$itemtype}#{$id}");
        }
    }
}
