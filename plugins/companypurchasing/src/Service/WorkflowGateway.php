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

    /** Ledger append-only de la instancia (orden causal). @return array<int,array<string,mixed>> */
    public function history(int $instanceId): array
    {
        return $instanceId > 0 ? $this->api()->history(['instances_id' => $instanceId]) : [];
    }
}
