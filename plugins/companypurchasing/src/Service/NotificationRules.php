<?php

/**
 * Reglas PURAS de notificación (P2D-4; ADR-0023 §9): qué eventos NATIVOS (`NotificationEvent::raiseEvent`) se
 * derivan de un HECHO DURABLE del ledger de companyworkflow (fila `transitioned`: from/to/action) y qué destinos
 * se siembran por defecto. Sin GLPI: unit-testables.
 *
 * Los destinatarios nunca son personas hardcodeadas: solicitante, aprobadores EFECTIVOS de la etapa (motor), actor,
 * destinatario de la entrega + los destinos nativos (grupos/perfiles/administradores) que configure el administrador.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class NotificationRules
{
    public const EV_SUBMITTED          = 'request_submitted';
    public const EV_APPROVAL_PENDING   = 'approval_pending';
    public const EV_APPROVED           = 'request_approved';
    public const EV_REJECTED           = 'request_rejected';
    public const EV_RETURNED           = 'request_returned';
    public const EV_PURCHASE_STARTED   = 'purchase_started';
    public const EV_RECEIPT_COMPLETED  = 'receipt_completed';
    public const EV_READY_FOR_DELIVERY = 'ready_for_delivery';
    public const EV_DELIVERED          = 'request_delivered';
    public const EV_CLOSED             = 'request_closed';

    /** Todos los eventos (orden estable). */
    public const EVENTS = [
        self::EV_SUBMITTED, self::EV_APPROVAL_PENDING, self::EV_APPROVED, self::EV_REJECTED, self::EV_RETURNED,
        self::EV_PURCHASE_STARTED, self::EV_RECEIPT_COMPLETED, self::EV_READY_FOR_DELIVERY, self::EV_DELIVERED, self::EV_CLOSED,
    ];

    // Destinos ESPECÍFICOS del tipo (ids dentro de `Notification::USER_TYPE`; no colisionan con los nativos 1..23).
    public const TARGET_REQUESTER = 4801;
    public const TARGET_APPROVERS = 4802;
    public const TARGET_ACTOR     = 4803;
    public const TARGET_RECIPIENT = 4804;

    /** Destinos sembrados por defecto en `install()` (el administrador puede cambiarlos con la UI nativa). */
    public const DEFAULT_TARGETS = [
        self::EV_SUBMITTED          => [self::TARGET_REQUESTER],
        self::EV_APPROVAL_PENDING   => [self::TARGET_APPROVERS],
        self::EV_APPROVED           => [self::TARGET_REQUESTER],
        self::EV_REJECTED           => [self::TARGET_REQUESTER],
        self::EV_RETURNED           => [self::TARGET_REQUESTER],
        self::EV_PURCHASE_STARTED   => [self::TARGET_REQUESTER],
        self::EV_RECEIPT_COMPLETED  => [self::TARGET_REQUESTER],
        self::EV_READY_FOR_DELIVERY => [self::TARGET_REQUESTER],
        self::EV_DELIVERED          => [self::TARGET_REQUESTER, self::TARGET_RECIPIENT],
        self::EV_CLOSED             => [self::TARGET_REQUESTER],
    ];

    /** Evento del ledger que origina notificaciones (literal de `HistoryEvent::EVENT_TRANSITIONED`). */
    public const LEDGER_TRANSITIONED = 'transitioned';

    /**
     * Eventos nativos derivados de UNA fila del ledger. Sólo transiciones confirmadas; nada para votos, quórum,
     * invalidaciones u otros registros.
     *
     * @return array<int,string>
     */
    public static function eventsFor(string $ledgerEvent, string $from, string $to, string $action): array
    {
        if ($ledgerEvent !== self::LEDGER_TRANSITIONED || $to === '' || $from === $to) {
            return [];
        }
        $out = [];
        if ($action === 'submit') {
            $out[] = self::EV_SUBMITTED;
        }
        if ($action === 'return') {
            $out[] = self::EV_RETURNED;
        }
        if (in_array($to, PurchasingWorkflow::APPROVAL_STAGES, true)) {
            $out[] = self::EV_APPROVAL_PENDING;
        }
        $out = array_merge($out, match ($to) {
            PurchasingWorkflow::S_APPROVED    => [self::EV_APPROVED],
            PurchasingWorkflow::S_REJECTED    => [self::EV_REJECTED],
            PurchasingWorkflow::S_IN_PURCHASE => [self::EV_PURCHASE_STARTED],
            PurchasingWorkflow::S_RECEIVED    => [self::EV_RECEIPT_COMPLETED, self::EV_READY_FOR_DELIVERY],
            PurchasingWorkflow::S_DELIVERED   => [self::EV_DELIVERED],
            PurchasingWorkflow::S_CLOSED      => [self::EV_CLOSED],
            default                           => [],
        });
        return array_values(array_unique($out));
    }

    /** Clave de idempotencia DURABLE: como mucho una notificación por (hecho del ledger, evento). */
    public static function idempotencyKey(int $historyId, string $event): string
    {
        return 'notify:' . $historyId . ':' . $event;
    }
}
