<?php

/**
 * Etiquetas i18n (ES/EN) de códigos ESTABLES del dominio: estados del workflow, motivos del gate de inventario,
 * motivos de bloqueo del cierre y tipos de error controlados. Los códigos nunca se muestran crudos al usuario y
 * los textos de negocio nunca se hardcodean en plantillas.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class Labels
{
    public static function state(string $code): string
    {
        return match ($code) {
            PurchasingWorkflow::S_DRAFT              => __('Draft', 'companypurchasing'),
            PurchasingWorkflow::S_PENDING_AREA_HEAD  => __('Pending area head approval', 'companypurchasing'),
            PurchasingWorkflow::S_PURCHASING         => __('In purchasing review', 'companypurchasing'),
            PurchasingWorkflow::S_PENDING_FINANCE    => __('Pending finance approval', 'companypurchasing'),
            PurchasingWorkflow::S_APPROVED           => __('Approved', 'companypurchasing'),
            PurchasingWorkflow::S_RETURNED           => __('Returned', 'companypurchasing'),
            PurchasingWorkflow::S_REJECTED           => __('Rejected', 'companypurchasing'),
            PurchasingWorkflow::S_CANCELLED          => __('Cancelled', 'companypurchasing'),
            PurchasingWorkflow::S_IN_PURCHASE        => __('In purchase', 'companypurchasing'),
            PurchasingWorkflow::S_PARTIALLY_RECEIVED => __('Partially received', 'companypurchasing'),
            PurchasingWorkflow::S_RECEIVED           => __('Received', 'companypurchasing'),
            PurchasingWorkflow::S_DELIVERED          => __('Delivered', 'companypurchasing'),
            PurchasingWorkflow::S_CLOSED             => __('Closed', 'companypurchasing'),
            'PENDING'                                => __('Submitted', 'companypurchasing'),
            default                                  => __('Unknown state', 'companypurchasing'),
        };
    }

    public static function phase(string $phase): string
    {
        return match ($phase) {
            PurchasingWorkflow::PHASE_REQUEST   => __('Request', 'companypurchasing'),
            PurchasingWorkflow::PHASE_APPROVAL  => __('Approval', 'companypurchasing'),
            PurchasingWorkflow::PHASE_PURCHASE  => __('Purchase', 'companypurchasing'),
            PurchasingWorkflow::PHASE_RECEPTION => __('Reception', 'companypurchasing'),
            PurchasingWorkflow::PHASE_DELIVERY  => __('Delivery', 'companypurchasing'),
            default                             => __('Finished', 'companypurchasing'),
        };
    }

    public static function action(string $action): string
    {
        return match ($action) {
            'submit'  => __('Submit', 'companypurchasing'),
            'approve' => __('Approve', 'companypurchasing'),
            'reject'  => __('Reject', 'companypurchasing'),
            'return'  => __('Return', 'companypurchasing'),
            'cancel'  => __('Cancel request', 'companypurchasing'),
            default   => __('Action', 'companypurchasing'),
        };
    }

    public static function gate(string $gate): string
    {
        return match ($gate) {
            DeliveryRules::GATE_DELIVERABLE             => __('Ready to deliver', 'companypurchasing'),
            DeliveryRules::GATE_INVENTORY_PENDING       => __('Inventory pending', 'companypurchasing'),
            DeliveryRules::GATE_INTEGRATION_IN_PROGRESS => __('Integration in progress', 'companypurchasing'),
            DeliveryRules::GATE_INTEGRATION_ERROR       => __('Integration error', 'companypurchasing'),
            DeliveryRules::GATE_ALREADY_DELIVERED       => __('Delivered', 'companypurchasing'),
            default                                     => __('Not deliverable', 'companypurchasing'),
        };
    }

    public static function closeBlocker(string $code): string
    {
        return match ($code) {
            DeliveryRules::BLOCK_STATE               => __('The request has not been fully delivered yet', 'companypurchasing'),
            DeliveryRules::BLOCK_LEGACY              => __('The request uses a previous workflow version without delivery/close phase', 'companypurchasing'),
            DeliveryRules::BLOCK_NO_UNITS            => __('No physical units were received', 'companypurchasing'),
            DeliveryRules::BLOCK_RECEIPT_PENDING     => __('There are units pending reception', 'companypurchasing'),
            DeliveryRules::BLOCK_UNITS_NOT_DELIVERED => __('There are units pending delivery', 'companypurchasing'),
            DeliveryRules::BLOCK_INVENTORY_NOT_DONE  => __('Inventory registration is not confirmed for every inventoriable unit', 'companypurchasing'),
            DeliveryRules::BLOCK_INTEGRITY           => __('Approval integrity must be repaired first', 'companypurchasing'),
            default                                  => __('The request is not ready to be closed', 'companypurchasing'),
        };
    }

    /** Mensaje FUNCIONAL (sin detalles técnicos) de un rechazo controlado de entrega/cierre. */
    public static function deliveryError(string $kind): string
    {
        return match ($kind) {
            DeliveryException::ACL                  => __('You do not have the right to perform this action', 'companypurchasing'),
            DeliveryException::ENTITY               => __('You do not have access to the entity of this request', 'companypurchasing'),
            DeliveryException::INVALID              => __('The submitted data is invalid', 'companypurchasing'),
            DeliveryException::STATE                => __('The request is not in a state that allows this action', 'companypurchasing'),
            DeliveryException::LEGACY_DEFINITION    => __('The request uses a previous workflow version without delivery/close phase', 'companypurchasing'),
            DeliveryException::RECIPIENT            => __('The recipient is not valid for the entity of this request', 'companypurchasing'),
            DeliveryException::UNIT_NOT_DELIVERABLE => __('One or more units were already delivered or are not deliverable', 'companypurchasing'),
            DeliveryException::INVENTORY_GATE       => __('One or more inventoriable units are not registered in inventory yet', 'companypurchasing'),
            DeliveryException::IDEMPOTENCY_CONFLICT => __('This form was already submitted with different data; reload the page', 'companypurchasing'),
            DeliveryException::CONCURRENCY_CONFLICT => __('Another user changed this request at the same time; reload and try again', 'companypurchasing'),
            DeliveryException::NOT_READY_TO_CLOSE   => __('The request is not ready to be closed', 'companypurchasing'),
            default                                 => __('The action could not be completed', 'companypurchasing'),
        };
    }

    public static function outboxStatus(?string $status): string
    {
        return match ($status) {
            null      => __('Not inventoriable', 'companypurchasing'),
            'PENDING' => __('Inventory pending', 'companypurchasing'),
            'RETRY'   => __('Inventory pending', 'companypurchasing'),
            'LEASED'  => __('Integration in progress', 'companypurchasing'),
            'DONE'    => __('Registered in inventory', 'companypurchasing'),
            'ERROR'   => __('Integration error', 'companypurchasing'),
            default   => __('Integration error', 'companypurchasing'),
        };
    }

    public static function integrationPhase(?string $phase): string
    {
        return match ($phase) {
            'completed'   => __('Registered in inventory', 'companypurchasing'),
            'in_progress' => __('Integration in progress', 'companypurchasing'),
            'attention'   => __('Integration requires attention', 'companypurchasing'),
            'pending'     => __('Inventory pending', 'companypurchasing'),
            default       => __('No integration data', 'companypurchasing'),
        };
    }

    /** Evento del ledger del motor (historial visible). */
    public static function historyEvent(string $event): string
    {
        return match ($event) {
            'started'              => __('Workflow started', 'companypurchasing'),
            'transitioned'         => __('State changed', 'companypurchasing'),
            'decision_recorded'    => __('Decision recorded', 'companypurchasing'),
            'quorum_reached'       => __('Quorum reached', 'companypurchasing'),
            'approval_invalidated' => __('Approvals invalidated', 'companypurchasing'),
            'rejected'             => __('Rejected', 'companypurchasing'),
            'returned'             => __('Returned', 'companypurchasing'),
            'cancelled'            => __('Cancelled', 'companypurchasing'),
            default                => __('Workflow event', 'companypurchasing'),
        };
    }

    /** Evento de la auditoría de negocio (sin su detalle técnico). */
    public static function auditEvent(string $event): string
    {
        return match (explode('.', $event)[0]) {
            'request'      => __('Request', 'companypurchasing'),
            'line'         => __('Request line', 'companypurchasing'),
            'workflow'     => __('Workflow', 'companypurchasing'),
            'checkpoint'   => __('Approval evidence', 'companypurchasing'),
            'approval'     => __('Approval decision', 'companypurchasing'),
            'scope'        => __('Approval integrity', 'companypurchasing'),
            'quote'        => __('Quote', 'companypurchasing'),
            'pdf'          => __('Approved PDF', 'companypurchasing'),
            'state'        => __('State reconciliation', 'companypurchasing'),
            'purchase'     => __('Purchase', 'companypurchasing'),
            'receipt', 'receiving' => __('Reception', 'companypurchasing'),
            'handoff'      => __('Inventory integration', 'companypurchasing'),
            'delivery'     => __('Delivery', 'companypurchasing'),
            'notification' => __('Notification', 'companypurchasing'),
            default        => __('Event', 'companypurchasing'),
        } . ' · ' . $event;
    }

    public static function physical(string $state): string
    {
        return $state === DeliveryRules::PHYSICAL_DELIVERED ? __('Delivered', 'companypurchasing') : __('Received', 'companypurchasing');
    }

    public static function notificationEvent(string $event): string
    {
        return match ($event) {
            NotificationRules::EV_SUBMITTED          => __('Purchase request submitted', 'companypurchasing'),
            NotificationRules::EV_APPROVAL_PENDING   => __('Purchase request pending approval', 'companypurchasing'),
            NotificationRules::EV_APPROVED           => __('Purchase request approved', 'companypurchasing'),
            NotificationRules::EV_REJECTED           => __('Purchase request rejected', 'companypurchasing'),
            NotificationRules::EV_RETURNED           => __('Purchase request returned', 'companypurchasing'),
            NotificationRules::EV_PURCHASE_STARTED   => __('Purchase started', 'companypurchasing'),
            NotificationRules::EV_RECEIPT_COMPLETED  => __('Reception completed', 'companypurchasing'),
            NotificationRules::EV_READY_FOR_DELIVERY => __('Ready for delivery', 'companypurchasing'),
            NotificationRules::EV_DELIVERED          => __('Purchase request delivered', 'companypurchasing'),
            NotificationRules::EV_CLOSED             => __('Purchase request closed', 'companypurchasing'),
            default                                  => __('Purchase request update', 'companypurchasing'),
        };
    }
}
