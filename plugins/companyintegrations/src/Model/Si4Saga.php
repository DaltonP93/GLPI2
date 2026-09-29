<?php

/**
 * Saga SI-4 por unidad recibida (glpi_plugin_companyintegrations_si4_sagas). Estado de INTEGRACIÓN (dueño:
 * companyintegrations), ligado al hecho de negocio de Compras por `receipt_unit_uuid` (ADR-0020). Se escribe sólo a
 * través de `DbSagaStore` (fencing por lease).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Model;

use CommonDBTM;

class Si4Saga extends CommonDBTM
{
    public static $rightname = 'plugin_companyintegrations';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyintegrations_si4_sagas';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Inventory saga (SI-4)', 'Inventory sagas (SI-4)', $nb, 'companyintegrations');
    }
}
