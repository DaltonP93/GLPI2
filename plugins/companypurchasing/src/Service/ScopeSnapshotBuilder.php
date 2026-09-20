<?php

/**
 * Constructor DETERMINISTA del snapshot semántico de un scope de aprobación (gate §Approval scopes).
 *
 * En P2D-1 SÓLO construye el payload; NO ejecuta aprobaciones ni llama a `companysignature`. En P2D-2,
 * el payload se entregará a `SignatureApi::recordDocumentVersion()` con el contrato EXACTO que espera
 * (`{ schema, subject_type, subject_id, entity_id, document_version, payload }`). Compras construye el
 * payload SEMÁNTICO; **Firma canonicaliza/hashea** (no se duplica su canonicalización criptográfica).
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
     * @return array<string,mixed>
     */
    public function build(Request $request, array $items, string $scopeKey, ?int $version = null): array
    {
        if ($version === null) {
            $version = (int) ($request->fields['scopes_version'] ?? 0);
            if ($version <= 0) {
                $version = PluginConfig::currentScopesVersion();
            }
        }
        $fields  = ScopeCatalog::fields($version, $scopeKey);
        $full    = $this->fullRecord($request, $items);
        $payload = self::selectFields($full, $fields);

        return [
            'schema'           => self::SCHEMA,
            'subject_type'     => Request::class,
            'subject_id'       => (int) $request->getID(),
            'entity_id'        => (int) ($request->fields['entities_id'] ?? 0),
            // Reservado en P2D-1 (el ciclo de versiones documentales lo maneja Firma en P2D-2).
            'document_version' => 1,
            'scope'            => $scopeKey,
            'scopes_version'   => $version,
            'payload'          => $payload,
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
            if (array_key_exists($key, $full)) {
                $out[$key] = $full[$key];
            }
        }
        ksort($out); // determinismo del orden de claves de nivel superior
        return $out;
    }

    /**
     * Record completo (todas las claves posibles) del cual cada scope SELECCIONA su subconjunto.
     * En P2D-1 no existen aún proveedor/cotización/precios finales seleccionados: esas claves no se
     * emiten (se incorporan en P2D-2). El total estimado se expone como string EXACTO en la moneda.
     *
     * @param array<int,RequestItem> $items
     * @return array<string,mixed>
     */
    private function fullRecord(Request $request, array $items): array
    {
        $currency = (string) ($request->fields['currency_code'] ?? 'PYG');
        $overrides = PluginConfig::currencyScaleOverrides();
        $total = (string) ($request->fields['amount_estimated'] ?? '0');
        try {
            $total = Money::ofStored($total, $currency, $overrides)->amount();
        } catch (\Throwable) {
            // Si el almacenamiento fuese inconsistente, se deja el crudo (no debe ocurrir en P2D-1).
        }

        return [
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
