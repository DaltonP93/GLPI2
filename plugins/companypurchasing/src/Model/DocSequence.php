<?php

/**
 * Contador de versiones documentales por solicitud (tabla `glpi_plugin_companypurchasing_docseq`).
 *
 * `UNIQUE(requests_id)`; `next_version` sólo crece (incremento atómico bajo bloqueo de fila). Una versión
 * asignada jamás se recicla: si algo falla después, queda un hueco (aceptado).
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class DocSequence extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_docseq';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Document version sequence', 'Document version sequences', $nb, 'companypurchasing');
    }
}
