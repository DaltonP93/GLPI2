<?php

/**
 * UNIDAD física recibida (tabla propia `glpi_plugin_companypurchasing_receipt_units`, P2D-3; gate §5).
 *
 * IDENTIDAD CANÓNICA = `receipt_unit_uuid` (UUID v4 generado con CSPRNG al recibir; inmutable; UNIQUE). Es
 * también la identidad de idempotencia del futuro SI-4. FK lógica = `items_id` (la línea real, NUNCA
 * `line_no`). `unit_index`/`correlation_key` son sólo display/correlación. `unit_cost` es un snapshot exacto
 * e INMUTABLE (política de costo pinneada). `physical_state` es el estado de NEGOCIO de la unidad (no la
 * saga de integración, que es de companyintegrations).
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class ReceiptUnit extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public const PHYSICAL_RECEIVED = 'RECEIVED';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_receipt_units';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Received unit', 'Received units', $nb, 'companypurchasing');
    }
}
