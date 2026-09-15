<?php

/**
 * Estado de una definición de workflow (glpi_plugin_companyworkflow_statedefs).
 *
 * Los estados son CONFIGURABLES por definición (no hardcodeados en el motor).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Model;

use CommonDBTM;

class StateDef extends CommonDBTM
{
    public static $rightname = 'plugin_companyworkflow';

    public const KIND_INITIAL      = 'initial';
    public const KIND_INTERMEDIATE = 'intermediate';
    public const KIND_FINAL        = 'final';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyworkflow_statedefs';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow state', 'Workflow states', $nb, 'companyworkflow');
    }

    public function isFinal(): bool
    {
        return ($this->fields['kind'] ?? '') === self::KIND_FINAL;
    }

    public function isEditable(): bool
    {
        return (int) ($this->fields['is_editable'] ?? 0) === 1;
    }
}
