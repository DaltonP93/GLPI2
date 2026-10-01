<?php

/**
 * Mapeo categoría de la línea de compra ↔ tipo de activo GLPI (glpi_plugin_companyintegrations_map_glpi_assettypes).
 *
 * SI4-2 (ADR-0021 §2). Explícito, APROBADO y separado de `map_models` (catálogo de Snipe): la categoría se compara
 * exacta y el modelo GLPI (opcional) se referencia por ID. Sin mapeo aprobado, con un itemtype no soportado o con un
 * modelo inexistente, la unidad queda BLOCKED_CONFIG (nunca se infiere el tipo por texto).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class MapGlpiAssetType extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_map_glpi_assettypes';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('GLPI asset type mapping', 'GLPI asset type mappings', $nb, 'companyintegrations');
    }
}
