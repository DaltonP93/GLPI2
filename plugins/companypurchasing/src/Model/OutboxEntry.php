<?php

/**
 * Handoff de inventario a SI-4 (tabla propia `glpi_plugin_companypurchasing_inventory_outbox`, P2D-3; gate §7).
 *
 * Una fila por unidad INVENTARIABLE, creada en la MISMA transacción que la unidad (`UNIQUE(receipt_unit_uuid)`).
 * El payload es INMUTABLE y versionado (`payload_version`, `payload_json`, `payload_sha256`); sólo mutan los
 * campos de ENTREGA. `companyintegrations` NO lee esta tabla por SQL: consume por
 * `Api\PurchasingIntegrationApi` (claim con lease + ack/retry/error con el mismo `lease_token`).
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class OutboxEntry extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_LEASED  = 'LEASED';
    public const STATUS_RETRY   = 'RETRY';
    public const STATUS_DONE    = 'DONE';
    public const STATUS_ERROR   = 'ERROR';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_inventory_outbox';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Inventory handoff', 'Inventory handoffs', $nb, 'companypurchasing');
    }
}
