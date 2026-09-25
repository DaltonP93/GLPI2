<?php

/**
 * Cotización de Compras (tabla `glpi_plugin_companypurchasing_quotes`) — P2D-2.
 *
 * Dominio de Compras: proveedor = `Supplier` NATIVO; adjuntos = `Document` + `Document_Item` NATIVOS
 * (N por cotización, sin duplicar maestros). NO existe `is_selected`: la ÚNICA fuente de verdad de la
 * selección es `requests.quotes_id_selected`. Descuentos/impuestos/flete como DECIMAL exacto; los totales
 * se DERIVAN (`QuoteMath`), no se almacenan.
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class Quote extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_quotes';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Quote', 'Quotes', $nb, 'companypurchasing');
    }
}
