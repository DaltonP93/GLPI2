<?php

/**
 * Puente 1:1 estable Snipe ↔ GLPI (glpi_plugin_companyintegrations_asset_bridge).
 *
 * Mapeo por ID (nunca por nombre). SI-1: se PUEBLA sólo cuando la evidencia es inequívoca;
 * jamás se auto-corrige un conflicto. Es tabla PROPIA: persistirla no modifica Snipe ni el
 * activo core de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class AssetBridge extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public const RIGHT_RECONCILE = 2;  // ejecutar reconciliación (read-only)
    public const RIGHT_MAP       = 4;  // aprobar mapeos (companies/users)
    public const RIGHT_CONFIG    = 8;  // configurar el plugin

    // Estados de sincronización (alineados con las clasificaciones de reconciliación).
    public const STATUS_PENDING         = 'pending';
    public const STATUS_MATCHED         = 'matched';
    public const STATUS_SNIPE_ONLY      = 'snipe_only';
    public const STATUS_GLPI_ONLY       = 'glpi_only';
    public const STATUS_AMBIGUOUS       = 'ambiguous';
    public const STATUS_COMPANY_UNMAPPED = 'company_unmapped';
    public const STATUS_SERIAL_CONFLICT = 'serial_conflict';
    public const STATUS_ERROR           = 'error';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_asset_bridge';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Asset bridge', 'Asset bridges', $nb, 'companyintegrations');
    }

    /**
     * @return array<int,string>
     */
    public function getRights($interface = 'central')
    {
        return [
            READ                  => __('Read'),
            self::RIGHT_RECONCILE => __('Run reconciliation (read-only)', 'companyintegrations'),
            self::RIGHT_MAP       => __('Approve mappings', 'companyintegrations'),
            self::RIGHT_CONFIG    => __('Configure plugin', 'companyintegrations'),
        ];
    }
}
