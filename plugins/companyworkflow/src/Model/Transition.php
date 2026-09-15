<?php

/**
 * Transición permitida entre estados (glpi_plugin_companyworkflow_transitions).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Model;

use CommonDBTM;

class Transition extends CommonDBTM
{
    public static $rightname = 'plugin_companyworkflow';

    // Acciones canónicas del motor (el dominio puede añadir otras vía definición).
    public const ACTION_SUBMIT  = 'submit';
    public const ACTION_APPROVE = 'approve';
    public const ACTION_REJECT  = 'reject';
    public const ACTION_RETURN  = 'return';
    public const ACTION_CANCEL  = 'cancel';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyworkflow_transitions';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow transition', 'Workflow transitions', $nb, 'companyworkflow');
    }
}
