<?php

/**
 * Cotizaciones de Compras (P2D-2; gate §8 "Selección de cotización — sin boolean concurrente").
 *
 * - ACL: `MANAGE_PURCHASING` (+ acceso a la entidad) para toda operación comercial; FAIL-CLOSED.
 * - Estado: sólo en los estados configurados (`quote_states`) según el MOTOR (autoridad).
 * - Proveedor = `Supplier` NATIVO y adjuntos = `Document` NATIVOS, validados PARA la entidad de la
 *   solicitud (`ReferenceValidator`, cadena viva de entidades): nada cross-branch.
 * - Selección: `requests.quotes_id_selected` es la ÚNICA fuente de verdad; se cambia bajo el lock común
 *   de la solicitud con `lock_version` esperado opcional ⇒ dos workers nunca dejan "dos seleccionadas".
 * - Dinero exacto (`Money`/`QuoteMath`); totales DERIVADOS. Moneda = la de la solicitud (v1).
 * - Toda mutación + su evento de auditoría confirman JUNTOS (transacción local, tablas propias).
 *
 * Toda mutación que altera el contenido COMMERCIAL (selección / cotización seleccionada) exige que el
 * perfil pueda reabrir aprobaciones (`ReopenCapability`) y escribe una marca DURABLE de integridad en su
 * misma transacción; la invalidación la ejecuta `ApprovalOrchestrator` (fachada) y, si falla, la marca
 * persiste y toda decisión posterior debe repararla antes (o fallar cerrado).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Document_Item;
use Session;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Quote;
use GlpiPlugin\Companypurchasing\Model\QuoteItem;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;

class QuoteManager
{
    private Audit $audit;
    private AdvisoryLock $lock;
    private ReferenceValidator $refs;
    private WorkflowGateway $wf;
    private PolicyStore $policies;
    private IntegrityLedger $integrity;

    public function __construct(?Audit $audit = null, ?AdvisoryLock $lock = null, ?ReferenceValidator $refs = null, ?WorkflowGateway $wf = null)
    {
        $this->audit     = $audit ?? new Audit();
        $this->lock      = $lock ?? new AdvisoryLock();
        $this->refs      = $refs ?? new ReferenceValidator();
        $this->wf        = $wf ?? new WorkflowGateway();
        $this->policies  = new PolicyStore();
        $this->integrity = new IntegrityLedger();
    }

    /**
     * @param array{suppliers_id:int, reference?:string, currency_code?:string, discounts?:string, taxes?:string,
     *              freight?:string, valid_until?:?string, notes?:string,
     *              lines?:array<int,array{items_id:int, final_unit_price:string}>} $in
     */
    public function createQuote(int $requestId, array $in): int
    {
        return (int) $this->lock->withRequestLock($requestId, function () use ($requestId, $in): int {
            /** @var \DBmysql $DB */
            global $DB;
            $req = $this->loadForCommercial($requestId);
            $entity = (int) $req->fields['entities_id'];
            $currency = (string) $req->fields['currency_code'];
            $overrides = PluginConfig::currencyScaleOverrides();

            $supplierId = (int) ($in['suppliers_id'] ?? 0);
            if ($supplierId <= 0) {
                throw new \InvalidArgumentException('la cotización requiere un proveedor (Supplier)');
            }
            $this->refs->assertReferenceForEntity(\Supplier::class, $supplierId, $entity);
            if (isset($in['currency_code']) && strtoupper((string) $in['currency_code']) !== $currency) {
                throw new \InvalidArgumentException('la moneda de la cotización debe ser la de la solicitud (v1, fail-closed)');
            }
            $amounts = $this->amounts($in, $currency, $overrides, null);
            $lines = $this->validatedLines($requestId, (array) ($in['lines'] ?? []), $currency, $overrides);
            $now = $this->now();

            $DB->beginTransaction();
            try {
                $quoteId = (int) (new Quote())->add([
                    'requests_id'      => $requestId,
                    'entities_id'      => $entity,
                    'is_recursive'     => 0,
                    'suppliers_id'     => $supplierId,
                    'reference'        => substr((string) ($in['reference'] ?? ''), 0, 190),
                    'currency_code'    => $currency,
                    'discounts'        => $amounts['discounts'],
                    'taxes'            => $amounts['taxes'],
                    'freight'          => $amounts['freight'],
                    'valid_until'      => $this->dateOrNull($in['valid_until'] ?? null),
                    'notes'            => (string) ($in['notes'] ?? ''),
                    'users_id_creator' => (int) (Session::getLoginUserID() ?: 0),
                    'lock_version'     => 0,
                    'date_creation'    => $now,
                    'date_mod'         => $now,
                ]);
                if ($quoteId <= 0) {
                    throw new \RuntimeException('no se pudo crear la cotización');
                }
                foreach ($lines as $itemsId => $price) {
                    $this->upsertLine($quoteId, $itemsId, $price, $now);
                }
                $this->audit->record($requestId, PurchasingEvent::EV_QUOTE_CREATED, $entity, [
                    'quote_id' => $quoteId, 'suppliers_id' => $supplierId, 'lines' => count($lines),
                ], (string) $req->fields['correlation_id']);
                $DB->commit();
                return $quoteId;
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
        });
    }

    /**
     * Actualiza importes/referencia/precios de línea de una cotización. Devuelve true si la cotización es
     * la SELECCIONADA (su cambio altera el contenido COMMERCIAL_FINANCIAL: el orquestador re-evalúa).
     *
     * @param array<string,mixed> $in
     */
    public function updateQuote(int $quoteId, array $in): bool
    {
        $quote = $this->loadQuote($quoteId);
        $requestId = (int) $quote->fields['requests_id'];
        return (bool) $this->lock->withRequestLock($requestId, function () use ($requestId, $quoteId, $in): bool {
            /** @var \DBmysql $DB */
            global $DB;
            $req = $this->loadForCommercial($requestId);
            $quote = $this->loadQuote($quoteId); // relectura fresca bajo lock
            if ((int) $quote->fields['requests_id'] !== $requestId) {
                throw new \RuntimeException('la cotización no pertenece a la solicitud (fail-closed)');
            }
            // Cambiar la cotización SELECCIONADA altera COMMERCIAL_FINANCIAL_SCOPE: puede exigir reabrir una
            // aprobación ⇒ el perfil debe poder hacerlo ANTES de aceptar el cambio.
            $isSelected = (int) $req->fields['quotes_id_selected'] === $quoteId;
            if ($isSelected) {
                ReopenCapability::assert();
            }
            $currency = (string) $req->fields['currency_code'];
            $overrides = PluginConfig::currencyScaleOverrides();
            $amounts = $this->amounts($in, $currency, $overrides, $quote);
            $lines = $this->validatedLines($requestId, (array) ($in['lines'] ?? []), $currency, $overrides);
            $fields = [
                'id'           => $quoteId,
                'discounts'    => $amounts['discounts'],
                'taxes'        => $amounts['taxes'],
                'freight'      => $amounts['freight'],
                'lock_version' => (int) $quote->fields['lock_version'] + 1,
                'date_mod'     => $this->now(),
            ];
            if (array_key_exists('reference', $in)) {
                $fields['reference'] = substr((string) $in['reference'], 0, 190);
            }
            if (array_key_exists('notes', $in)) {
                $fields['notes'] = (string) $in['notes'];
            }
            $DB->beginTransaction();
            try {
                if (!$quote->update($fields)) {
                    throw new \RuntimeException('no se pudo actualizar la cotización');
                }
                foreach ($lines as $itemsId => $price) {
                    $this->upsertLine($quoteId, $itemsId, $price, $this->now());
                }
                $this->audit->record($requestId, PurchasingEvent::EV_QUOTE_UPDATED, (int) $req->fields['entities_id'], [
                    'quote_id' => $quoteId, 'lines' => array_keys($lines),
                ], (string) $req->fields['correlation_id']);
                if ($isSelected) {
                    // Marca DURABLE en la MISMA transacción (sólo se limpia tras invalidación confirmada o
                    // verificación contra el ledger del motor).
                    $this->integrity->markDirty($req, [ScopeCatalog::SCOPE_COMMERCIAL_FINANCIAL], PurchasingEvent::EV_QUOTE_UPDATED, $this->lastState, $this->lastLock);
                }
                $DB->commit();
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
            return $isSelected;
        });
    }

    /**
     * Selecciona la cotización (única fuente de verdad: `requests.quotes_id_selected`). Serializada por
     * el lock común; con `$expectedLockVersion` es además optimista (una selección concurrente basada en
     * una lectura vieja FALLA en vez de pisar a la otra). Idempotente si ya está seleccionada.
     *
     * @return bool true si cambió la selección
     */
    public function selectQuote(int $requestId, int $quoteId, ?int $expectedLockVersion = null): bool
    {
        return (bool) $this->lock->withRequestLock($requestId, function () use ($requestId, $quoteId, $expectedLockVersion): bool {
            /** @var \DBmysql $DB */
            global $DB;
            $req = $this->loadForCommercial($requestId);
            if ($expectedLockVersion !== null && (int) $req->fields['lock_version'] !== $expectedLockVersion) {
                throw new \RuntimeException('conflicto de concurrencia: la solicitud cambió (lock_version)');
            }
            $quote = $this->loadQuote($quoteId);
            if ((int) $quote->fields['requests_id'] !== $requestId) {
                // Cotización de OTRA solicitud (incl. de otra entidad/rama): jamás se acepta.
                throw new \RuntimeException('la cotización no pertenece a la solicitud (fail-closed)');
            }
            // El proveedor debe seguir siendo aplicable a la entidad (cadena viva) y los términos completos.
            $this->refs->assertReferenceForEntity(\Supplier::class, (int) $quote->fields['suppliers_id'], (int) $req->fields['entities_id']);
            $this->termsFor($req, $quote);
            $previous = (int) $req->fields['quotes_id_selected'];
            if ($previous === $quoteId) {
                return false;
            }
            // Cambiar la selección altera COMMERCIAL_FINANCIAL_SCOPE: el perfil debe poder reabrir.
            ReopenCapability::assert();
            $DB->beginTransaction();
            try {
                if (!$req->update([
                    'id'                 => $requestId,
                    'quotes_id_selected' => $quoteId,
                    'lock_version'       => (int) $req->fields['lock_version'] + 1,
                    'date_mod'           => $this->now(),
                ])) {
                    throw new \RuntimeException('no se pudo seleccionar la cotización');
                }
                $this->audit->record($requestId, PurchasingEvent::EV_QUOTE_SELECTED, (int) $req->fields['entities_id'], [
                    'from' => $previous, 'to' => $quoteId, 'suppliers_id' => (int) $quote->fields['suppliers_id'],
                ], (string) $req->fields['correlation_id']);
                $this->integrity->markDirty($req, [ScopeCatalog::SCOPE_COMMERCIAL_FINANCIAL], PurchasingEvent::EV_QUOTE_SELECTED, $this->lastState, $this->lastLock);
                $DB->commit();
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
            return true;
        });
    }

    /**
     * Adjunta un `Document` NATIVO a la cotización vía `Document_Item` (N por cotización; idempotente).
     * El Document debe existir y ser aplicable a la entidad de la solicitud (nada cross-branch).
     *
     * @return bool true si creó el vínculo ahora
     */
    public function attachDocument(int $quoteId, int $documentsId): bool
    {
        $quote = $this->loadQuote($quoteId);
        $requestId = (int) $quote->fields['requests_id'];
        return (bool) $this->lock->withRequestLock($requestId, function () use ($requestId, $quoteId, $documentsId): bool {
            /** @var \DBmysql $DB */
            global $DB;
            $req = $this->loadForCommercial($requestId);
            $entity = (int) $req->fields['entities_id'];
            if ($documentsId <= 0) {
                throw new \InvalidArgumentException('documento inválido');
            }
            $this->refs->assertReferenceForEntity(\Document::class, $documentsId, $entity);
            $link = new Document_Item();
            if ($link->getFromDBByCrit(['documents_id' => $documentsId, 'itemtype' => Quote::class, 'items_id' => $quoteId])) {
                return false;
            }
            $DB->beginTransaction();
            try {
                $linkId = (int) $link->add([
                    'documents_id' => $documentsId,
                    'itemtype'     => Quote::class,
                    'items_id'     => $quoteId,
                    'entities_id'  => $entity,
                    'is_recursive' => 0,
                ]);
                if ($linkId <= 0) {
                    throw new \RuntimeException('no se pudo vincular el documento a la cotización');
                }
                $this->audit->record($requestId, PurchasingEvent::EV_QUOTE_ATTACHED, $entity, [
                    'quote_id' => $quoteId, 'documents_id' => $documentsId,
                ], (string) $req->fields['correlation_id']);
                $DB->commit();
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                throw $e;
            }
            return true;
        });
    }

    /** Documents NATIVOS vinculados a la cotización. @return array<int,int> */
    public function attachments(int $quoteId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => 'documents_id',
            'FROM'   => Document_Item::getTable(),
            'WHERE'  => ['itemtype' => Quote::class, 'items_id' => $quoteId],
            'ORDER'  => 'documents_id ASC',
        ]) as $row) {
            $out[] = (int) $row['documents_id'];
        }
        return $out;
    }

    /**
     * Términos comerciales EXACTOS de la cotización SELECCIONADA (para COMMERCIAL_FINANCIAL_SCOPE), o
     * null si no hay selección. FAIL-CLOSED si la selección es inconsistente o incompleta.
     *
     * @return array{quote_id:int, reference:string, suppliers_id:int, math:array<string,mixed>}|null
     */
    public function commercialTerms(Request $req): ?array
    {
        $selected = (int) ($req->fields['quotes_id_selected'] ?? 0);
        if ($selected <= 0) {
            return null;
        }
        $quote = $this->loadQuote($selected);
        if ((int) $quote->fields['requests_id'] !== (int) $req->getID()) {
            throw new \RuntimeException('selección inconsistente: la cotización no pertenece a la solicitud (fail-closed)');
        }
        return $this->termsFor($req, $quote);
    }

    // ---------------------------------------------------------------- internals

    /** @return array{quote_id:int, reference:string, suppliers_id:int, math:array<string,mixed>} */
    private function termsFor(Request $req, Quote $quote): array
    {
        // Revalidación EN VIVO al construir el snapshot: el Supplier válido al seleccionar pudo moverse de
        // rama después. Cadena viva de entidades (nunca caché del árbol). Fail-closed.
        $this->refs->assertReferenceForEntity(\Supplier::class, (int) $quote->fields['suppliers_id'], (int) $req->fields['entities_id']);
        $currency = (string) $req->fields['currency_code'];
        if ((string) $quote->fields['currency_code'] !== $currency) {
            throw new \RuntimeException('la moneda de la cotización no coincide con la de la solicitud (fail-closed)');
        }
        $overrides = PluginConfig::currencyScaleOverrides();
        $prices = $this->quotePrices((int) $quote->getID());
        $requestLines = $this->requestLines((int) $req->getID());
        QuoteMath::assertCoverage(array_keys($requestLines), array_keys($prices));
        $lines = [];
        foreach ($requestLines as $itemsId => $qty) {
            $lines[] = [
                'line_id'          => $itemsId,
                'quantity'         => $qty,
                'final_unit_price' => Money::ofStored($prices[$itemsId], $currency, $overrides)->amount(),
            ];
        }
        $math = QuoteMath::compute(
            $lines,
            Money::ofStored((string) $quote->fields['discounts'], $currency, $overrides)->amount(),
            Money::ofStored((string) $quote->fields['taxes'], $currency, $overrides)->amount(),
            Money::ofStored((string) $quote->fields['freight'], $currency, $overrides)->amount(),
            $currency,
            $overrides
        );
        return [
            'quote_id'     => (int) $quote->getID(),
            'reference'    => (string) $quote->fields['reference'],
            'suppliers_id' => (int) $quote->fields['suppliers_id'],
            'math'         => $math,
        ];
    }

    /** Líneas de la solicitud en orden (line_no, id) ⇒ items_id → cantidad. @return array<int,int> */
    private function requestLines(int $requestId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'quantity'],
            'FROM'   => RequestItem::getTable(),
            'WHERE'  => ['requests_id' => $requestId],
            'ORDER'  => 'line_no ASC, id ASC',
        ]) as $row) {
            $out[(int) $row['id']] = (int) $row['quantity'];
        }
        return $out;
    }

    /** items_id → final_unit_price (almacenado). @return array<int,string> */
    private function quotePrices(int $quoteId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => QuoteItem::getTable(), 'WHERE' => ['quotes_id' => $quoteId]]) as $row) {
            $out[(int) $row['items_id']] = (string) $row['final_unit_price'];
        }
        return $out;
    }

    /**
     * Valida líneas de precio: cada `items_id` pertenece a la solicitud, sin repetir, precio exacto.
     *
     * @param array<int,mixed> $lines
     * @param array<string,int> $overrides
     * @return array<int,string> items_id → precio (string exacto a la escala de la moneda)
     */
    private function validatedLines(int $requestId, array $lines, string $currency, array $overrides): array
    {
        $own = $this->requestLines($requestId);
        $out = [];
        foreach ($lines as $l) {
            if (!is_array($l)) {
                throw new \InvalidArgumentException('línea de cotización inválida');
            }
            $itemsId = (int) ($l['items_id'] ?? 0);
            if (!isset($own[$itemsId])) {
                throw new \InvalidArgumentException("la línea {$itemsId} no pertenece a la solicitud (fail-closed)");
            }
            if (isset($out[$itemsId])) {
                throw new \InvalidArgumentException("línea {$itemsId} repetida en la cotización");
            }
            $out[$itemsId] = Money::of((string) ($l['final_unit_price'] ?? ''), $currency, $overrides)->amount();
        }
        return $out;
    }

    private function upsertLine(int $quoteId, int $itemsId, string $price, string $now): void
    {
        $qi = new QuoteItem();
        if ($qi->getFromDBByCrit(['quotes_id' => $quoteId, 'items_id' => $itemsId])) {
            if (!$qi->update(['id' => (int) $qi->getID(), 'final_unit_price' => $price, 'date_mod' => $now])) {
                throw new \RuntimeException('no se pudo actualizar el precio de línea');
            }
            return;
        }
        $id = (int) $qi->add([
            'quotes_id'        => $quoteId,
            'items_id'         => $itemsId,
            'final_unit_price' => $price,
            'date_creation'    => $now,
            'date_mod'         => $now,
        ]);
        if ($id <= 0) {
            throw new \RuntimeException('no se pudo registrar el precio de línea');
        }
    }

    /**
     * @param array<string,mixed> $in
     * @param array<string,int> $overrides
     * @return array{discounts:string, taxes:string, freight:string}
     */
    private function amounts(array $in, string $currency, array $overrides, ?Quote $current): array
    {
        $out = [];
        foreach (['discounts', 'taxes', 'freight'] as $k) {
            $raw = array_key_exists($k, $in)
                ? (string) $in[$k]
                : ($current !== null ? Money::ofStored((string) $current->fields[$k], $currency, $overrides)->amount() : '0');
            $out[$k] = Money::of($raw, $currency, $overrides)->amount();
        }
        return $out;
    }

    /** Carga fresca + ACL comercial + entidad + estado del MOTOR permitido. FAIL-CLOSED. */
    private function loadForCommercial(int $requestId): Request
    {
        $req = new Request();
        if (!$req->getFromDB($requestId)) {
            throw new \RuntimeException('solicitud inexistente');
        }
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_MANAGE_PURCHASING)) {
            throw new \RuntimeException('permiso denegado (MANAGE_PURCHASING)');
        }
        if (!Session::haveAccessToEntity((int) $req->fields['entities_id'])) {
            throw new \RuntimeException('sin acceso a la entidad');
        }
        // P2D-3: iniciada la compra, cotizaciones/precios quedan CONGELADOS (el costo por unidad ya se fijó).
        if (!empty($req->fields['purchase_started_at'])) {
            throw new \RuntimeException('compra iniciada: cotizaciones y precios congelados (fail-closed)');
        }
        $inst = $this->wf->loadInstance((int) $req->fields['workflow_instances_id']);
        $state = $inst !== null ? $this->wf->stateCode($inst) : '';
        // Estados permitidos según la política PINNEADA de la solicitud (no la configuración vigente).
        if ($inst === null || !$this->wf->isOpen($inst)
            || !in_array($state, $this->policies->forRequest($req)->quoteStates(), true)) {
            throw new \RuntimeException('la solicitud no admite gestión de cotizaciones en su estado actual (fail-closed)');
        }
        $this->lastState = $state;
        $this->lastLock  = (int) $inst->fields['lock_version'];
        return $req;
    }

    /** Estado y lock_version del motor leídos en la última carga comercial (para la marca de integridad). */
    private string $lastState = '';
    private int $lastLock = 0;

    private function loadQuote(int $quoteId): Quote
    {
        $q = new Quote();
        if ($quoteId <= 0 || !$q->getFromDB($quoteId)) {
            throw new \RuntimeException('cotización inexistente');
        }
        return $q;
    }

    private function dateOrNull(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
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
