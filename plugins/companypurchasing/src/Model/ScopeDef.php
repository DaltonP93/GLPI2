<?php

/**
 * Definición VERSIONADA de un scope de aprobación (tabla `glpi_plugin_companypurchasing_scope_defs`).
 *
 * Un scope agrupa el conjunto de campos que protege una etapa de aprobación (gate §Approval scopes).
 * Es CONFIGURABLE y VERSIONADO: editar la configuración crea una NUEVA versión; una solicitud ya
 * iniciada pinnea la versión vigente al abandonar DRAFT y NO cambia de semántica retroactivamente.
 *
 * `UNIQUE(scopes_version, scope_key)`; `fields_json` = lista ORDENADA de claves de campo del scope.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class ScopeDef extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    /** Claves de scope baseline (gate). */
    public const SCOPE_REQUEST              = 'REQUEST_SCOPE';
    public const SCOPE_COMMERCIAL_FINANCIAL = 'COMMERCIAL_FINANCIAL_SCOPE';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_scope_defs';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Approval scope', 'Approval scopes', $nb, 'companypurchasing');
    }
}
