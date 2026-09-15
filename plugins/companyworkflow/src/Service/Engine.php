<?php

/**
 * Motor de transiciones. Una transición es ATÓMICA y FAIL-CLOSED:
 *   validar acción/estado → ACL → entidad → versión (optimista) → comentario → condición →
 *   [quórum si aplica] → cambio de estado (UPDATE condicionado por lock_version) → auditoría
 *   append-only → notificación/evento.
 *
 * Evita doble aprobación por concurrencia/replay: (a) UNIQUE del voto por (instancia,estado,
 * usuario); (b) UPDATE con `WHERE lock_version = <esperado>` → sólo un cambio de estado gana.
 *
 * Multi-entidad ESTRICTA: sin acceso a la entidad de la instancia → DENIED_ENTITY.
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

final class Engine
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
     * @param array<string,mixed> $ctx  comment, fields (para condición), expected_lock_version,
     *                                  expected_def_version, actor_users_id, requester_users_id
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

        // --- Multi-entidad ESTRICTA ---
        $ent = (int) $instance->fields['entities_id'];
        $rec = (int) $instance->fields['is_recursive'];
        if (!Session::haveAccessToEntity($ent, (bool) $rec)) {
            return TransitionResult::fail(TransitionResult::DENIED_ENTITY, 'Sin acceso a la entidad de la instancia.');
        }

        // --- Control OPTIMISTA (versión de instancia / de definición) ---
        if (isset($ctx['expected_lock_version'])
            && (int) $ctx['expected_lock_version'] !== (int) $instance->fields['lock_version']) {
            return TransitionResult::fail(TransitionResult::CONFLICT_VERSION, 'La instancia cambió (lock_version).');
        }
        if (isset($ctx['expected_def_version'])
            && (int) $ctx['expected_def_version'] !== (int) $instance->fields['def_version']) {
            return TransitionResult::fail(TransitionResult::CONFLICT_VERSION, 'Definición desincronizada (def_version).');
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

        // --- Etapa de aprobación del estado actual (si existe) ---
        $stage = $this->stageApprovers($transitions, $fromStateId, $defId, $ent);

        // approve/reject/return sobre una etapa con aprobadores: el actor DEBE ser aprobador.
        if ($stage['step'] !== null && in_array($action, ['approve', 'reject', 'return'], true)) {
            if (!in_array($actor, $stage['approvers'], true)) {
                return TransitionResult::fail(TransitionResult::DENIED_ACL, 'El actor no es aprobador de esta etapa.');
            }
        }

        // Camino con quórum (approve sobre etapa con step).
        if ($action === 'approve' && $stage['step'] !== null) {
            return $this->handleApproval($instance, $t, $stage, $fromStateId, $actor, $comment, $ctx);
        }

        // Transición de actor único (submit/reject/return/cancel/order/receive/deliver/close/…).
        return $this->advance($instance, $t, $fromStateId, $actor, $action, $comment, $ctx);
    }

    /**
     * Acciones disponibles desde el estado actual para el actor (para bandeja/action bar).
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

    // ------------------------------------------------------------------

    /** @param array<string,mixed> $t @param array{step:?array,approvers:array<int,int>} $stage @param array<string,mixed> $ctx */
    private function handleApproval(Instance $instance, array $t, array $stage, int $fromStateId, int $actor, string $comment, array $ctx): TransitionResult
    {
        $instanceId = (int) $instance->getID();
        $step       = $stage['step'];
        $approvers  = $stage['approvers'];

        // Voto idempotente: si ya votó (no pending) → DUPLICATE.
        $ballot = new Assignment();
        if ($ballot->getFromDBByCrit([
            'instances_id' => $instanceId,
            'statedefs_id' => $fromStateId,
            'users_id'     => $actor,
        ])) {
            if (($ballot->fields['decision'] ?? '') !== Assignment::DECISION_PENDING) {
                return TransitionResult::fail(TransitionResult::DUPLICATE, 'El actor ya emitió su voto en esta etapa.');
            }
            $ballot->update([
                'id'       => $ballot->getID(),
                'decision' => Assignment::DECISION_APPROVED,
                'comment'  => $comment !== '' ? $comment : null,
                'date'     => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
        } else {
            $newId = (int) $ballot->add([
                'instances_id' => $instanceId,
                'statedefs_id' => $fromStateId,
                'steps_id'     => (int) ($step['id'] ?? 0),
                'level'        => (int) ($step['level'] ?? 1),
                'users_id'     => $actor,
                'group_ref'    => (int) ($step['approver_ref'] ?? 0),
                'decision'     => Assignment::DECISION_APPROVED,
                'comment'      => $comment !== '' ? $comment : null,
                'date'         => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            ]);
            if ($newId <= 0) {
                // La UNIQUE lo pudo rechazar por carrera → duplicado.
                return TransitionResult::fail(TransitionResult::DUPLICATE, 'Voto duplicado (carrera).');
            }
        }

        $this->audit->record($instanceId, HistoryEvent::EVENT_BALLOT_RECORDED,
            $this->stateCode($fromStateId), $this->stateCode($fromStateId), $comment, false,
            ['decision' => Assignment::DECISION_APPROVED]);

        // Recontar quórum.
        $approvals = $this->countApprovals($instanceId, $fromStateId);
        $total     = count($approvers);
        $met = $this->quorum->isMet(
            (string) ($step['quorum_type'] ?? 'count'),
            (int) ($step['quorum_value'] ?? 1),
            $total,
            $approvals
        );

        if (!$met) {
            return TransitionResult::ok(TransitionResult::RECORDED, 'Voto registrado; quórum aún no alcanzado.', [
                'approvals' => $approvals,
                'total'     => $total,
            ]);
        }

        $this->audit->record($instanceId, HistoryEvent::EVENT_QUORUM_REACHED,
            $this->stateCode($fromStateId), $this->stateCode($fromStateId), '', true,
            ['approvals' => $approvals, 'total' => $total]);

        return $this->advance($instance, $t, $fromStateId, $actor, 'approve', $comment, $ctx);
    }

    /** @param array<string,mixed> $t @param array<string,mixed> $ctx */
    private function advance(Instance $instance, array $t, int $fromStateId, int $actor, string $action, string $comment, array $ctx): TransitionResult
    {
        /** @var \DBmysql $DB */
        global $DB;

        $instanceId  = (int) $instance->getID();
        $toStateId   = (int) $t['to_statedefs_id'];
        $expectedLock = (int) $instance->fields['lock_version'];

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

        $DB->beginTransaction();
        try {
            $DB->update(
                Instance::getTable(),
                [
                    'current_statedefs_id' => $toStateId,
                    'status'               => $newStatus,
                    'lock_version'         => $expectedLock + 1,
                    'date_mod'             => $now,
                ],
                [
                    'id'           => $instanceId,
                    'lock_version' => $expectedLock,
                    'status'       => Instance::STATUS_OPEN,
                ]
            );

            // Éxito del cambio condicionado por lock_version (evita doble aprobación por carrera):
            // se usa affectedRows() si está disponible; si no, se verifica releyendo la fila.
            $affected = null;
            if (method_exists($DB, 'affectedRows')) {
                $affected = (int) $DB->affectedRows();
            } elseif (method_exists($DB, 'affected_rows')) {
                $affected = (int) $DB->affected_rows();
            }
            if ($affected !== null) {
                $applied = ($affected === 1);
            } else {
                $verify = new Instance();
                $applied = $verify->getFromDB($instanceId)
                    && (int) $verify->fields['lock_version'] === $expectedLock + 1
                    && (int) $verify->fields['current_statedefs_id'] === $toStateId;
            }
            if (!$applied) {
                $DB->rollBack();
                return TransitionResult::fail(TransitionResult::CONFLICT_VERSION, 'Cambio de estado en conflicto (concurrencia/replay).');
            }

            // Auditoría append-only.
            $this->audit->record($instanceId, HistoryEvent::EVENT_TRANSITIONED, $fromCode, $toCode, $comment, false, ['action' => $action]);
            if ($action === 'reject') {
                $this->audit->record($instanceId, HistoryEvent::EVENT_REJECTED, $fromCode, $toCode, $comment, false);
            } elseif ($action === 'return') {
                $this->audit->record($instanceId, HistoryEvent::EVENT_RETURNED, $fromCode, $toCode, $comment, false);
                // Devolución para corrección: INVALIDA los votos previos (se rehacen las etapas).
                // No se toca el historial (append-only): se registra el evento de invalidación.
                $DB->delete(Assignment::getTable(), ['instances_id' => $instanceId]);
                $this->audit->record($instanceId, HistoryEvent::EVENT_APPROVAL_INVALIDATED, $fromCode, $toCode, '', true);
            } elseif ($action === 'cancel') {
                $this->audit->record($instanceId, HistoryEvent::EVENT_CANCELLED, $fromCode, $toCode, $comment, false);
            }

            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            return TransitionResult::fail(TransitionResult::ERROR, 'Error en la transición: ' . $e->getMessage());
        }

        // Post-commit best-effort (una notificación no revierte la transición confirmada).
        $recipients = $this->nextRecipients($instance, $toStateId, $ctx);
        $this->notifications->notifyTransition([
            'instances_id' => $instanceId,
            'from'         => $fromCode,
            'to'           => $toCode,
            'action'       => $action,
            'actor'        => $actor,
            'recipients'   => $recipients,
        ]);

        $instance->getFromDB($instanceId);
        return TransitionResult::ok(TransitionResult::OK, 'Transición aplicada.', [
            'from'         => $fromCode,
            'to'           => $toCode,
            'status'       => $newStatus,
            'lock_version' => $expectedLock + 1,
            'recipients'   => $recipients,
        ]);
    }

    /**
     * Aprobadores de la etapa de aprobación saliente del estado (la transición 'approve').
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
        $approvers = $this->approvers->resolveForStep($step, $defId, $entitiesId);
        return ['step' => $step, 'approvers' => $approvers];
    }

    /** @return array<string,mixed>|null */
    private function firstStep(int $transitionsId): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $best = null;
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_companyworkflow_steps',
            'WHERE' => ['transitions_id' => $transitionsId],
            'ORDER' => 'level ASC',
        ]) as $row) {
            $best = $row;
            break;
        }
        return $best;
    }

    private function countApprovals(int $instanceId, int $stateId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request([
            'COUNT' => 'cnt',
            'FROM'  => 'glpi_plugin_companyworkflow_assignments',
            'WHERE' => [
                'instances_id' => $instanceId,
                'statedefs_id' => $stateId,
                'decision'     => Assignment::DECISION_APPROVED,
            ],
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
        foreach ($DB->request([
            'FROM'  => Transition::getTable(),
            'WHERE' => ['workflowdefs_id' => $defId],
        ]) as $row) {
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
        $code = $s->getFromDB($stateId) ? (string) ($s->fields['code'] ?? '') : '';
        return $cache[$stateId] = $code;
    }

    /**
     * Destinatarios de la notificación tras entrar a un estado: aprobadores de la nueva etapa
     * (si la hay) + solicitante (si se pasó en el contexto).
     * @param array<string,mixed> $ctx
     * @return array<int,int>
     */
    private function nextRecipients(Instance $instance, int $toStateId, array $ctx): array
    {
        $transitions = $this->loadTransitions((int) $instance->fields['workflowdefs_id']);
        $stage = $this->stageApprovers(
            $transitions,
            $toStateId,
            (int) $instance->fields['workflowdefs_id'],
            (int) $instance->fields['entities_id']
        );
        $recipients = $stage['approvers'];
        $requester = (int) ($ctx['requester_users_id'] ?? 0);
        if ($requester > 0) {
            $recipients[] = $requester;
        }
        return array_values(array_unique(array_map('intval', $recipients)));
    }
}
