<?php

/**
 * BANDEJAS de Compras (P2D-4; ADR-0023 §7), derivadas del MOTOR — nunca de un `domain_state` hardcodeado:
 *
 *   - "Para mí" (aprobaciones): `WorkflowApi::pendingDecisionsForCurrentUser()` (aprobador EFECTIVO por grupo/perfil +
 *     delegación, voto aún no emitido, derecho y entidad).
 *   - Compras (`RIGHT_MANAGE_PURCHASING`): acciones del motor `start_purchase` / `close` disponibles, o cotización en
 *     curso en un estado que la política PINNEADA de la solicitud habilita (`quote_states`).
 *   - Recepciones pendientes (`RIGHT_RECEIVE`): acción `receive_partial` / `receive_complete` disponible.
 *   - Entregas pendientes (`RIGHT_DELIVER`): acción `deliver_complete` disponible y unidades aún sin entregar.
 *
 * Multi-entidad fail-closed: sólo entidades ACTIVAS de la sesión. Los predicados son los de `RequestUiAccess`: una fila
 * de una bandeja es exactamente una solicitud que la sesión puede ABRIR por lectura contextual (y viceversa). La
 * bandeja "Para mí" no exige VIEW_OWN / VIEW_ENTITY: basta que el MOTOR confirme la decisión pendiente.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\Request;

final class InboxService
{
    public const BOX_APPROVALS  = 'approvals';
    public const BOX_PURCHASING = 'purchasing';
    public const BOX_RECEIVING  = 'receiving';
    public const BOX_DELIVERY   = 'delivery';

    /** Estados finales del motor: nunca son candidatos de una bandeja operativa. */
    private const NOT_OPEN = [PurchasingWorkflow::S_DRAFT, PurchasingWorkflow::S_REJECTED, PurchasingWorkflow::S_CANCELLED, PurchasingWorkflow::S_CLOSED];

    private WorkflowGateway $wf;
    private RequestUiAccess $access;

    public function __construct(?WorkflowGateway $wf = null)
    {
        $this->wf = $wf ?? new WorkflowGateway();
        $this->access = new RequestUiAccess($this->wf);
    }

    /** Bandejas visibles para la sesión (según derechos). @return array<int,string> */
    public static function availableBoxes(): array
    {
        $out = [self::BOX_APPROVALS];
        foreach ([self::BOX_PURCHASING => Request::RIGHT_MANAGE_PURCHASING, self::BOX_RECEIVING => Request::RIGHT_RECEIVE,
                  self::BOX_DELIVERY => Request::RIGHT_DELIVER] as $box => $bit) {
            if (Session::haveRight(Request::$rightname, $bit)) {
                $out[] = $box;
            }
        }
        return $out;
    }

    /**
     * @return array<int,array<string,mixed>> filas de presentación (+ `actions` derivadas del motor)
     * @throws \RuntimeException bandeja sin derecho (fail-closed)
     */
    public function box(string $box): array
    {
        return match ($box) {
            self::BOX_APPROVALS  => $this->approvals(),
            self::BOX_PURCHASING => $this->operational(Request::RIGHT_MANAGE_PURCHASING, fn (Request $r, array $acts, string $st): bool
                => $this->access->purchasingActionable($r, $acts, $st)),
            self::BOX_RECEIVING  => $this->operational(Request::RIGHT_RECEIVE, static fn (Request $r, array $acts, string $st): bool
                => RequestUiAccess::receivingActionable($r, $acts)),
            self::BOX_DELIVERY   => $this->operational(Request::RIGHT_DELIVER, static fn (Request $r, array $acts, string $st): bool
                => RequestUiAccess::deliveryActionable($r, $acts)),
            default              => throw new \RuntimeException('bandeja desconocida'),
        };
    }

    /** @return array<int,array<string,mixed>> */
    private function approvals(): array
    {
        $pending = $this->wf->pendingDecisionsForCurrentUser(Request::class, RequestQuery::MAX_ROWS, PluginConfig::inboxScanCap());
        $byId = [];
        foreach ($pending as $p) {
            $byId[(int) $p['items_id']] = $p['actions'];
        }
        // Lectura CONTEXTUAL (no `canView`): el aprobador efectivo puede no tener VIEW_OWN / VIEW_ENTITY.
        $rows = [];
        foreach ($byId as $id => $actions) {
            $req = new Request();
            if ($id > 0 && $req->getFromDB($id) && $this->access->canRead($req)) {
                $row = RequestQuery::present($req->fields);
                $row['actions'] = $actions;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Recorre solicitudes ABIERTAS de las entidades activas (tope `inbox_scan_cap`) y conserva las que el MOTOR (acciones
     * disponibles de SU versión) y el predicado de la bandeja confirman.
     *
     * @param callable(Request, array<int,string>, string): bool $keep
     * @return array<int,array<string,mixed>>
     */
    private function operational(int $right, callable $keep): array
    {
        if (!Session::haveRight(Request::$rightname, $right)) {
            throw new \RuntimeException('permiso denegado para la bandeja');
        }
        $entities = MetricsService::scopeEntities(null);
        if ($entities === []) {
            return [];
        }
        /** @var \DBmysql $DB */
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'FROM'  => Request::getTable(),
            'WHERE' => ['entities_id' => $entities, 'NOT' => ['domain_state' => self::NOT_OPEN], ['workflow_instances_id' => ['>', 0]]],
            'ORDER' => 'id ASC',
            'LIMIT' => PluginConfig::inboxScanCap(),
        ]) as $r) {
            $req = new Request();
            if (!$req->getFromDB((int) $r['id'])) {
                continue;
            }
            $inst = $this->wf->loadInstance((int) $req->fields['workflow_instances_id']);
            if ($inst === null || !$this->wf->isOpen($inst)) {
                continue;
            }
            $acts = $this->wf->availableActions($inst);
            if ($keep($req, $acts, $this->wf->stateCode($inst))) {
                $row = RequestQuery::present($req->fields);
                $row['actions'] = $acts;
                $rows[] = $row;
            }
            if (count($rows) >= RequestQuery::MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }
}
