<?php

/**
 * DOBLE DE PRUEBA de `GlpiMappingResolver` (categoría ⇒ [itemtype, modelo GLPI] aprobados). Cada categoría es UNA fila:
 * su id es estable (`id` explícito o derivado de la categoría), así que reemplazar la entrada emula un UPDATE del
 * administrador sobre la misma fila.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class ArrayGlpiMappingResolver implements GlpiMappingResolver
{
    /** @var array<string,array{id?:int, glpi_itemtype:string, glpi_model_id:int}> */
    public array $byCategory;

    /** @param array<string,array{id?:int, glpi_itemtype:string, glpi_model_id:int}> $byCategory */
    public function __construct(array $byCategory)
    {
        $this->byCategory = $byCategory;
    }

    public function resolve(array $payload): array
    {
        $category = (string) ($payload['category'] ?? '');
        $row = $this->byCategory[$category] ?? null;
        if ($row !== null) {
            $row['id'] = (int) ($row['id'] ?? (crc32($category) % 1_000_000 + 1));
        }
        return GlpiMappingRules::decide($category, $row === null ? [] : [$row]);
    }
}
