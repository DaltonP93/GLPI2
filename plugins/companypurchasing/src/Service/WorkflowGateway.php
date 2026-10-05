<?php

/**
 * Puerta ÚNICA de companypurchasing hacia `companyworkflow` (P2D-2).
 *
 * - Escrituras SÓLO vía `WorkflowApi` (startInstance/transition/invalidateApprovals/createVersion): el
 *   motor es la autoridad (estados, aprobadores, quórum, delegación, SLA, lock_version, auditoría).
 * - Lecturas vía `WorkflowApi::history()` y los MODELOS del motor (`Instance`/`StateDef`) por su interfaz
 *   CommonDBTM (sin SQL directo a sus tablas).
 * - Fail-closed si el plugin no está disponible.
 *
 * No es `final`: los tests deterministas de caída/recuperación la subclasean.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companyworkflow\Api\WorkflowApi;
use GlpiPlugin\Companyworkflow\Model\Instance;
use GlpiPlugin\Companyworkflow\Model\StateDef;
use GlpiPlugin\Companyworkflow\Model\Transition;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;
use GlpiPlugin\Companyworkflow\Service\TransitionResult;

class WorkflowGateway
{
    private ?WorkflowApi $api = null;

    public function available(): bool
    {
        return class_exists(WorkflowApi::class) && class_exists(Instance::class);
    }

    protected function api(): WorkflowApi
    {
        if (!$this->available()) {
            throw new \RuntimeException('companyworkflow no disponible (fail-closed)');
        }
        return $this->api ??= new WorkflowApi();
    }

    public function activeDefinition(string $code): ?WorkflowDef
    {
        return $this->api()->builder()->activeByCode($code);
    }

    /** @param array<string,mixed> $spec */
    public function publish(array $spec): WorkflowDef
    {
        return $this->api()->builder()->createVersion($spec);
    }

    public function startInstance(WorkflowDef $def, string $itemtype, int $itemsId, int $entitiesId): Instance
    {
        $inst = $this->api()->startInstance($def, $itemtype, $itemsId, $entitiesId, 0);
        if ($inst === null) {
            throw new \RuntimeException('no se pudo iniciar la instancia de workflow');
        }
        return $inst;
    }

    /** Instancia existente de un objeto de dominio (`UNIQUE(itemtype, items_id)` en el motor). */
    public function findInstance(string $itemtype, int $itemsId): ?Instance
    {
        if (!$this->available() || $itemsId <= 0) {
            return null;
        }
        $inst = new Instance();
        return $inst->getFromDBByCrit(['itemtype' => $itemtype, 'items_id' => $itemsId]) ? $inst : null;
    }

    public function loadInstance(int $instanceId): ?Instance
    {
        if (!$this->available() || $instanceId <= 0) {
            return null;
        }
        $inst = new Instance();
        return $inst->getFromDB($instanceId) ? $inst : null;
    }

    public function stateCode(Instance $inst): string
    {
        $sd = new StateDef();
        return $sd->getFromDB((int) $inst->fields['current_statedefs_id']) ? (string) $sd->fields['code'] : '';
    }

    /** AUTORIDAD de editabilidad: `is_editable` del estado ACTUAL en el motor (instancia abierta). */
    public function isEditable(Instance $inst): bool
    {
        if (!$inst->isOpen()) {
            return false;
        }
        $sd = new StateDef();
        return $sd->getFromDB((int) $inst->fields['current_statedefs_id']) && (int) ($sd->fields['is_editable'] ?? 0) === 1;
    }

    public function isOpen(Instance $inst): bool
    {
        return $inst->isOpen();
    }

    /** @param array<string,mixed> $ctx */
    public function transition(Instance $inst, string $action, array $ctx): TransitionResult
    {
        return $this->api()->transition($inst, $action, $ctx);
    }

    /** @param array<string,mixed> $context */
    public function invalidate(int $instanceId, string $reason, array $context, ?int $expectedVersion): TransitionResult
    {
        return $this->api()->invalidateApprovals($instanceId, $reason, $context, $expectedVersion);
    }

    /** Acciones disponibles desde el estado ACTUAL de la instancia (definición de SU versión). @return array<int,string> */
    public function availableActions(Instance $inst): array
    {
        return $this->api()->availableActions($inst);
    }

    /**
     * ¿La VERSIÓN de definición de la instancia declara la acción `$action`? (P2D-3: detectar instancias
     * iniciadas bajo una versión anterior, que conservan su versión y no conocen la fase de compra.) Lectura
     * por la interfaz CommonDBTM del modelo del motor (sin SQL directo a sus tablas).
     */
    public function definitionHasAction(Instance $inst, string $action): bool
    {
        if (!$this->available() || !class_exists(Transition::class)) {
            return false;
        }
        $defId = (int) ($inst->fields['workflowdefs_id'] ?? 0);
        return $defId > 0 && (new Transition())->find(['workflowdefs_id' => $defId, 'action' => $action], [], 1) !== [];
    }

    /** Ledger append-only de la instancia (orden causal). @return array<int,array<string,mixed>> */
    public function history(int $instanceId): array
    {
        return $instanceId > 0 ? $this->api()->history(['instances_id' => $instanceId]) : [];
    }

    // ---------------------------------------------------------------- P2D-4: lectura para bandejas/notificaciones/métricas
    // (API de SÓLO LECTURA de companyworkflow >= 0.6.0; fail-closed si el motor instalado no la ofrece).

    /** Acciones que la sesión ACTUAL puede ejecutar ahora (ACL + entidad + aprobador efectivo + voto). @return array<int,string> */
    public function actionsForCurrentUser(Instance $inst): array
    {
        return $this->apiWith('actionsForCurrentUser')->actionsForCurrentUser($inst);
    }

    /** @return array<int,array{instances_id:int, items_id:int, entities_id:int, state_code:string, actions:array<int,string>}> */
    public function pendingDecisionsForCurrentUser(string $itemtype, int $limit, int $scanCap): array
    {
        return $this->apiWith('pendingDecisionsForCurrentUser')->pendingDecisionsForCurrentUser($itemtype, $limit, $scanCap);
    }

    /** Aprobadores EFECTIVOS de la etapa actual. @return array<int,int> */
    public function currentApprovers(Instance $inst): array
    {
        return $this->apiWith('currentApprovers')->currentApprovers($inst);
    }

    /** @return array<string,mixed>|null */
    public function historyById(int $historyId): ?array
    {
        return $historyId > 0 ? $this->api()->historyById($historyId) : null;
    }

    /** Filas del ledger posteriores a `$sinceId` (orden causal). @param array<int,string> $events @return array<int,array<string,mixed>> */
    public function historySince(int $sinceId, array $events, int $limit): array
    {
        return $this->api()->history(['since_id' => max(0, $sinceId), 'events' => $events, 'limit' => max(1, $limit)]);
    }

    /** Ledger de VARIAS instancias (métricas por etapa). @param array<int,int> $instanceIds @param array<int,string> $events @return array<int,array<string,mixed>> */
    public function historyFor(array $instanceIds, array $events): array
    {
        $ids = array_values(array_filter(array_map('intval', $instanceIds), static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }
        $this->apiWith('lastHistoryId');
        return $this->api()->history(['instances_ids' => $ids, 'events' => $events]);
    }

    private function apiWith(string $method): WorkflowApi
    {
        $api = $this->api();
        if (!method_exists($api, $method)) {
            throw new \RuntimeException("companyworkflow >= 0.6.0 requerido ({$method}) (fail-closed)");
        }
        return $api;
    }
}
