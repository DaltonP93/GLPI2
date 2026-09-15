<?php

/**
 * Etapa/quórum de una transición de aprobación (glpi_plugin_companyworkflow_steps).
 *
 * `approver_kind` NUNCA es un nombre de persona: es group/profile/entity_manager/user (por ID).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Model;

use CommonDBTM;

class Step extends CommonDBTM
{
    public static $rightname = 'plugin_companyworkflow';

    public const QUORUM_COUNT   = 'count';
    public const QUORUM_PERCENT = 'percent';

    public const APPROVER_GROUP          = 'group';
    public const APPROVER_PROFILE        = 'profile';
    public const APPROVER_ENTITY_MANAGER = 'entity_manager';
    public const APPROVER_USER           = 'user';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyworkflow_steps';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow step', 'Workflow steps', $nb, 'companyworkflow');
    }
}
