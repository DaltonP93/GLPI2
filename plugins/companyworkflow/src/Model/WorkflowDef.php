<?php

/**
 * Definición de workflow VERSIONADA (tabla propia glpi_plugin_companyworkflow_defs).
 *
 * Una definición se identifica por (`code`,`version`). Modificar un workflow crea una
 * NUEVA versión (fila nueva) y desactiva la anterior; las instancias en ejecución siguen
 * apuntando a su fila de versión → una edición administrativa NO cambia silenciosamente
 * solicitudes ya iniciadas (ver docs/architecture/companyworkflow-technical-design.md).
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo glpi_plugin_companyworkflow_).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Model;

use CommonDBTM;

class WorkflowDef extends CommonDBTM
{
    /** Derecho compartido por todo el plugin. */
    public static $rightname = 'plugin_companyworkflow';

    /**
     * Bits del derecho `plugin_companyworkflow`. READ es el bit estándar (1).
     * El resto son bits propios (no colisionan porque el rightname es nuestro).
     */
    public const RIGHT_ACT      = 2;  // ejecutar transiciones (approve/reject/return/...)
    public const RIGHT_ADMIN    = 4;  // administrar definiciones de workflow
    public const RIGHT_DELEGATE = 8;  // gestionar delegaciones
    public const RIGHT_CONFIG   = 16; // configurar el plugin

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyworkflow_defs';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow definition', 'Workflow definitions', $nb, 'companyworkflow');
    }

    /**
     * Bits de derecho disponibles en el formulario de perfiles de GLPI.
     * @return array<int,string>
     */
    public function getRights($interface = 'central')
    {
        return [
            READ                => __('Read'),
            self::RIGHT_ACT     => __('Act on transitions (approve/reject/return)', 'companyworkflow'),
            self::RIGHT_ADMIN   => __('Administer workflow definitions', 'companyworkflow'),
            self::RIGHT_DELEGATE => __('Manage delegations', 'companyworkflow'),
            self::RIGHT_CONFIG  => __('Configure plugin', 'companyworkflow'),
        ];
    }
}
