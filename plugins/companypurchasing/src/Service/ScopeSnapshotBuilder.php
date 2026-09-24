<?php

/**
 * Constructor DETERMINISTA del snapshot semántico de un scope de aprobación (gate §Approval scopes).
 *
 * SEPARACIÓN (gate §document_version):
 *   - `build()` produce SÓLO el snapshot SEMÁNTICO (metadata de sujeto/scope + payload). NO inventa una
 *     `document_version`: la asigna el DOMINIO con `DocumentVersionAllocator` (P2D-2).
 *   - `envelope()` arma la envoltura probatoria que espera `SignatureApi::recordDocumentVersion()`
 *     (`{ schema, subject_type, subject_id, entity_id, document_version, payload }`), exigiendo
 *     `document_version > 0` como PARÁMETRO (nunca hardcodeado). Se usará en P2D-2.
 * Compras construye el payload SEMÁNTICO; **Firma canonicaliza/hashea** (no se duplica su canonicalización).
 *
 * Determinismo: dados el mismo estado de solicitud + la misma versión de scope ⇒ el mismo payload
 * (claves de nivel superior ordenadas; líneas ordenadas por `line_no`,`id`; importes como string exacto).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;

final class ScopeSnapshotBuilder
{
    public const SCHEMA = 'companypurchasing/request/v1';

    /**
     * Construye el snapshot de un scope para una solicitud ya cargada + sus líneas.
     *
     * @param array<int,RequestItem> $items
     * @param array<string,mixed>|null $commercial  términos de la cotización SELECCIONADA (P2D-2,
     *        `QuoteManager::commercialTerms()`); null ⇒ no hay claves comerciales (un scope que las exija
     *        falla cerrado en `selectFields`).
     * @return array<string,mixed>
     */
    public function build(Request $request, array $items, string $scopeKey, ?int $version = null, ?array $commercial = null): array
    {
        if ($version === null) {
            $version = (int) ($request->fields['scopes_version'] ?? 0);
            if ($version <= 0) {
                $version = PluginConfig::currentScopesVersion();
            }
        }
        $fields  = ScopeCatalog::fields($version, $scopeKey);
        $full    = $this->fullRecord($request, $items, $commercial);
        $payload = self::selectFields($full, $fields);

        // Snapshot SEMÁNTICO — SIN `document_version` (la declara el dominio en P2D-2, ver `envelope()`).
        return [
            'schema'         => self::SCHEMA,
            'subject_type'   => Request::class,
            'subject_id'     => (int) $request->getID(),
            'entity_id'      => (int) ($request->fields['entities_id'] ?? 0),
            'scope'          => $scopeKey,
            'scopes_version' => $version,
            'payload'        => $payload,
        ];
    }

    /**
     * Envoltura probatoria para `SignatureApi::recordDocumentVersion()` a partir de un snapshot
     * SEMÁNTICO. `document_version` es OBLIGATORIA y `> 0` (la declara el dominio; NUNCA hardcodeada).
     * Pura y unit-testable. Se usará en P2D-2 (P2D-1 no aloca versiones documentales).
     *
     * @param array<string,mixed> $semantic
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function envelope(array $semantic, int $documentVersion): array
    {
        if ($documentVersion <= 0) {
            throw new \InvalidArgumentException('document_version debe ser > 0 (la declara el dominio, no el builder)');
        }
        return [
            'schema'           => (string) ($semantic['schema'] ?? ''),
            'subject_type'     => (string) ($semantic['subject_type'] ?? ''),
            'subject_id'       => (int) ($semantic['subject_id'] ?? 0),
            'entity_id'        => (int) ($semantic['entity_id'] ?? 0),
            'document_version' => $documentVersion,
            'payload'          => $semantic['payload'] ?? [],
        ];
    }

    /**
     * Selección PURA y determinista de campos (unit-testable sin BD).
     *
     * @param array<string,mixed> $full
     * @param array<int,string>   $fields
     * @return array<string,mixed>
     */
    public static function selectFields(array $full, array $fields): array
    {
        $out = [];
        foreach ($fields as $key) {
            if (!array_key_exists($key, $full)) {
                // FAIL-CLOSED: un campo protegido/configurado que el builder NO puede producir AHORA no se
                // ignora en silencio — se rechaza (jamás un snapshot parcial que luego se firmará).
                throw new \RuntimeException("campo de scope no producible por el builder: '{$key}' (fail-closed)");
            }
            $out[$key] = $full[$key];
        }
        ksort($out); // determinismo del orden de claves de nivel superior
        return $out;
    }

    /**
     * Record completo (todas las claves posibles) del cual cada scope SELECCIONA su subconjunto.
     * Sin cotización seleccionada NO se emiten proveedor/cotización/precios finales/descuentos/impuestos/
     * flete y `total` es el ESTIMADO; con cotización seleccionada (P2D-2) se emiten desde
     * `commercialRecord()` y `total` pasa a ser el TOTAL FINAL. Importes siempre como string EXACTO.
     *
     * @param array<int,RequestItem> $items
     * @param array<string,mixed>|null $commercial
     * @return array<string,mixed>
     */
    private function fullRecord(Request $request, array $items, ?array $commercial = null): array
    {
        $currency = (string) ($request->fields['currency_code'] ?? 'PYG');
        $overrides = PluginConfig::currencyScaleOverrides();
        // FAIL-CLOSED: un importe almacenado inconsistente con la moneda LANZA (nunca entra crudo a un
        // snapshot que luego se firmará). Sin fallback silencioso.
        $total = Money::ofStored((string) ($request->fields['amount_estimated'] ?? '0'), $currency, $overrides)->amount();

        $record = [
            'requester'    => (int) ($request->fields['users_id_requester'] ?? 0),
            'department'   => (int) ($request->fields['groups_id_department'] ?? 0),
            'category'     => (string) ($request->fields['category'] ?? ''),
            'destination'  => (string) ($request->fields['destination'] ?? ''),
            'reason'       => (string) ($request->fields['reason'] ?? ''),
            'observations' => (string) ($request->fields['observations'] ?? ''),
            'budget'       => (int) ($request->fields['budgets_id'] ?? 0),
            'currency'     => $currency,
            'total'        => $total,
            'lines'        => $this->lines($items),
        ];
        if ($commercial !== null) {
            $record = array_merge($record, self::commercialRecord($commercial, $currency));
        }
        return $record;
    }

    /**
     * Claves COMMERCIAL_FINANCIAL a partir de los términos de la cotización seleccionada. PURA.
     * FAIL-CLOSED: moneda distinta a la de la solicitud, o términos incompletos ⇒ excepción.
     *
     * @param array{quote_id:int, reference:string, suppliers_id:int, math:array<string,mixed>} $commercial
     * @return array<string,mixed>
     */
    public static function commercialRecord(array $commercial, string $requestCurrency): array
    {
        $math = $commercial['math'] ?? null;
        if (!is_array($math) || (int) ($commercial['quote_id'] ?? 0) <= 0 || (int) ($commercial['suppliers_id'] ?? 0) <= 0) {
            throw new \RuntimeException('términos comerciales incompletos (fail-closed)');
        }
        if ((string) ($math['currency'] ?? '') !== $requestCurrency) {
            throw new \RuntimeException('la moneda de la cotización no coincide con la de la solicitud (fail-closed)');
        }
        foreach (['lines', 'discounts', 'taxes', 'freight', 'total'] as $k) {
            if (!array_key_exists($k, $math)) {
                throw new \RuntimeException("términos comerciales sin '{$k}' (fail-closed)");
            }
        }
        return [
            'suppliers_id_selected' => (int) $commercial['suppliers_id'],
            'selected_quote'        => [
                'id'           => (int) $commercial['quote_id'],
                'reference'    => (string) ($commercial['reference'] ?? ''),
                'suppliers_id' => (int) $commercial['suppliers_id'],
            ],
            'final_prices'          => array_values($math['lines']),
            'discounts'             => (string) $math['discounts'],
            'taxes'                 => (string) $math['taxes'],
            'freight'               => (string) $math['freight'],
            'total'                 => (string) $math['total'],
        ];
    }

    /**
     * @param array<int,RequestItem> $items
     * @return array<int,array<string,mixed>>
     */
    private function lines(array $items): array
    {
        usort($items, static function (RequestItem $a, RequestItem $b): int {
            return [(int) ($a->fields['line_no'] ?? 0), (int) $a->getID()]
                <=> [(int) ($b->fields['line_no'] ?? 0), (int) $b->getID()];
        });
        $out = [];
        foreach ($items as $it) {
            $out[] = [
                'description'      => (string) ($it->fields['description'] ?? ''),
                'category'         => (string) ($it->fields['category'] ?? ''),
                'quantity'         => (string) (int) ($it->fields['quantity'] ?? 0), // entero en v1
                'unit'             => (string) ($it->fields['unit'] ?? ''),
                'is_inventoriable' => (int) ($it->fields['is_inventoriable'] ?? 0),
            ];
        }
        return $out;
    }
}
