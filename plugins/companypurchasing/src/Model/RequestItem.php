<?php

/**
 * Línea de una solicitud de compra (tabla propia `glpi_plugin_companypurchasing_items`).
 *
 * IDENTIDAD = `id` (estable). `line_no` es SÓLO presentación/orden: reordenar cambia `line_no` pero
 * NUNCA la identidad, y jamás se usa como FK lógica (gate §Líneas).
 *
 * Importes exactos (`Money`/`DECIMAL(20,6)` + `currency_code`); cantidad entera positiva para ítems
 * inventariables físicos en v1.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class RequestItem extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_items';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Request line', 'Request lines', $nb, 'companypurchasing');
    }
}
