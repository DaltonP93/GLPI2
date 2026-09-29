<?php

/**
 * `MappingResolver` de PRODUCCIÓN: lee las tablas PROPIAS `map_companies` y `map_models` (sólo filas aprobadas) y el
 * estado configurado. La categoría se compara EXACTA (binaria), sin inferencias por nombre.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Model\MapCompany;
use GlpiPlugin\Companyintegrations\Model\MapModel;

final class DbMappingResolver implements MappingResolver
{
    private int $statusId;

    public function __construct(int $statusId)
    {
        $this->statusId = $statusId;
    }

    public function resolve(array $payload): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $companies = [];
        foreach ($DB->request([
            'SELECT' => ['snipe_company_id'],
            'FROM'   => MapCompany::getTable(),
            'WHERE'  => ['glpi_entity_id' => (int) ($payload['entity_id'] ?? -1), 'is_approved' => 1],
        ]) as $r) {
            $companies[] = (int) $r['snipe_company_id'];
        }
        $category = (string) ($payload['category'] ?? '');
        $model = 0;
        if ($category !== '') {
            foreach ($DB->request([
                'SELECT' => ['category_key', 'snipe_model_id'],
                'FROM'   => MapModel::getTable(),
                'WHERE'  => ['category_key' => $category, 'is_approved' => 1],
            ]) as $r) {
                if ((string) $r['category_key'] === $category) { // comparación exacta (la colación de la BD es case-insensitive)
                    $model = (int) $r['snipe_model_id'];
                }
            }
        }
        return MappingRules::decide($companies, $model, $this->statusId);
    }
}
