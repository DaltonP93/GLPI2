<?php

/**
 * Secuencia de numeración por (entidad, scope, año) — tabla `glpi_plugin_companypurchasing_numbering`.
 *
 * `UNIQUE(entities_id, scope, year)`. La asignación es TRANSACCIONAL/concurrency-safe (ver
 * `Service/NumberingService`): números distintos por proceso concurrente, sin reutilizar; se aceptan
 * huecos si una reserva legítima falla/cancela (no se reciclan).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class NumberSequence extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_numbering';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Numbering sequence', 'Numbering sequences', $nb, 'companypurchasing');
    }
}
