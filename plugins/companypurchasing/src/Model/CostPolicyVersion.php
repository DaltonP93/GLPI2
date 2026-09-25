<?php

/**
 * Versión INMUTABLE de la política de COSTO atribuible (tabla `glpi_plugin_companypurchasing_cost_policies`, P2D-3).
 *
 * `UNIQUE(policy_hash)`: el mismo contenido canónico comparte versión; un cambio de configuración produce una
 * versión NUEVA y las compras ya iniciadas conservan la suya (`requests.cost_policies_id`). Append-only.
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class CostPolicyVersion extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_cost_policies';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Cost policy version', 'Cost policy versions', $nb, 'companypurchasing');
    }
}
