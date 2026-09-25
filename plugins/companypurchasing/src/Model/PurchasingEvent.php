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

    // Eventos de negocio de P2D-2 (circuito de aprobación; espejo semántico, el ledger probatorio es del motor).
    public const EV_DEFINITION_PUBLISHED = 'workflow.definition_published'; // requests_id = 0
    public const EV_WORKFLOW_STARTED     = 'workflow.started';      // instancia del motor creada/enlazada
    public const EV_WORKFLOW_SUBMITTED   = 'workflow.submitted';    // DRAFT/RETURNED → etapa del jefe
    public const EV_CHECKPOINT_RECORDED  = 'checkpoint.recorded';   // versión documental registrada en Firma
    public const EV_DECISION             = 'approval.decided';      // approve/reject/return confirmado por el motor
    public const EV_SCOPE_INVALIDATED    = 'scope.invalidated';     // cambio de un scope aprobado → reapertura
    public const EV_QUOTE_CREATED        = 'quote.created';
    public const EV_QUOTE_UPDATED        = 'quote.updated';
    public const EV_QUOTE_SELECTED       = 'quote.selected';
    public const EV_QUOTE_ATTACHED       = 'quote.attached';        // Document nativo vinculado a la cotización
    public const EV_LINE_AMENDED         = 'line.amended';          // enmienda post-aprobación (Compras)
    public const EV_PDF_READY            = 'pdf.ready';
    public const EV_PDF_FAILED           = 'pdf.failed';            // el fallo NO revierte workflow/evidencia
    public const EV_STATE_RECONCILED     = 'state.reconciled';      // proyección corregida desde el motor

    // Eventos de negocio de P2D-3 (recepción física + handoff a SI-4).
    public const EV_PURCHASE_STARTED  = 'purchase.started';   // cantidades/costos CONGELADOS + política de costo pinneada
    public const EV_RECEIPT_RECORDED  = 'receipt.recorded';   // lote + unidades + outbox confirmados (misma transacción)
    public const EV_RECEIVING_SYNCED  = 'receiving.synced';   // el motor reflejó los contadores físicos (saga)
    public const EV_RECEIVING_ANOMALY = 'receiving.anomaly';  // motor y contadores no convergibles: se reporta, no se muta
    public const EV_HANDOFF_DONE      = 'handoff.done';       // SI-4 confirmó el procesamiento (lease válido)
    public const EV_HANDOFF_RETRY     = 'handoff.retry';
    public const EV_HANDOFF_ERROR     = 'handoff.error';      // final (requiere intervención)

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_events';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Purchasing event', 'Purchasing events', $nb, 'companypurchasing');
    }
}
