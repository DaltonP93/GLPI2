<?php

/**
 * Estados de la saga SI-4 por unidad (SI4-1 ADR-0020, SI4-2 ADR-0021, SI4-3 ADR-0022). La saga es de `companyintegrations`; el hecho
 * de negocio (unidad recibida, costo, outbox) es de `companypurchasing`.
 *
 *   PENDING                   fila creada al tomar el primer lease; nada remoto todavía
 *   SNIPE_CREATING            intención de creación persistida ANTES del POST (o POST incierto): el próximo intento BUSCA primero
 *   SNIPE_CREATED             activo remoto vinculado y verificado (`snipe_asset_id`; `snipe_outcome` = created|reconciled)
 *   GLPI_RESOLVED_OR_CREATED  activo GLPI resuelto (vinculado) o creado y verificado (`glpi_itemtype` + `glpi_items_id`)
 *   INFOCOM_READY             Infocom del activo con el costo exacto de la unidad, proveedor y fechas (verificado)
 *   BRIDGED                   `asset_bridge` 1:1 persistido (fin de SI4-2). Con SI4-3 sigue la etapa QR
 *   QR_READY                  código companyqr ACTIVO del activo (`qr_code_id`, `qr_public_code`) y etiqueta renderizable
 *                             (`label_ready_at`); recién aquí se confirma el outbox (`acknowledgeProcessed`, último efecto)
 *   COMPLETED                 outbox DONE (verificado por `getHandoff`): SI-4 completo para la unidad. Sólo lo escribe el
 *                             finalizador (`SagaStore::complete`, guarda `state = QR_READY`), nunca una transición común
 *   BLOCKED_CONFIG            mapeo/configuración/ACL ausente o rechazada → reintento tardío (se destraba al configurar);
 *                             `resume_state` indica la etapa post-Snipe desde la que se reanuda (NULL = desde el inicio;
 *                             BRIDGED = desde la etapa QR de SI4-3)
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
    public const QR_READY                 = 'QR_READY';
    public const COMPLETED                = 'COMPLETED';
    public const BLOCKED_CONFIG           = 'BLOCKED_CONFIG';
    public const MANUAL_REVIEW            = 'MANUAL_REVIEW';

    public const ALL = [
        self::PENDING, self::SNIPE_CREATING, self::SNIPE_CREATED, self::GLPI_RESOLVED_OR_CREATED, self::INFOCOM_READY,
        self::BRIDGED, self::QR_READY, self::COMPLETED, self::BLOCKED_CONFIG, self::MANUAL_REVIEW,
    ];

    /** Etapas posteriores a Snipe desde las que avanza SI4-2 (y valores válidos de `resume_state`). */
    public const POST_SNIPE = [self::SNIPE_CREATED, self::GLPI_RESOLVED_OR_CREATED, self::INFOCOM_READY];

    /** Valores válidos de `resume_state`: las etapas post-Snipe (SI4-2) y BRIDGED (etapa QR de SI4-3). */
    public const RESUMABLE = [self::SNIPE_CREATED, self::GLPI_RESOLVED_OR_CREATED, self::INFOCOM_READY, self::BRIDGED];

    public const OUTCOME_CREATED    = 'created';
    public const OUTCOME_RECONCILED = 'reconciled';
    public const OUTCOME_LINKED     = 'linked';
    public const OUTCOME_UPDATED    = 'updated';
    public const OUTCOME_UNCHANGED  = 'unchanged';
    /** SI4-3: el activo ya tenía su código companyqr (se reutiliza tal cual). */
    public const OUTCOME_EXISTING   = 'existing';
}
