<?php

/**
 * Precio final por línea de una cotización (tabla `glpi_plugin_companypurchasing_quote_items`) — P2D-2.
 *
 * Identidad de línea = `items_id` → `..._items.id` (NUNCA `line_no`, que es sólo orden).
 * `UNIQUE(quotes_id, items_id)`: una línea se cotiza a lo sumo una vez por cotización. El total de línea
 * se DERIVA (`final_unit_price × quantity`), no se almacena.
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class QuoteItem extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_quote_items';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Quote line', 'Quote lines', $nb, 'companypurchasing');
    }
}
