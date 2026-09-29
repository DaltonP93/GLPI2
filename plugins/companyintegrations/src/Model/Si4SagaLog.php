<?php

/**
 * Bitácora APPEND-ONLY de la saga SI-4 (glpi_plugin_companyintegrations_si4_saga_log): cada toma de lease y cada
 * transición (con motivo saneado). Auditoría de integración; nunca se actualiza ni se borra desde el código.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class Si4SagaLog extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_si4_saga_log';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Inventory saga event', 'Inventory saga events', $nb, 'companyintegrations');
    }
}
