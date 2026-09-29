<?php

/**
 * Estados de la saga SI-4 por unidad (incremento SI4-1, ADR-0020). La saga es de `companyintegrations`; el hecho
 * de negocio (unidad recibida, costo, outbox) es de `companypurchasing`.
 *
 *   PENDING         fila creada al tomar el primer lease; nada remoto todavía
 *   SNIPE_CREATING  intención de creación persistida ANTES del POST (o POST incierto): el próximo intento BUSCA primero
 *   SNIPE_CREATED   activo remoto vinculado (`snipe_asset_id` persistido; `snipe_outcome` = created|reconciled).
 *                   Última etapa implementada en SI4-1: la unidad queda ESTACIONADA (sin ack al outbox).
 *   BLOCKED_CONFIG  mapeo/configuración ausente o rechazada por Snipe → reintento tardío (se destraba al configurar)
 *   MANUAL_REVIEW   conflicto que requiere decisión humana (duplicado, borrado, compañía/serial divergente…)
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class SagaState
{
    public const PENDING        = 'PENDING';
    public const SNIPE_CREATING = 'SNIPE_CREATING';
    public const SNIPE_CREATED  = 'SNIPE_CREATED';
    public const BLOCKED_CONFIG = 'BLOCKED_CONFIG';
    public const MANUAL_REVIEW  = 'MANUAL_REVIEW';

    public const ALL = [self::PENDING, self::SNIPE_CREATING, self::SNIPE_CREATED, self::BLOCKED_CONFIG, self::MANUAL_REVIEW];

    public const OUTCOME_CREATED    = 'created';
    public const OUTCOME_RECONCILED = 'reconciled';
}
