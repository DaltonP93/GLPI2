<?php

/**
 * Motor de transiciones. Una transición es ATÓMICA, SERIALIZADA y FAIL-CLOSED:
 *   pre-chequeos (acción/estado, ACL, entidad, versión, comentario, condición, pertenencia de
 *   aprobador) → BEGIN → SELECT ... FOR UPDATE de la instancia (serializa por instancia) →
 *   relectura fresca → re-validación → [voto + quórum si aplica] → cambio de estado (UPDATE
 *   condicionado por lock_version) → auditoría append-only → COMMIT → notificación/evento.
 *
 * Recovery-safe (no existe "voto aprobado + estado sin avanzar + retry bloqueado"): el voto y el
 * avance se confirman en la MISMA transacción; y si en un reintento el voto ya existe y el quórum
 * está alcanzado pero el estado no avanzó, se AVANZA (no se devuelve DUPLICATE).
 *
 * Concurrencia: el FOR UPDATE serializa las acciones sobre la misma instancia, de modo que el
 * conteo de votos ve todos los votos ya confirmados (sin carreras de snapshot); el lock_version
 * es defensa adicional.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

use Session;
use GlpiPlugin\Companyworkflow\Model\Assignment;
use GlpiPlugin\Companyworkflow\Model\HistoryEvent;
use GlpiPlugin\Companyworkflow\Model\Instance;
use GlpiPlugin\Companyworkflow\Model\StateDef;
use GlpiPlugin\Companyworkflow\Model\Transition;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;

class Engine
{
    private ConditionEvaluator $conditions;
    private QuorumCalculator $quorum;
    private TransitionResolver $resolver;
    private ApproverResolver $approvers;
    private AuditBridge $audit;
    private NotificationBridge $notifications;

    public function __construct()
    {
        $this->conditions    = new ConditionEvaluator();
        $this->quorum        = new QuorumCalculator();
        $this->resolver      = new TransitionResolver();
        $this->approvers     = new ApproverResolver();
        $this->audit         = new AuditBridge();
        $this->notifications = new NotificationBridge();
    }

    /**
     * Ejecuta (o intenta) una transición. Fail-closed.
     *
     * @param array<string,mixed> $ctx  comment, fields, expected_lock_version, expected_def_version,
     *                                  actor_users_id, requester_users_id
     */
    public function transition(Instance $instance, string $action, array $ctx = []): TransitionResult
    {
        if (!$instance->isOpen()) {
            return TransitionResult::fail(TransitionResult::CLOSED, 'La instancia no está abierta.');
        }

        $defId       = (int) $instance->fields['workflowdefs_id'];
        $fromStateId = (int) $instance->fields['current_statedefs_id'];
        $transitions = $this->loadTransitions($defId);

        $t = $this->resolver->resolve($transitions, $fromStateId, $action);
        if ($t === null) {
            return TransitionResult::fail(TransitionResult::INVALID_ACTION, 'Transición no válida desde el estado actual.');
        }

        // --- ACL (bit configurable por transición) ---
        $right = (int) ($t['required_right'] ?? 0);
        if ($right <= 0) {
            $right = READ;
        }
        if (!Session::haveRight(WorkflowDef::$rightname, $right)) {
            return TransitionResult::fail(TransitionResult::DENIED_ACL, 'Sin derecho para esta acción.');
        }

        // --- Multi-entidad ESTRICTA (actor) ---
        $ent = (int) $instance->fields['entities_id'];
        $rec = (int) $instance->fields['is_recursive'];
        if (!Session::haveAccessToEntity($ent, (bool) $rec)) {
            return TransitionResult::fail(TransitionResult::DENIED_ENTITY, 'Sin acceso a la entidad de la instancia.');
        }

        // --- Comentario obligatorio ---
        $comment = trim((string) ($ctx['comment'] ?? ''));
        if ((int) ($t['requires_comment'] ?? 0) === 1 && $comment === '') {
            return TransitionResult::fail(TransitionResult::COMMENT_REQUIRED, 'Esta transición exige comentario.');
        }

        // --- Condición declarativa ---
        $fields = is_array($ctx['fields'] ?? null) ? $ctx['fields'] : [];
        if (!$this->conditions->evaluate($t['condition_json'] ?? null, $fields)) {
            return TransitionResult::fail(TransitionResult::CONDITION_FAILED, 'No se cumple la condición de la transición.');
        }

        $actor = (int) (Session::getLoginUserID() ?: (int) ($ctx['actor_users_id'] ?? 0));

        // --- Etapa de aprobación del estado actual (aprobadores EFECTIVOS por entidad) ---
        $stage = $this->stageApprovers($transitions, $fromStateId, $defId, $ent);
        if ($stage['step'] !== null && in_array($action, ['approve', 'reject', 'return'], true)) {
            if (!in_array($actor, $stage['approvers'], true)) {
                return TransitionResult::fail(TransitionResult::DENIED_ACL, 'El actor no es aprobador efectivo de esta etapa.');
            }
        }

        return $this->executeMutation($instance, $t, $action, $fromStateId, $stage, $actor, $comment, $ctx);
    }

    /**
     * Acciones disponibles desde el estado actual (para bandeja/action bar).
     * @return array<int,string>
     */
    public function availableActions(Instance $instance): array
    {
        if (!$instance->isOpen()) {
            return [];
        }
        $transitions = $this->loadTransitions((int) $instance->fields['workflowdefs_id']);
        return $this->resolver->actionsFrom($transitions, (int) $instance->fields['current_statedefs_id']);
    }

    // ------------------------------------------------------------------ mutación atómica

    /**
     * @param array<string,mixed> $t
     * @param array{step:?array,approvers:array<int,int>} $stage
     * @param array<string,mixed> $ctx
     */
    private function executeMutation(Instance $instance, array $t, string $action, int $fromStateId, array $stage, int $actor, string $comment, array $ctx): TransitionResult
    {
        /** @var \DBmysql $DB */
        global $DB;
        $instanceId = (int) $instance->getID();

        $DB->beginTransaction();
        try {
            // Serialización por instancia (evita carreras de conteo/doble avance).
            $fresh = $this->lockAndLoad($instanceId);
            if ($fresh === null) {
                $this->safeRollback($DB);
                return TransitionResult::fail(TransitionResult::ERROR, 'Instancia inexistente.');
            }
            if ((string) $fresh['status'] !== Instance::STATUS_OPEN) {
                $this->safeRollback($DB);
                return TransitionResult::fail(TransitionResult::CLOSED, 'La instancia no está abierta.');
            }
            // El estado pudo haber avanzado desde que el actor cargó la instancia.
            if ((int) $fresh['current_statedefs_id'] !== $fromStateId) {
                $this->safeRollback($DB);
                return TransitionResult::fail(TransitionResult::CONFLICT_VERSION, 'El estado cambió antes de aplicar (concurrencia).');
            }
            // Control optimista explícito (versión de instancia / de definición).
            if (isset($ctx['expected_lock_version']) && (int) $ctx['expected_lock_version'] !== (int) $fresh['lock_version']) {
                $this->safeRollback($DB);
                return TransitionResult::fail(TransitionResult::CONFLICT_VERSION, 'La instancia cambió (lock_version).');
            }
            if (isset($ctx['expected_def_version']) && (int) $ctx['expected_def_version'] !== (int) $fresh['def_version']) {
                $this->safeRollback($DB);
                return TransitionResult::fail(TransitionResult::CONFLICT_VERSION, 'Definición desincronizada (def_version).');
            }

            $result = ($action === 'approve' && $stage['step'] !== null)
                ? $this->applyApproval($fresh, $t, $stage, $fromStateId, $actor, $comment, $ctx)
                : $this->applySingleActor($fresh, $t, $fromStateId, $actor, $action, $comment, $ctx);

            if (!$result->success && $result->code !== TransitionResult::RECORDED) {
                // RECORDED es éxito (voto sin quórum); cualquier fallo real → rollback.
                $this->safeRollback($DB);
                return $result;
            }

            $DB->commit();
        } catch (\Throwable $e) {
            $this->safeRollback($DB);
            return TransitionResult::fail(TransitionResult::ERROR, 'Error en la transición: ' . $e->getMessage());
        }

        // Post-commit best-effort (una notificación no revierte lo confirmado).
        $instance->getFromDB($instanceId);
        if ($result->success && ($result->data['advanced'] ?? false)) {
            $recipients = $this->nextRecipients($instance, (int) $result->data['to_state_id'], $ctx);
            $result->data['recipients'] = $recipients;
            $this->notifications->notifyTransition([
                'instances_id' => $instanceId,
                'from'         => $result->data['from'] ?? '',
                'to'           => $result->data['to'] ?? '',
                'action'       => $action,
                'actor'        => $actor,
                'recipients'   => $recipients,
            ]);
        }
        return $result;
    }

    /**
     * Voto + quórum + avance, todo dentro de la transacción abierta (recovery-safe).
     * @param array<string,mixed> $fresh @param array<string,mixed> $t
     * @param array{step:?array,approvers:array<int,int>} $stage @param array<string,mixed> $ctx
     */
    private function applyApproval(array $fresh, array $t, array $stage, int $fromStateId, int $actor, string $comment, array $ctx): TransitionResult
    {
        $instanceId = (int) $fresh['id'];
        $step       = $stage['step'];
        $approvers  = $stage['approvers'];

        $ballotState = $this->recordBallot($instanceId, $fromStateId, $step, $actor, $comment);
        if ($ballotState === 'error') {
            return TransitionResult::fail(TransitionResult::ERROR, 'No se pudo registrar el voto.');
        }
        if ($ballotState === 'new') {
            $this->audit->record($instanceId, HistoryEvent::EVENT_BALLOT_RECORDED,
                $this->stateCode($fromStateId), $this->stateCode($fromStateId), $comment, false,
                ['decision' => Assignment::DECISION_APPROVED]);
        }

        // Conteo bajo lock → ve todos los votos confirmados.
        $approvals = $this->countApprovals($instanceId, $fromStateId);
        $met = $this->quorum->isMet(
            (string) ($step['quorum_type'] ?? 'count'),
            (int) ($step['quorum_value'] ?? 1),
            count($approvers),
            $approvals
        );

        if (!$met) {
            // Sin quórum: si el actor ya había votado (retry) → DUPLICATE; si no, RECORDED.
            if ($ballotState === 'dup') {
                return TransitionResult::fail(TransitionResult::DUPLICATE, 'El actor ya emitió su voto en esta etapa.');
            }
            return TransitionResult::ok(TransitionResult::RECORDED, 'Voto registrado; quórum aún no alcanzado.', [
                'approvals' => $approvals,
                'total'     => count($approvers),
                'advanced'  => false,
            ]);
        }

        // Quórum alcanzado → avanzar (aunque el voto fuese un retry: recovery-safe).
        $this->audit->record($instanceId, HistoryEvent::EVENT_QUORUM_REACHED,
            $this->stateCode($fromStateId), $this->stateCode($fromStateId), '', true,
            ['approvals' => $approvals, 'total' => count($approvers)]);

        // Punto de inyección de fallo para tests de recuperación (no-op en producción).
        $this->afterQuorumBeforeAdvance();

        return $this->applyStateChange($fresh, $t, $fromStateId, $actor, 'approve', $comment);
    }

    /**
     * @param array<string,mixed> $fresh @param array<string,mixed> $t @param array<string,mixed> $ctx
     */
    private function applySingleActor(array $fresh, array $t, int $fromStateId, int $actor, string $action, string $comment, array $ctx): TransitionResult
    {
        return $this->applyStateChange($fresh, $t, $fromStateId, $actor, $action, $comment);
    }

    /**
     * Cambio de estado condicionado por lock_version + auditoría, dentro de la transacción.
     * Como se sostiene el FOR UPDATE y se verificó el estado, el UPDATE siempre aplica.
     * @param array<string,mixed> $fresh @param array<string,mixed> $t
     */
    private function applyStateChange(array $fresh, array $t, int $fromStateId, int $actor, string $action, string $comment): TransitionResult
    {
        /** @var \DBmysql $DB */
        global $DB;

        $instanceId   = (int) $fresh['id'];
        $expectedLock = (int) $fresh['lock_version'];
        $toStateId    = (int) $t['to_statedefs_id'];

        $toState = new StateDef();
        if (!$toState->getFromDB($toStateId)) {
            return TransitionResult::fail(TransitionResult::ERROR, 'Estado destino inexistente.');
        }
        $fromCode = $this->stateCode($fromStateId);
        $toCode   = (string) ($toState->fields['code'] ?? '');
        $toKind   = (string) ($toState->fields['kind'] ?? '');

        $newStatus = Instance::STATUS_OPEN;
        if ($action === 'cancel') {
            $newStatus = Instance::STATUS_CANCELLED;
        } elseif ($toKind === StateDef::KIND_FINAL) {
            $newStatus = Instance::STATUS_CLOSED;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->update(
            Instance::getTable(),
            [
                'current_statedefs_id' => $toStateId,
                'status'               => $newStatus,
                'lock_version'         => $expectedLock + 1,
                'date_mod'             => $now,
            ],
            ['id' => $instanceId, 'lock_version' => $expectedLock, 'status' => Instance::STATUS_OPEN]
        );

        if (!$this->changeApplied($instanceId, $expectedLock + 1, $toStateId)) {
            return TransitionResult::fail(TransitionResult::CONFLICT_VERSION, 'Cambio de estado en conflicto (concurrencia/replay).');
        }

        // Auditoría append-only.
        $this->audit->record($instanceId, HistoryEvent::EVENT_TRANSITIONED, $fromCode, $toCode, $comment, false, ['action' => $action]);
        if ($action === 'reject') {
            $this->audit->record($instanceId, HistoryEvent::EVENT_REJECTED, $fromCode, $toCode, $comment, false);
        } elseif ($action === 'return') {
            $this->audit->record($instanceId, HistoryEvent::EVENT_RETURNED, $fromCode, $toCode, $comment, false);
            // Devolución: invalida votos previos (se rehacen las etapas); no borra historial.
            $DB->delete(Assignment::getTable(), ['instances_id' => $instanceId]);
            $this->audit->record($instanceId, HistoryEvent::EVENT_APPROVAL_INVALIDATED, $fromCode, $toCode, '', true);
        } elseif ($action === 'cancel') {
            $this->audit->record($instanceId, HistoryEvent::EVENT_CANCELLED, $fromCode, $toCode, $comment, false);
        }

        return TransitionResult::ok(TransitionResult::OK, 'Transición aplicada.', [
            'from'         => $fromCode,
            'to'           => $toCode,
            'to_state_id'  => $toStateId,
            'status'       => $newStatus,
            'lock_version' => $expectedLock + 1,
            'advanced'     => true,
        ]);
    }

    /** Punto de inyección de fallo SÓLO para tests (subclase). No-op en producción. */
    protected function afterQuorumBeforeAdvance(): void
    {
    }

    // ------------------------------------------------------------------ helpers de datos

    /** Bloquea la fila de la instancia y devuelve sus datos actuales (o null). @return array<string,mixed>|null */
    private function lockAndLoad(int $instanceId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $table = Instance::getTable();
        $res = $DB->doQuery("SELECT * FROM `{$table}` WHERE `id` = " . $instanceId . " FOR UPDATE");
        if ($res !== false && ($row = $DB->fetchAssoc($res))) {
            return $row;
        }
        return null;
    }

    /** ¿El UPDATE condicionado aplicó? (relee bajo el lock; independiente de affectedRows). */
    private function changeApplied(int $instanceId, int $expectedNewLock, int $toStateId): bool
    {
        $v = new Instance();
        return $v->getFromDB($instanceId)
            && (int) $v->fields['lock_version'] === $expectedNewLock
            && (int) $v->fields['current_statedefs_id'] === $toStateId;
    }

    /**
     * Registra/actualiza el voto del actor. Devuelve 'new' | 'dup' | 'error'.
     * @param array<string,mixed>|null $step
     */
    private function recordBallot(int $instanceId, int $stateId, ?array $step, int $actor, string $comment): string
    {
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $ballot = new Assignment();
        if ($ballot->getFromDBByCrit(['instances_id' => $instanceId, 'statedefs_id' => $stateId, 'users_id' => $actor])) {
            if (($ballot->fields['decision'] ?? '') === Assignment::DECISION_APPROVED) {
                return 'dup'; // ya votó (posible retry): el llamador re-evalúa quórum.
            }
            $ok = $ballot->update([
                'id'       => $ballot->getID(),
                'decision' => Assignment::DECISION_APPROVED,
                'comment'  => $comment !== '' ? $comment : null,
                'date'     => $now,
            ]);
            return $ok ? 'new' : 'error';
        }
        $newId = (int) $ballot->add([
            'instances_id' => $instanceId,
            'statedefs_id' => $stateId,
            'steps_id'     => (int) ($step['id'] ?? 0),
            'level'        => (int) ($step['level'] ?? 1),
            'users_id'     => $actor,
            'group_ref'    => (int) ($step['approver_ref'] ?? 0),
            'decision'     => Assignment::DECISION_APPROVED,
            'comment'      => $comment !== '' ? $comment : null,
            'date'         => $now,
        ]);
        return $newId > 0 ? 'new' : 'error';
    }

    /**
     * @param array<int,array<string,mixed>> $transitions
     * @return array{step:?array<string,mixed>, approvers:array<int,int>}
     */
    private function stageApprovers(array $transitions, int $fromStateId, int $defId, int $entitiesId): array
    {
        $approveT = $this->resolver->resolve($transitions, $fromStateId, 'approve');
        if ($approveT === null) {
            return ['step' => null, 'approvers' => []];
        }
        $step = $this->firstStep((int) $approveT['id']);
        if ($step === null) {
            return ['step' => null, 'approvers' => []];
        }
        return ['step' => $step, 'approvers' => $this->approvers->resolveForStep($step, $defId, $entitiesId)];
    }

    /** @return array<string,mixed>|null */
    private function firstStep(int $transitionsId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_companyworkflow_steps',
            'WHERE' => ['transitions_id' => $transitionsId],
            'ORDER' => 'level ASC',
        ]) as $row) {
            return $row;
        }
        return null;
    }

    private function countApprovals(int $instanceId, int $stateId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request([
            'COUNT' => 'cnt',
            'FROM'  => 'glpi_plugin_companyworkflow_assignments',
            'WHERE' => ['instances_id' => $instanceId, 'statedefs_id' => $stateId, 'decision' => Assignment::DECISION_APPROVED],
        ]) as $row) {
            $n = (int) $row['cnt'];
        }
        return $n;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadTransitions(int $defId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => Transition::getTable(), 'WHERE' => ['workflowdefs_id' => $defId]]) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    private function stateCode(int $stateId): string
    {
        static $cache = [];
        if (isset($cache[$stateId])) {
            return $cache[$stateId];
        }
        $s = new StateDef();
        return $cache[$stateId] = ($s->getFromDB($stateId) ? (string) ($s->fields['code'] ?? '') : '');
    }

    /**
     * @param array<string,mixed> $ctx
     * @return array<int,int>
     */
    private function nextRecipients(Instance $instance, int $toStateId, array $ctx): array
    {
        $transitions = $this->loadTransitions((int) $instance->fields['workflowdefs_id']);
        $stage = $this->stageApprovers($transitions, $toStateId, (int) $instance->fields['workflowdefs_id'], (int) $instance->fields['entities_id']);
        $recipients = $stage['approvers'];
        $requester = (int) ($ctx['requester_users_id'] ?? 0);
        if ($requester > 0) {
            $recipients[] = $requester;
        }
        return array_values(array_unique(array_map('intval', $recipients)));
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
}
