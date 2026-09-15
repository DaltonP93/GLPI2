<?php

/**
 * Voto de aprobación por instancia+etapa (glpi_plugin_companyworkflow_assignments).
 *
 * La clave UNIQUE (instances_id, statedefs_id, users_id) impide DOBLE aprobación del mismo
 * usuario en la misma etapa (idempotencia ante reintento/replay).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Model;

use CommonDBTM;

class Assignment extends CommonDBTM
{
    public static $rightname = 'plugin_companyworkflow';

    public const DECISION_PENDING  = 'pending';
    public const DECISION_APPROVED = 'approved';
    public const DECISION_REJECTED = 'rejected';
    public const DECISION_RETURNED = 'returned';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyworkflow_assignments';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow assignment', 'Workflow assignments', $nb, 'companyworkflow');
    }
}
