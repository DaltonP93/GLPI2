<?php

/**
 * Resultado de reconciliación (glpi_plugin_companyintegrations_recon), append-only.
 *
 * Registra la clasificación de cada activo Snipe cruzado con GLPI. En SI-1 NUNCA se auto-corrige
 * un conflicto: se observa y se reporta.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class ReconResult extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_recon';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Reconciliation result', 'Reconciliation results', $nb, 'companyintegrations');
    }
}
