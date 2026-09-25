<?php

/**
 * Lote/evento de RECEPCIÓN física (tabla propia `glpi_plugin_companypurchasing_receipt_batches`, P2D-3).
 *
 * APPEND-ONLY: fecha, actor, entidad, política de costo usada y `idempotency_key` de la OPERACIÓN (UNIQUE):
 * reintentar la misma recepción devuelve este mismo lote, nunca otro. Se crea sólo dentro de
 * `ReceivingService::receive()` (transacción atómica con sus unidades y su outbox).
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class ReceiptBatch extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_receipt_batches';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Receipt batch', 'Receipt batches', $nb, 'companypurchasing');
    }
}
