<?php

/**
 * Evento de auditoría de NEGOCIO (tabla propia `glpi_plugin_companypurchasing_events`).
 *
 * APPEND-ONLY: registra la creación y mutaciones relevantes del borrador (y, en fases posteriores,
 * el resto del ciclo). No almacena secretos. Conserva un `correlation_id` para trazabilidad.
 *
 * No sustituye a la auditoría técnica nativa (`Log`) ni al ledger probatorio de `companyworkflow`;
 * es el espejo semántico del dominio de compras.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class PurchasingEvent extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    // Eventos de negocio de P2D-1.
    public const EV_REQUEST_CREATED = 'request.created';
    public const EV_REQUEST_UPDATED = 'request.updated';
    public const EV_LINE_ADDED      = 'line.added';
    public const EV_LINE_UPDATED    = 'line.updated';
    public const EV_LINE_REMOVED    = 'line.removed';
    public const EV_REQUEST_SUBMITTED = 'request.submitted'; // abandona DRAFT: número asignado + scopes pinneados

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_events';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Purchasing event', 'Purchasing events', $nb, 'companypurchasing');
    }
}
