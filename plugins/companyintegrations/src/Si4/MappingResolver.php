<?php

/**
 * Resuelve los IDs de Snipe para una unidad a partir de MAPEOS/CONFIGURACIÓN validados (ADR-0020 §8), nunca de
 * literales: compañía = `map_companies` aprobado para la entidad (exactamente uno), modelo = `map_models` aprobado
 * para la `category` de la línea, estado = `si4_snipe_status_id` (verificado contra Snipe en el preflight).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

interface MappingResolver
{
    /**
     * @param array<string,mixed> $payload payload v1 del handoff
     * @return array{ok:bool, company_id:int, model_id:int, status_id:int, reason:string}
     */
    public function resolve(array $payload): array;
}
