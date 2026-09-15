<?php

/**
 * Fachada PHP del motor para plugins de dominio (companypurchasing y futuros).
 *
 * NO conoce el dominio: recibe la definición (por code/objeto) y el objeto por itemtype/items_id.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Api;

use GlpiPlugin\Companyworkflow\Model\HistoryEvent;
use GlpiPlugin\Companyworkflow\Model\Instance;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;
use GlpiPlugin\Companyworkflow\Service\AuditBridge;
use GlpiPlugin\Companyworkflow\Service\DefinitionBuilder;
use GlpiPlugin\Companyworkflow\Service\Engine;
use GlpiPlugin\Companyworkflow\Service\TransitionResult;

final class WorkflowApi
{
    private Engine $engine;
    private DefinitionBuilder $builder;
    private AuditBridge $audit;

    public function __construct()
    {
        $this->engine  = new Engine();
        $this->builder = new DefinitionBuilder();
        $this->audit   = new AuditBridge();
    }

    /**
     * Crea una instancia en el estado inicial de la definición (conserva la versión de la def).
     */
    public function startInstance(WorkflowDef $def, string $itemtype, int $items_id, int $entities_id, int $is_recursive = 0): ?Instance
    {
        $initial = $this->builder->initialState((int) $def->getID());
        if ($initial === null) {
            return null;
        }
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        $instance = new Instance();
        $id = (int) $instance->add([
            'workflowdefs_id'      => (int) $def->getID(),
            'def_version'          => (int) ($def->fields['version'] ?? 1),
            'itemtype'             => $itemtype,
            'items_id'             => $items_id,
            'entities_id'          => $entities_id,
            'is_recursive'         => $is_recursive,
            'current_statedefs_id' => (int) $initial->getID(),
            'status'               => Instance::STATUS_OPEN,
            'lock_version'         => 0,
            'date_creation'        => $now,
            'date_mod'             => $now,
        ]);
        if ($id <= 0) {
            return null;
        }
        $instance->getFromDB($id);

        $this->audit->record($id, HistoryEvent::EVENT_STARTED, '', (string) $initial->fields['code'], '', false, [
            'workflowdefs_id' => (int) $def->getID(),
            'def_version'     => (int) ($def->fields['version'] ?? 1),
        ]);

        return $instance;
    }

    /** @return array<int,string> */
    public function availableActions(Instance $instance): array
    {
        return $this->engine->availableActions($instance);
    }

    /** @param array<string,mixed> $ctx */
    public function transition(Instance $instance, string $action, array $ctx = []): TransitionResult
    {
        return $this->engine->transition($instance, $action, $ctx);
    }

    public function builder(): DefinitionBuilder
    {
        return $this->builder;
    }
}
