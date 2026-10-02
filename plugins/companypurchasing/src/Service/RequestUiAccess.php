<?php

/**
 * Política EXPLÍCITA de lectura de UNA solicitud en la UI (P2D-4; ADR-0023 §8).
 *
 *   - `VIEW_OWN` / `VIEW_ENTITY` (`RequestManager::canView`) = lectura GENERAL: listados, historial, cualquier estado.
 *   - Acción ACTUAL autorizada = lectura CONTEXTUAL de ESA solicitud, sólo mientras la acción exista:
 *       B) el motor ofrece a la sesión approve / reject / return sobre ESA instancia (`actionsForCurrentUser`);
 *       C) `MANAGE_PURCHASING` y una operación de compras disponible: `start_purchase` del motor, o cotizar antes de
 *          iniciar la compra en un estado que la política PINNEADA habilita (`quote_states`);
 *       D) `RIGHT_RECEIVE` y `receive_partial` / `receive_complete` disponibles (compra iniciada);
 *       E) `RIGHT_DELIVER` y `deliver_complete` disponible con unidades aún sin entregar;
 *       F) `MANAGE_PURCHASING` y `close` disponible.
 *   - SIEMPRE `Session::haveAccessToEntity(entities_id)`.
 *
 * Un derecho operativo NUNCA es un `VIEW_ENTITY` implícito: sin una acción actual sobre ESA solicitud ⇒ denegado. Los
 * predicados son los MISMOS que arman las bandejas (`InboxService`), así que "aparece en mi bandeja" ⇔ "puedo abrirla".
 * `RequestQuery::search()` (búsqueda / historial general) NO usa esta política: sigue exigiendo VIEW_OWN / VIEW_ENTITY.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\ReceiptUnit;
use GlpiPlugin\Companypurchasing\Model\Request;

final class RequestUiAccess
{
    public const VIA_VIEW       = 'view';
    public const VIA_DECISION   = 'decision';
    public const VIA_PURCHASING = 'purchasing';
    public const VIA_RECEIVING  = 'receiving';
    public const VIA_DELIVERY   = 'delivery';

    private WorkflowGateway $wf;
    private PolicyStore $policies;

    public function __construct(?WorkflowGateway $wf = null, ?PolicyStore $policies = null)
    {
        $this->wf = $wf ?? new WorkflowGateway();
        $this->policies = $policies ?? new PolicyStore();
    }

    /**
     * Por qué la sesión puede leer ESTA solicitud en la UI (`VIA_*`), o null si no puede (fail-closed).
     */
    public function readVia(Request $req): ?string
    {
        if (!Session::haveAccessToEntity((int) $req->fields['entities_id'])) {
            return null;
        }
        if ((new RequestManager())->canView($req)) {
            return self::VIA_VIEW;
        }
        $inst = $this->wf->loadInstance((int) ($req->fields['workflow_instances_id'] ?? 0));
        if ($inst === null || !$this->wf->isOpen($inst)) {
            return null;
        }
        try {
            $mine = $this->wf->actionsForCurrentUser($inst);
        } catch (\Throwable) {
            $mine = [];
        }
        if (array_intersect(ApprovalOrchestrator::ACTIONS, $mine) !== []) {
            return self::VIA_DECISION;
        }
        $acts = $this->wf->availableActions($inst);
        $state = $this->wf->stateCode($inst);
        if (self::has(Request::RIGHT_MANAGE_PURCHASING) && $this->purchasingActionable($req, $acts, $state)) {
            return self::VIA_PURCHASING;
        }
        if (self::has(Request::RIGHT_RECEIVE) && self::receivingActionable($req, $acts)) {
            return self::VIA_RECEIVING;
        }
        if (self::has(Request::RIGHT_DELIVER) && self::deliveryActionable($req, $acts)) {
            return self::VIA_DELIVERY;
        }
        return null;
    }

    public function canRead(Request $req): bool
    {
        return $this->readVia($req) !== null;
    }

    /** @throws UiAccessException inexistente (NOT_FOUND) o sin lectura general ni contextual (DENIED) */
    public function getReadable(int $id): Request
    {
        $req = new Request();
        if ($id <= 0 || !$req->getFromDB($id)) {
            throw UiAccessException::notFound();
        }
        if (!$this->canRead($req)) {
            throw UiAccessException::denied();
        }
        return $req;
    }

    // ---------------------------------------------------------------- predicados COMPARTIDOS con las bandejas

    /**
     * C/F) Compras: `start_purchase` o `close` del motor, o cotización en curso (compra no iniciada y el estado del motor
     * es uno donde la política PINNEADA de la solicitud habilita cotizar).
     *
     * @param array<int,string> $acts acciones disponibles de la instancia (motor)
     */
    public function purchasingActionable(Request $req, array $acts, string $state): bool
    {
        if (array_intersect($acts, [PurchasingWorkflow::A_START_PURCHASE, PurchasingWorkflow::A_CLOSE]) !== []) {
            return true;
        }
        if (!empty($req->fields['purchase_started_at'])) {
            return false;
        }
        try {
            return in_array($state, $this->policies->forRequest($req)->quoteStates(), true);
        } catch (\Throwable) {
            return false;
        }
    }

    /** D) Recepción: compra iniciada y `receive_partial` / `receive_complete` disponibles. @param array<int,string> $acts */
    public static function receivingActionable(Request $req, array $acts): bool
    {
        return !empty($req->fields['purchase_started_at'])
            && array_intersect($acts, [PurchasingWorkflow::A_RECEIVE_PARTIAL, PurchasingWorkflow::A_RECEIVE_COMPLETE]) !== [];
    }

    /** E) Entrega: `deliver_complete` disponible y al menos una unidad aún sin entregar. @param array<int,string> $acts */
    public static function deliveryActionable(Request $req, array $acts): bool
    {
        return in_array(PurchasingWorkflow::A_DELIVER_COMPLETE, $acts, true)
            && countElementsInTable(ReceiptUnit::getTable(), ['requests_id' => (int) $req->getID(), 'physical_state' => ReceiptUnit::PHYSICAL_RECEIVED]) > 0;
    }

    private static function has(int $bit): bool
    {
        return (bool) Session::haveRight(Request::$rightname, $bit);
    }
}
