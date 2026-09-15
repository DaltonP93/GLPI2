<?php

/**
 * Mapeo usuario Snipe ↔ usuario GLPI (glpi_plugin_companyintegrations_map_users).
 *
 * La identidad corporativa la define GLPI/IdP (Snipe es dueño de la CUSTODIA, no de la identidad).
 * El mapeo es explícito y aprobado; NUNCA se correlacionan usuarios por nombre/email inferido.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class MapUser extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_map_users';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('User mapping', 'User mappings', $nb, 'companyintegrations');
    }

    public static function approvedGlpiUserFor(int $snipeUserId): ?int
    {
        $m = new self();
        if ($m->getFromDBByCrit(['snipe_user_id' => $snipeUserId, 'is_approved' => 1])) {
            return (int) $m->fields['glpi_users_id'];
        }
        return null;
    }
}
