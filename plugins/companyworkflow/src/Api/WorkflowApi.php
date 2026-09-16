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

use CommonDBTM;
use Session;
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

    public function __construct(?Engine $engine = null)
    {
        $this->engine  = $engine ?? new Engine();
        $this->builder = new DefinitionBuilder();
        $this->audit   = new AuditBridge();
    }

    /**
     * Crea una instancia en el estado inicial de la definición, FAIL-CLOSED y TRANSACCIONAL:
     * valida definición activa/versionada, `itemtype_target`, existencia del objeto de dominio,
     * coherencia de entidad y ACL; luego crea instancia + evento inicial en una sola transacción.
     *
     * @throws \InvalidArgumentException  validación fallida (sin escrituras)
     * @throws \RuntimeException          fallo de persistencia (con rollback)
     */
    public function startInstance(WorkflowDef $def, string $itemtype, int $items_id, int $entities_id, int $is_recursive = 0): ?Instance
    {
        /** @var \DBmysql $DB */
        global $DB;

        // --- Validación de la definición (activa/versionada, con estado inicial) ---
        $defId = (int) $def->getID();
        if ($defId <= 0 || (int) ($def->fields['is_active'] ?? 0) !== 1) {
            throw new \InvalidArgumentException('la definición no está activa/versionada');
        }
        $initial = $this->builder->initialState($defId);
        if ($initial === null) {
            throw new \InvalidArgumentException('la definición no tiene estado inicial');
        }

        // --- itemtype_target (si la definición lo fija, debe coincidir) ---
        $target = (string) ($def->fields['itemtype_target'] ?? '');
        if ($target !== '' && $target !== $itemtype) {
            throw new \InvalidArgumentException("itemtype '{$itemtype}' no coincide con el objetivo '{$target}'");
        }

        // --- Existencia del objeto de dominio ---
        if (!class_exists($itemtype) || !is_subclass_of($itemtype, CommonDBTM::class)) {
            throw new \InvalidArgumentException("itemtype inválido: '{$itemtype}'");
        }
        /** @var CommonDBTM $obj */
        $obj = new $itemtype();
        if ($items_id <= 0 || !$obj->getFromDB($items_id)) {
            throw new \InvalidArgumentException('el objeto de dominio no existe');
        }

        // --- Coherencia de entidad + ACL ---
        if (isset($obj->fields['entities_id']) && (int) $obj->fields['entities_id'] !== $entities_id) {
            throw new \InvalidArgumentException('la entidad no coincide con la del objeto de dominio');
        }
        if (!Session::haveAccessToEntity($entities_id, (bool) $is_recursive)) {
            throw new \InvalidArgumentException('sin acceso a la entidad indicada');
        }

        // --- Creación + evento inicial TRANSACCIONAL ---
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->beginTransaction();
        try {
            $instance = new Instance();
            $id = (int) $instance->add([
                'workflowdefs_id'      => $defId,
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
                // UNIQUE(itemtype, items_id): ya existe una instancia para este objeto.
                $this->safeRollback($DB);
                throw new \RuntimeException('ya existe una instancia para este objeto (o fallo al crear)');
            }

            $this->audit->record($id, HistoryEvent::EVENT_STARTED, '', (string) $initial->fields['code'], '', false, [
                'workflowdefs_id' => $defId,
                'def_version'     => (int) ($def->fields['version'] ?? 1),
            ]);

            $DB->commit();
            $instance->getFromDB($id);
            return $instance;
        } catch (\Throwable $e) {
            $this->safeRollback($DB);
            throw ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException)
                ? $e
                : new \RuntimeException('fallo al iniciar instancia: ' . $e->getMessage(), 0, $e);
        }
    }

    private function safeRollback(\DBmysql $DB): void
    {
        try {
            if (!method_exists($DB, 'inTransaction') || $DB->inTransaction()) {
                $DB->rollBack();
            }
        } catch (\Throwable) {
            // best-effort
        }
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

    /**
     * Invalidación GENÉRICA de aprobaciones (extensión para plugins de dominio, p. ej.
     * `companysignature` cuando el contenido aprobado cambia de forma sustantiva).
     *
     * Domain-agnostic · fail-closed · concurrencia (`expectedVersion`) · IDEMPOTENTE
     * (`context['idempotency_key']`) · auditoría append-only · reabre al checkpoint
     * (`context['reopen_to_code']` o estado inicial) · emite `companyworkflow:approval_invalidated`.
     *
     * @param array<string,mixed> $context
     */
    public function invalidateApprovals(int $instanceId, string $reason, array $context = [], ?int $expectedVersion = null): TransitionResult
    {
        return $this->engine->invalidateApprovals($instanceId, $reason, $context, $expectedVersion);
    }

    public function builder(): DefinitionBuilder
    {
        return $this->builder;
    }
}
