<?php

/**
 * Mapeo compañía Snipe ↔ entidad GLPI (glpi_plugin_companyintegrations_map_companies).
 *
 * Regla dura multi-entidad: un activo cuya compañía Snipe NO esté mapeada (y aprobada) a una
 * entidad GLPI queda en `company_unmapped`; NUNCA se infiere la entidad.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class MapCompany extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_map_companies';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Company mapping', 'Company mappings', $nb, 'companyintegrations');
    }

    /** Entidad GLPI aprobada para una compañía Snipe, o null si no hay mapeo aprobado. */
    public static function approvedEntityFor(int $snipeCompanyId): ?int
    {
        $m = new self();
        if ($m->getFromDBByCrit(['snipe_company_id' => $snipeCompanyId, 'is_approved' => 1])) {
            return (int) $m->fields['glpi_entity_id'];
        }
        return null;
    }
}
