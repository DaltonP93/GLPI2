<?php

/**
 * Delegación temporal de aprobación (glpi_plugin_companyworkflow_delegations).
 *
 * A delega en B por rango de fechas y alcance (definición/entidad). Queda en auditoría
 * (`delegated_from`). El ApproverResolver incluye al delegado como aprobador válido vigente.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Model;

use CommonDBTM;

class Delegation extends CommonDBTM
{
    public static $rightname = 'plugin_companyworkflow';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyworkflow_delegations';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow delegation', 'Workflow delegations', $nb, 'companyworkflow');
    }
}
