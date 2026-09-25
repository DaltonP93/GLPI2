<?php

/**
 * Versión INMUTABLE de la política de aprobación P2D-2 (tabla `glpi_plugin_companypurchasing_policies`).
 *
 * Congela, por solicitud, las reglas que gobiernan su circuito (etapa → scope, scope → checkpoint, etapas
 * con PDF, estados de gestión comercial/enmienda). `UNIQUE(policy_hash)`: el mismo contenido canónico
 * comparte versión; un cambio de configuración produce una versión NUEVA y las solicitudes ya iniciadas
 * conservan la suya (`requests.policies_id`). Append-only: nunca se actualiza ni se borra en uso.
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class PolicyVersion extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_policies';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Approval policy version', 'Approval policy versions', $nb, 'companypurchasing');
    }
}
