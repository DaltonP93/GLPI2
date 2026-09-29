<?php

/**
 * Mapeo categoría de la línea de compra ↔ modelo Snipe-IT (glpi_plugin_companyintegrations_map_models).
 *
 * Explícito y APROBADO: la categoría se compara exacta y el modelo se referencia por ID (nunca por nombre). Sin mapeo
 * aprobado la unidad queda BLOCKED_CONFIG (no se infiere un modelo).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class MapModel extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_map_models';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Model mapping', 'Model mappings', $nb, 'companyintegrations');
    }
}
