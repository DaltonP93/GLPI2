<?php

/**
 * Estados de la saga SI-4 por unidad (SI4-1 ADR-0020, SI4-2 ADR-0021). La saga es de `companyintegrations`; el hecho
 * de negocio (unidad recibida, costo, outbox) es de `companypurchasing`.
 *
 *   PENDING                   fila creada al tomar el primer lease; nada remoto todavía
 *   SNIPE_CREATING            intención de creación persistida ANTES del POST (o POST incierto): el próximo intento BUSCA primero
 *   SNIPE_CREATED             activo remoto vinculado y verificado (`snipe_asset_id`; `snipe_outcome` = created|reconciled)
 *   GLPI_RESOLVED_OR_CREATED  activo GLPI resuelto (vinculado) o creado y verificado (`glpi_itemtype` + `glpi_items_id`)
 *   INFOCOM_READY             Infocom del activo con el costo exacto de la unidad, proveedor y fechas (verificado)
 *   BRIDGED                   `asset_bridge` 1:1 persistido. Última etapa de SI4-2: la unidad queda ESTACIONADA
 *                             (sin ack al outbox: companyqr/etiqueta son SI4-3)
 *   BLOCKED_CONFIG            mapeo/configuración/ACL ausente o rechazada → reintento tardío (se destraba al configurar);
 *                             `resume_state` indica la etapa post-Snipe desde la que se reanuda (NULL = desde el inicio)
 *   MANUAL_REVIEW             conflicto que requiere decisión humana (duplicado, ambiguo, otra entidad, costo no
 *                             representable…)
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class SagaState
{
    public const PENDING                  = 'PENDING';
    public const SNIPE_CREATING           = 'SNIPE_CREATING';
    public const SNIPE_CREATED            = 'SNIPE_CREATED';
    public const GLPI_RESOLVED_OR_CREATED = 'GLPI_RESOLVED_OR_CREATED';
    public const INFOCOM_READY            = 'INFOCOM_READY';
    public const BRIDGED                  = 'BRIDGED';
    public const BLOCKED_CONFIG           = 'BLOCKED_CONFIG';
    public const MANUAL_REVIEW            = 'MANUAL_REVIEW';

    public const ALL = [
        self::PENDING, self::SNIPE_CREATING, self::SNIPE_CREATED, self::GLPI_RESOLVED_OR_CREATED, self::INFOCOM_READY,
        self::BRIDGED, self::BLOCKED_CONFIG, self::MANUAL_REVIEW,
    ];

    /** Etapas posteriores a Snipe desde las que avanza SI4-2 (y valores válidos de `resume_state`). */
    public const POST_SNIPE = [self::SNIPE_CREATED, self::GLPI_RESOLVED_OR_CREATED, self::INFOCOM_READY];

    public const OUTCOME_CREATED    = 'created';
    public const OUTCOME_RECONCILED = 'reconciled';
    public const OUTCOME_LINKED     = 'linked';
    public const OUTCOME_UPDATED    = 'updated';
    public const OUTCOME_UNCHANGED  = 'unchanged';
}
