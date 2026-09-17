<?php

/**
 * Materializador de evidencia a partir del LEDGER de historial de `companyworkflow` (hardening §1–§5).
 *
 * Es la ÚNICA vía por la que se crea evidencia derivada de eventos de workflow. Lo usan por igual:
 *   - el listener EN VIVO (`WorkflowEventListener`), pasando el `workflow_history_id` del evento; y
 *   - el RECONCILIADOR (`ReconcileCommand`), iterando filas del ledger.
 * Así, un evento perdido (proceso caído tras el COMMIT del workflow) se materializa exactamente una
 * vez al reconciliar: `commit → caída → restart → reconcile → evidencia creada una sola vez`.
 *
 * Garantías:
 *  - IDEMPOTENTE por `workflow_history_id + document_version + evidence_type` (UNIQUE en `evidences`).
 *  - FAIL-CLOSED de snapshot (§2): NUNCA crea evidencia de decisión/transición sin una versión
 *    documental vigente con `content_sha256` válido; si aún no existe, queda PENDIENTE (no-op) para
 *    una reconciliación posterior.
 *  - Vinculación por TIEMPO: la decisión se ata a la versión en vigor cuando ocurrió (no a una posterior).
 *  - INVALIDACIÓN EXACTA (§5): referencia explícitamente cada evidencia de aprobación afectada,
 *    acotada por instancia de workflow.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;

final class Materializer
{
    // Eventos del ledger de companyworkflow que producen evidencia (literales: sin acoplar la clase).
    public const WF_DECISION_RECORDED    = 'decision_recorded';
    public const WF_TRANSITIONED         = 'transitioned';
    public const WF_APPROVAL_INVALIDATED = 'approval_invalidated';

    /** Decisiones de actor que se materializan como evidencia (approved/rejected/returned). */
    private const DECISIONS = [
        ApprovalEvidence::DECISION_APPROVED,
        ApprovalEvidence::DECISION_REJECTED,
        ApprovalEvidence::DECISION_RETURNED,
    ];

    private VersionStore $versions;
    private EvidenceRecorder $recorder;
    private IdempotencyKey $keys;

    public function __construct(?VersionStore $versions = null, ?EvidenceRecorder $recorder = null, ?IdempotencyKey $keys = null)
    {
        $this->versions = $versions ?? new VersionStore();
        $this->recorder = $recorder ?? new EvidenceRecorder();
        $this->keys     = $keys ?? new IdempotencyKey();
    }

    /** Materializa por id de fila del ledger. Devuelve nº de evidencias NUEVAS creadas (0 si pendiente). */
    public function materializeByHistoryId(int $historyId): int
    {
        $api = $this->workflowApi();
        if ($api === null) {
            return 0;
        }
        $row = $api->historyById($historyId);
        return $row === null ? 0 : $this->materializeRow($row);
    }

    /**
     * Materializa a partir de una fila del ledger (ya leída). Devuelve nº de evidencias nuevas.
     * @param array<string,mixed> $row  fila de historial de companyworkflow
     */
    public function materializeRow(array $row): int
    {
        $historyId  = (int) ($row['id'] ?? 0);
        $event      = (string) ($row['event'] ?? '');
        $instanceId = (int) ($row['instances_id'] ?? 0);
        if ($historyId <= 0 || $instanceId <= 0) {
            return 0;
        }
        $subject = $this->resolveSubject($instanceId);
        if ($subject === null) {
            return 0;
        }
        $at   = (string) ($row['date'] ?? '');
        $meta = $this->decodeMeta($row);

        return match ($event) {
            self::WF_DECISION_RECORDED    => $this->materializeDecision($historyId, $instanceId, $subject, $row, $meta, $at),
            self::WF_TRANSITIONED         => $this->materializeTransition($historyId, $instanceId, $subject, $row, $at),
            self::WF_APPROVAL_INVALIDATED => $this->materializeInvalidation($historyId, $instanceId, $meta),
            default                       => 0,
        };
    }

    // ------------------------------------------------------------------ decisión por aprobador (§4)

    /**
     * @param array{itemtype:string,items_id:int,entities_id:int,is_recursive:int} $subject
     * @param array<string,mixed> $row @param array<string,mixed> $meta
     */
    private function materializeDecision(int $historyId, int $instanceId, array $subject, array $row, array $meta, string $at): int
    {
        $decision = (string) ($meta['decision'] ?? '');
        if (!in_array($decision, self::DECISIONS, true)) {
            return 0;
        }
        // FAIL-CLOSED (§2): sin versión vigente con hash válido → PENDIENTE.
        $version = $this->versionInEffect($subject, $at);
        if ($version === null) {
            return 0;
        }
        $key = $this->keys->forHistoryEvent($historyId, $version->versionNumber(), $decision);
        if ($this->recorder->findByKey($key) !== null) {
            return 0; // ya materializada
        }
        $ev = $this->recorder->record([
            'idempotency_key'       => $key,
            'subject_itemtype'      => $subject['itemtype'],
            'subject_items_id'      => $subject['items_id'],
            'entities_id'           => $subject['entities_id'],
            'is_recursive'          => $subject['is_recursive'],
            'workflow_instances_id' => $instanceId,
            'workflow_history_id'   => $historyId,
            'workflow_event_ref'    => (string) ($row['from_code'] ?? '') . '->' . (string) ($row['to_code'] ?? ''),
            'document_versions_id'  => (int) $version->getID(),
            'document_version'      => $version->versionNumber(),
            'content_sha256'        => $version->contentHash(),
            'actor_users_id'        => (int) ($row['actor_users_id'] ?? ($meta['actor'] ?? 0)),
            'actor_role'            => '',
            'decision'              => $decision,
            'event_type'            => ApprovalEvidence::EVENT_DECISION,
            'comment'               => (string) ($row['comment'] ?? ''),
            'references_evidences_id' => 0,
        ]);
        return $ev !== null ? 1 : 0;
    }

    // ------------------------------------------------------------------ transición (evento separado)

    /**
     * @param array{itemtype:string,items_id:int,entities_id:int,is_recursive:int} $subject
     * @param array<string,mixed> $row
     */
    private function materializeTransition(int $historyId, int $instanceId, array $subject, array $row, string $at): int
    {
        $version = $this->versionInEffect($subject, $at);
        if ($version === null) {
            return 0; // fail-closed (§2): transiciones antes de haber contenido aprobado quedan pendientes
        }
        $key = $this->keys->forHistoryEvent($historyId, $version->versionNumber(), ApprovalEvidence::EVENT_TRANSITION);
        if ($this->recorder->findByKey($key) !== null) {
            return 0;
        }
        $ev = $this->recorder->record([
            'idempotency_key'       => $key,
            'subject_itemtype'      => $subject['itemtype'],
            'subject_items_id'      => $subject['items_id'],
            'entities_id'           => $subject['entities_id'],
            'is_recursive'          => $subject['is_recursive'],
            'workflow_instances_id' => $instanceId,
            'workflow_history_id'   => $historyId,
            'workflow_event_ref'    => (string) ($row['from_code'] ?? '') . '->' . (string) ($row['to_code'] ?? ''),
            'document_versions_id'  => (int) $version->getID(),
            'document_version'      => $version->versionNumber(),
            'content_sha256'        => $version->contentHash(),
            'actor_users_id'        => (int) ($row['actor_users_id'] ?? 0),
            'actor_role'            => '',
            'decision'              => ApprovalEvidence::DECISION_TRANSITION,
            'event_type'            => ApprovalEvidence::EVENT_TRANSITION,
            'comment'               => (string) ($row['comment'] ?? ''),
            'references_evidences_id' => 0,
        ]);
        return $ev !== null ? 1 : 0;
    }

    // ------------------------------------------------------------------ invalidación EXACTA (§5)

    /**
     * Sólo materializa invalidaciones originadas por `invalidateApprovals()` (llevan `idempotency_key`
     * en meta). Las invalidaciones internas de "devolución" del motor no llevan clave → se ignoran.
     * @param array<string,mixed> $meta
     */
    private function materializeInvalidation(int $historyId, int $instanceId, array $meta): int
    {
        if ((string) ($meta['idempotency_key'] ?? '') === '') {
            return 0;
        }
        $docVersion = (int) ($meta['document_version'] ?? 0);
        $created = 0;
        // Sólo afecta aprobaciones ANTERIORES a esta invalidación (id de ledger monotónico): una
        // aprobación posterior (p. ej. de la versión nueva) NUNCA es invalidada por este evento,
        // aunque se reconcilie varias veces.
        foreach ($this->standingApprovals($instanceId, $historyId) as $approval) {
            $key = $this->keys->forInvalidation($historyId, $docVersion, (int) $approval->getID());
            if ($this->recorder->findByKey($key) !== null) {
                continue;
            }
            $ev = $this->recorder->record([
                'idempotency_key'         => $key,
                'subject_itemtype'        => (string) $approval->fields['subject_itemtype'],
                'subject_items_id'        => (int) $approval->fields['subject_items_id'],
                'entities_id'             => (int) $approval->fields['entities_id'],
                'is_recursive'            => (int) $approval->fields['is_recursive'],
                'workflow_instances_id'   => $instanceId,
                'workflow_history_id'     => $historyId,
                'workflow_event_ref'      => 'invalidated',
                'document_versions_id'    => (int) $approval->fields['document_versions_id'],
                'document_version'        => (int) $approval->fields['document_version'],
                'content_sha256'          => (string) $approval->fields['content_sha256'],
                'actor_users_id'          => (int) ($meta['actor'] ?? 0),
                'actor_role'              => '',
                'decision'                => ApprovalEvidence::DECISION_INVALIDATED,
                'event_type'              => ApprovalEvidence::EVENT_INVALIDATION,
                'comment'                 => (string) ($meta['reason'] ?? ''),
                'references_evidences_id' => (int) $approval->getID(),
            ]);
            if ($ev !== null) {
                $created++;
            }
        }
        return $created;
    }

    /**
     * Aprobaciones VIGENTES (aún no superseded) de una instancia — el conjunto exacto a invalidar.
     * Acotado por `workflow_instances_id` (invalidar A no toca B aunque compartan sujeto) y por
     * ANTERIORIDAD al evento de invalidación (`workflow_history_id` del ledger, monotónico), de modo
     * que una aprobación posterior (versión nueva) nunca queda invalidada por este evento.
     * @return array<int,ApprovalEvidence>
     */
    private function standingApprovals(int $instanceId, int $beforeHistoryId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => ApprovalEvidence::getTable(),
            'WHERE'  => [
                'workflow_instances_id' => $instanceId,
                'event_type'            => ApprovalEvidence::EVENT_DECISION,
                'decision'              => ApprovalEvidence::DECISION_APPROVED,
                ['workflow_history_id'  => ['>', 0]],
                ['workflow_history_id'  => ['<', $beforeHistoryId]],
            ],
            'ORDER'  => 'id ASC',
        ]) as $row) {
            $id = (int) $row['id'];
            if ($this->isReferencedByInvalidation($id)) {
                continue; // ya invalidada
            }
            $m = new ApprovalEvidence();
            if ($m->getFromDB($id)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** ¿Existe una evidencia de invalidación que referencia EXACTAMENTE a esta evidencia? (§5) */
    public function isReferencedByInvalidation(int $evidenceId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($evidenceId <= 0) {
            return false;
        }
        foreach ($DB->request([
            'COUNT' => 'c',
            'FROM'  => ApprovalEvidence::getTable(),
            'WHERE' => ['event_type' => ApprovalEvidence::EVENT_INVALIDATION, 'references_evidences_id' => $evidenceId],
        ]) as $row) {
            return ((int) $row['c']) > 0;
        }
        return false;
    }

    // ------------------------------------------------------------------ helpers

    /** Versión vigente con hash válido (fail-closed §2), o null si aún no hay snapshot. */
    private function versionInEffect(array $subject, string $at): ?DocumentVersion
    {
        if ($at === '') {
            return null;
        }
        $v = $this->versions->versionInEffectAt($subject['itemtype'], $subject['items_id'], $at);
        if ($v === null) {
            return null;
        }
        if ((int) $v->getID() <= 0 || $v->versionNumber() <= 0 || !$this->isValidHash($v->contentHash())) {
            return null;
        }
        return $v;
    }

    private function isValidHash(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1;
    }

    /** @return array<string,mixed> */
    private function decodeMeta(array $row): array
    {
        $raw = (string) ($row['meta_json'] ?? '');
        if ($raw === '') {
            return [];
        }
        $m = json_decode($raw, true);
        return is_array($m) ? $m : [];
    }

    /**
     * @return array{itemtype:string, items_id:int, entities_id:int, is_recursive:int}|null
     */
    private function resolveSubject(int $instanceId): ?array
    {
        $instClass = 'GlpiPlugin\\Companyworkflow\\Model\\Instance';
        if (!class_exists($instClass)) {
            return null;
        }
        /** @var \CommonDBTM $inst */
        $inst = new $instClass();
        if (!$inst->getFromDB($instanceId)) {
            return null;
        }
        $itemtype = (string) ($inst->fields['itemtype'] ?? '');
        $itemsId  = (int) ($inst->fields['items_id'] ?? 0);
        if ($itemtype === '' || $itemsId <= 0) {
            return null;
        }
        return [
            'itemtype'     => $itemtype,
            'items_id'     => $itemsId,
            'entities_id'  => (int) ($inst->fields['entities_id'] ?? 0),
            'is_recursive' => (int) ($inst->fields['is_recursive'] ?? 0),
        ];
    }

    /** @return object|null instancia de WorkflowApi de companyworkflow, o null si no está disponible */
    private function workflowApi(): ?object
    {
        $cls = 'GlpiPlugin\\Companyworkflow\\Api\\WorkflowApi';
        return class_exists($cls) ? new $cls() : null;
    }
}
