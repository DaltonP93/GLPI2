<?php

/**
 * Alias histórico de asset tags (glpi_plugin_companyintegrations_asset_tag_aliases).
 *
 * La identidad estable del activo puenteado es técnica (`asset_bridge.id`), NO el `asset_tag`.
 * El gateway resuelve el tag ACTUAL y también los HISTÓRICOS → una etiqueta física impresa con un
 * tag viejo sigue resolviendo al mismo activo.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class AssetTagAlias extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_asset_tag_aliases';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Asset tag alias', 'Asset tag aliases', $nb, 'companyintegrations');
    }
}
