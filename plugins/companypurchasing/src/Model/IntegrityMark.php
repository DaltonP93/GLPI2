<?php

/**
 * Marca DURABLE de integridad de aprobación (tabla `glpi_plugin_companypurchasing_integrity`).
 *
 * Se escribe en la MISMA transacción local que una mutación sustantiva posterior al envío (selección o
 * precio de la cotización seleccionada, enmienda de cantidad): "el scope X de la solicitud R puede haber
 * quedado distinto de lo aprobado". Mientras exista una marca `dirty`, la solicitud NO se considera
 * íntegramente aprobada aunque el motor diga APPROVED, y toda decisión debe repararla antes (o fallar
 * cerrado). Sólo se resuelve tras una `invalidateApprovals()` CONFIRMADA o tras verificar contra el
 * ledger del motor que no hay aprobación viva distinta del contenido actual. Append-only con resolución.
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class IntegrityMark extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public const STATUS_DIRTY    = 'dirty';
    public const STATUS_RESOLVED = 'resolved';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_integrity';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Approval integrity mark', 'Approval integrity marks', $nb, 'companypurchasing');
    }
}
