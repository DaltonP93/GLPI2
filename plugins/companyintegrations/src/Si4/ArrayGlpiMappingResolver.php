<?php

/**
 * DOBLE DE PRUEBA de `GlpiMappingResolver` (categoría ⇒ [itemtype, modelo GLPI] aprobados).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class ArrayGlpiMappingResolver implements GlpiMappingResolver
{
    /** @var array<string,array{glpi_itemtype:string, glpi_model_id:int}> */
    public array $byCategory;

    /** @param array<string,array{glpi_itemtype:string, glpi_model_id:int}> $byCategory */
    public function __construct(array $byCategory)
    {
        $this->byCategory = $byCategory;
    }

    public function resolve(array $payload): array
    {
        $category = (string) ($payload['category'] ?? '');
        $row = $this->byCategory[$category] ?? null;
        return GlpiMappingRules::decide($category, $row === null ? [] : [$row]);
    }
}
