<?php

/**
 * Regla PURA de resolución de mapeos (compartida por los resolvers de BD y de memoria). Fail-closed: cualquier
 * ausencia o ambigüedad ⇒ `ok = false` con un motivo legible (la saga queda BLOCKED_CONFIG).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class MappingRules
{
    /**
     * @param array<int,int> $approvedCompanies compañías Snipe aprobadas para la entidad de la unidad
     * @return array{ok:bool, company_id:int, model_id:int, status_id:int, reason:string}
     */
    public static function decide(array $approvedCompanies, int $modelId, int $statusId): array
    {
        $companies = array_values(array_unique(array_filter(array_map('intval', $approvedCompanies), static fn (int $c): bool => $c > 0)));
        $reason = '';
        if ($companies === []) {
            $reason = 'mapping ausente: entidad sin compañía Snipe aprobada (map_companies)';
        } elseif (count($companies) > 1) {
            $reason = 'mapping ambiguo: la entidad tiene más de una compañía Snipe aprobada';
        } elseif ($modelId <= 0) {
            $reason = 'mapping ausente: categoría sin modelo Snipe aprobado (map_models)';
        } elseif ($statusId <= 0) {
            $reason = 'configuración ausente: si4_snipe_status_id';
        }
        return [
            'ok'         => $reason === '',
            'company_id' => count($companies) === 1 ? $companies[0] : 0,
            'model_id'   => max(0, $modelId),
            'status_id'  => max(0, $statusId),
            'reason'     => $reason,
        ];
    }
}
