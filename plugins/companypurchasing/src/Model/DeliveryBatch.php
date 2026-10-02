<?php

/**
 * Lote/evento de ENTREGA física (tabla propia `glpi_plugin_companypurchasing_delivery_batches`, P2D-4).
 *
 * APPEND-ONLY: actor, destinatario, fecha, notas, entidad, cantidad de unidades e `idempotency_key` de la
 * OPERACIÓN (UNIQUE): reintentar la misma entrega devuelve este mismo lote; la misma clave con otra entrada es un
 * conflicto (`input_sha256`). Se crea sólo dentro de `DeliveryService::deliver()`, en la MISMA transacción que
 * marca sus unidades `DELIVERED`.
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class DeliveryBatch extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_delivery_batches';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Delivery batch', 'Delivery batches', $nb, 'companypurchasing');
    }
}
