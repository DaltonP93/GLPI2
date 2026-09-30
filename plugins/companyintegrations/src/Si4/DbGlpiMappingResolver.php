<?php

/**
 * `GlpiMappingResolver` de PRODUCCIÓN sobre la tabla PROPIA `map_glpi_assettypes` (ADR-0021 §2).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Model\MapGlpiAssetType;

final class DbGlpiMappingResolver implements GlpiMappingResolver
{
    public function resolve(array $payload): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $category = (string) ($payload['category'] ?? '');
        $rows = [];
        if ($category !== '') {
            foreach ($DB->request([
                'SELECT' => ['category_key', 'glpi_itemtype', 'glpi_model_id'],
                'FROM'   => MapGlpiAssetType::getTable(),
                'WHERE'  => ['category_key' => $category, 'is_approved' => 1],
            ]) as $r) {
                if ((string) $r['category_key'] === $category) { // exacta (la colación de la BD es case-insensitive)
                    $rows[] = ['glpi_itemtype' => (string) $r['glpi_itemtype'], 'glpi_model_id' => (int) $r['glpi_model_id']];
                }
            }
        }
        return GlpiMappingRules::decide($category, $rows);
    }
}
