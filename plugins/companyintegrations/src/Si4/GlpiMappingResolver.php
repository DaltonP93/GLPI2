<?php

/**
 * Resuelve el destino GLPI de una unidad: categoría de la línea ⇒ itemtype + modelo GLPI opcional (SI4-2, ADR-0021 §2).
 * Sólo mapeos APROBADOS de `map_glpi_assettypes` (comparación exacta); ausente ⇒ BLOCKED_CONFIG. Se consulta SÓLO en el
 * primer uso de SI4-2 de cada saga: el resultado queda PINNEADO en la saga (`GlpiMappingRules::PIN_COLUMNS`).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

interface GlpiMappingResolver
{
    /**
     * @param array<string,mixed> $payload handoff v1
     * @return array{ok:bool, mapping_id:int, itemtype:string, model_id:int, reason:string}
     */
    public function resolve(array $payload): array;
}
