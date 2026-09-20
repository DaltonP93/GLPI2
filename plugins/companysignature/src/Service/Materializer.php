<?php

/**
 * Materializador de evidencia a partir del LEDGER de historial de `companyworkflow` (hardening §1–§5).
 *
 * Es la ÚNICA vía por la que se crea evidencia derivada de eventos de workflow. Lo usan por igual:
 *   - el listener EN VIVO (`WorkflowEventListener`), pasando el `workflow_history_id` del evento; y
 *   - el RECONCILIADOR durable (`ReconcileService`/CronTask), procesando la cola.
 * Así, un evento perdido (proceso caído tras el COMMIT del workflow) se materializa exactamente una
 * vez al reconciliar.
 *
 * Garantías:
 *  - IDEMPOTENTE por `workflow_history_id + document_version + evidence_type` (UNIQUE en `evidences`).
 *  - IDENTIDAD EXPLÍCITA (§2): la versión aprobada la fija el DOMINIO vía `evidence_ref`
 *    `{document_versions_id, document_version, content_sha256}` guardada en el meta del ledger.
 *    **No se infiere por timestamp.** Sin `evidence_ref` válida ⇒ pendiente (fail-closed).
 *  - `event_date` PROBATORIO (§1): se guarda la fecha ORIGINAL del ledger (momento de la decisión).
 *  - CONTEXTO HISTÓRICO del aprobador (§5): se copia desde el meta del ledger, sin inferir a posteriori.
 *  - INVALIDACIÓN EXACTA (§5 previo): referencia explícita por aprobación, acotada por instancia y
 *    por anterioridad en el ledger.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;

// No es `final`: `workflowApi()` es `protected` para permitir un test determinista del camino
// FAIL-CLOSED cuando la dependencia (companyworkflow) NO está disponible.
class Materializer
{
    public const WF_DECISION_RECORDED    = 'decision_recorded';
    public const WF_TRANSITIONED         = 'transitioned';
    public const WF_APPROVAL_INVALIDATED = 'approval_invalidated';

    /** Resultados para el worker de la cola. */
    public const R_DONE    = 'done';    // materializado o ya existente o no aplica
    public const R_PENDING = 'pending'; // falta evidence_ref/dv → reintentar
    public const R_ERROR   = 'error';   // inconsistencia (ref no coincide) → no se auto-cura

    private const DECISIONS = [
        ApprovalEvidence::DECISION_APPROVED,
        ApprovalEvidence::DECISION_REJECTED,
        ApprovalEvidence::DECISION_RETURNED,
    ];

    private EvidenceRecorder $recorder;
    private IdempotencyKey $keys;

    public function __construct(?EvidenceRecorder $recorder = null, ?IdempotencyKey $keys = null)
    {
        $this->recorder = $recorder ?? new EvidenceRecorder();
        $this->keys     = $keys ?? new IdempotencyKey();
    }

    /**
     * Materializa por id de fila del ledger.
     * @return array{created:int, status:string}
     */
    public function materializeByHistoryId(int $historyId): array
    {
        $api = $this->workflowApi();
        if ($api === null) {
            // Dependencia (companyworkflow) NO disponible: es una condición TEMPORAL. NUNCA consumir la
            // tarea (no DONE): reintentar cuando la dependencia vuelva (fail-closed §3).
            return ['created' => 0, 'status' => self::R_PENDING, 'reason' => 'companyworkflow no disponible'];
        }
        $row = $api->historyById($historyId);
        if ($row === null) {
            // La fila del ledger no es legible AHORA. El historial es append-only (no debería faltar
            // permanentemente), así que se trata como transitorio: reintentar, no marcar DONE.
            return ['created' => 0, 'status' => self::R_PENDING, 'reason' => 'ledger no legible (historyById null)'];
        }
        return $this->materializeRow($row);
    }

    /**
     * Materializa a partir de una fila del ledger (ya leída).
     * @param array<string,mixed> $row
     * @return array{created:int, status:string}
     */
    public function materializeRow(array $row): array
    {
        $historyId  = (int) ($row['id'] ?? 0);
        $event      = (string) ($row['event'] ?? '');
        $instanceId = (int) ($row['instances_id'] ?? 0);
        if ($historyId <= 0) {
            return ['created' => 0, 'status' => self::R_DONE]; // fila sin id utilizable: nada que hacer
        }
        if ($instanceId <= 0) {
            // Fila del ledger sin instancia: INCONSISTENCIA auditable, no éxito silencioso (§3).
            return ['created' => 0, 'status' => self::R_ERROR, 'reason' => 'ledger sin instances_id'];
        }
        $subjectRes = $this->resolveSubject($instanceId);
        if ($subjectRes['status'] === 'pending') {
            // Dependencia (clase Instance) no cargada: TEMPORAL → reintentar (fail-closed §3).
            return ['created' => 0, 'status' => self::R_PENDING, 'reason' => 'companyworkflow no disponible'];
        }
        if ($subjectRes['status'] === 'error') {
            // Instancia/sujeto FALTANTE o incoherente: INCONSISTENCIA auditable, NO DONE silencioso (§3).
            return ['created' => 0, 'status' => self::R_ERROR, 'reason' => 'instancia/sujeto inconsistente'];
        }
        $subject   = $subjectRes['subject'];
        $eventDate = (string) ($row['date'] ?? '');
        $meta = $this->decodeMeta($row);

        return match ($event) {
            self::WF_DECISION_RECORDED    => $this->materializeDecision($historyId, $instanceId, $subject, $row, $meta, $eventDate),
            self::WF_TRANSITIONED         => $this->materializeTransition($historyId, $instanceId, $subject, $row, $meta, $eventDate),
            self::WF_APPROVAL_INVALIDATED => $this->materializeInvalidation($historyId, $instanceId, $meta, $eventDate),
            default                       => ['created' => 0, 'status' => self::R_DONE],
        };
    }

    // ------------------------------------------------------------------ decisión por aprobador

    /**
     * @param array{itemtype:string,items_id:int,entities_id:int,is_recursive:int} $subject
     * @param array<string,mixed> $row @param array<string,mixed> $meta
     * @return array{created:int, status:string}
     */
    private function materializeDecision(int $historyId, int $instanceId, array $subject, array $row, array $meta, string $eventDate): array
    {
        $decision = (string) ($meta['decision'] ?? '');
        if (!in_array($decision, self::DECISIONS, true)) {
            return ['created' => 0, 'status' => self::R_DONE]; // p. ej. cancel: no produce evidencia
        }
        $ref = $this->resolveRef($meta, $subject);
        if ($ref['status'] !== 'ok') {
            return ['created' => 0, 'status' => $ref['status'] === 'error' ? self::R_ERROR : self::R_PENDING, 'reason' => 'evidence_ref: ' . $ref['reason']];
        }
        /** @var DocumentVersion $version */
        $version = $ref['version'];
        $key = $this->keys->forHistoryEvent($historyId, $version->versionNumber(), $decision);
        if ($this->recorder->findByKey($key) !== null) {
            return ['created' => 0, 'status' => self::R_DONE];
        }
        $ev = $this->recorder->record($this->baseEvidence($historyId, $instanceId, $subject, $row, $meta, $eventDate, $version) + [
            'idempotency_key'         => $key,
            'decision'                => $decision,
            'event_type'              => ApprovalEvidence::EVENT_DECISION,
            'references_evidences_id' => 0,
        ]);
        return ['created' => $ev !== null ? 1 : 0, 'status' => $ev !== null ? self::R_DONE : self::R_PENDING];
    }

    // ------------------------------------------------------------------ transición (evento separado)

    /**
     * @param array{itemtype:string,items_id:int,entities_id:int,is_recursive:int} $subject
     * @param array<string,mixed> $row @param array<string,mixed> $meta
     * @return array{created:int, status:string}
     */
    private function materializeTransition(int $historyId, int $instanceId, array $subject, array $row, array $meta, string $eventDate): array
    {
        // Una transición SIN evidence_ref (p. ej. submit antes de haber contenido aprobado) no
        // produce evidencia: es un no-op definitivo (no queda pendiente eternamente).
        if (!isset($meta['evidence_ref']) || !is_array($meta['evidence_ref'])) {
            return ['created' => 0, 'status' => self::R_DONE];
        }
        $ref = $this->resolveRef($meta, $subject);
        if ($ref['status'] !== 'ok') {
            return ['created' => 0, 'status' => $ref['status'] === 'error' ? self::R_ERROR : self::R_PENDING, 'reason' => 'evidence_ref: ' . $ref['reason']];
        }
        /** @var DocumentVersion $version */
        $version = $ref['version'];
        $key = $this->keys->forHistoryEvent($historyId, $version->versionNumber(), ApprovalEvidence::EVENT_TRANSITION);
        if ($this->recorder->findByKey($key) !== null) {
            return ['created' => 0, 'status' => self::R_DONE];
        }
        $ev = $this->recorder->record($this->baseEvidence($historyId, $instanceId, $subject, $row, $meta, $eventDate, $version) + [
            'idempotency_key'         => $key,
            'decision'                => ApprovalEvidence::DECISION_TRANSITION,
            'event_type'              => ApprovalEvidence::EVENT_TRANSITION,
            'references_evidences_id' => 0,
        ]);
        return ['created' => $ev !== null ? 1 : 0, 'status' => $ev !== null ? self::R_DONE : self::R_PENDING];
    }

    // ------------------------------------------------------------------ invalidación EXACTA

    /**
     * @param array<string,mixed> $meta
     * @return array{created:int, status:string}
     */
    private function materializeInvalidation(int $historyId, int $instanceId, array $meta, string $eventDate): array
    {
        if ((string) ($meta['idempotency_key'] ?? '') === '') {
            return ['created' => 0, 'status' => self::R_DONE]; // invalidación interna de "devolución": no aplica
        }
        if ($eventDate === '') {
            return ['created' => 0, 'status' => self::R_PENDING];
        }
        $docVersion = (int) ($meta['document_version'] ?? 0);
        $actor = (int) ($meta['actor'] ?? 0); // ACTOR DURABLE reconstruido del ledger (§4)
        $created = 0;
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
                'actor_users_id'          => $actor,
                'actor_role'              => 'invalidator',
                'actor_context'           => ['reason' => (string) ($meta['reason'] ?? ''), 'reopen_to' => (string) ($meta['reopen_to_code'] ?? '')],
                'decision'                => ApprovalEvidence::DECISION_INVALIDATED,
                'event_type'              => ApprovalEvidence::EVENT_INVALIDATION,
                'comment'                 => (string) ($meta['reason'] ?? ''),
                'references_evidences_id' => (int) $approval->getID(),
                'event_date'              => $eventDate,
            ]);
            if ($ev !== null) {
                $created++;
            }
        }
        return ['created' => $created, 'status' => self::R_DONE];
    }

    /**
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
                continue;
            }
            $m = new ApprovalEvidence();
            if ($m->getFromDB($id)) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /** ¿Existe una evidencia de invalidación que referencia EXACTAMENTE a esta evidencia? */
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

    /**
     * Campos comunes de una evidencia de decisión/transición, con `event_date` original del ledger
     * (§1), la versión FIJADA por `evidence_ref` (§2) y el contexto histórico del aprobador (§5).
     *
     * @param array{itemtype:string,items_id:int,entities_id:int,is_recursive:int} $subject
     * @param array<string,mixed> $row @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function baseEvidence(int $historyId, int $instanceId, array $subject, array $row, array $meta, string $eventDate, DocumentVersion $version): array
    {
        $actorContext = [];
        foreach (['statedefs_id', 'steps_id', 'approver_kind', 'approver_ref', 'delegated_from'] as $k) {
            if (array_key_exists($k, $meta)) {
                $actorContext[$k] = $meta[$k];
            }
        }
        $actor = (int) ($meta['actor'] ?? ($row['actor_users_id'] ?? 0));
        return [
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
            'actor_users_id'        => $actor,
            'actor_role'            => (string) ($meta['approver_kind'] ?? ''),
            'actor_context'         => $actorContext,
            'comment'               => (string) ($row['comment'] ?? ''),
            'event_date'            => $eventDate,
        ];
    }

    /**
     * Valida la `evidence_ref` EXPLÍCITA del ledger contra la versión documental real (§2).
     * @param array<string,mixed> $meta
     * @param array{itemtype:string,items_id:int,entities_id:int,is_recursive:int} $subject
     * @return array{status:string, version:?DocumentVersion, reason:string}
     */
    private function resolveRef(array $meta, array $subject): array
    {
        $ref = $meta['evidence_ref'] ?? null;
        if (!is_array($ref)) {
            return ['status' => 'pending', 'version' => null, 'reason' => 'sin evidence_ref']; // no adivinar por fecha
        }
        $dvId  = (int) ($ref['document_versions_id'] ?? 0);
        $dvVer = (int) ($ref['document_version'] ?? 0);
        $dvHash = (string) ($ref['content_sha256'] ?? '');
        if ($dvId <= 0) {
            return ['status' => 'pending', 'version' => null, 'reason' => 'ref incompleta'];
        }
        $v = new DocumentVersion();
        if (!$v->getFromDB($dvId)) {
            return ['status' => 'pending', 'version' => null, 'reason' => 'version aun no existe']; // reintentar
        }
        if ((string) $v->fields['subject_itemtype'] !== $subject['itemtype'] || (int) $v->fields['subject_items_id'] !== $subject['items_id']) {
            return ['status' => 'error', 'version' => null, 'reason' => 'ref de otro sujeto'];
        }
        if ((int) $v->fields['entities_id'] !== $subject['entities_id']) {
            return ['status' => 'error', 'version' => null, 'reason' => 'ref de otra entidad'];
        }
        if ($v->versionNumber() !== $dvVer) {
            return ['status' => 'error', 'version' => null, 'reason' => 'version no coincide'];
        }
        if (!$this->isValidHash($v->contentHash()) || $dvHash === '' || !hash_equals($v->contentHash(), $dvHash)) {
            return ['status' => 'error', 'version' => null, 'reason' => 'content_sha256 no coincide'];
        }
        return ['status' => 'ok', 'version' => $v, 'reason' => ''];
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
     * Resuelve el sujeto de la instancia distinguiendo TEMPORAL (dependencia caída → reintentar) de
     * PERMANENTE (instancia/sujeto faltante o incoherente → error auditable). NUNCA "ok" silencioso
     * ante inconsistencia.
     *
     * @return array{status:string, subject:?array{itemtype:string,items_id:int,entities_id:int,is_recursive:int}}
     */
    private function resolveSubject(int $instanceId): array
    {
        $instClass = 'GlpiPlugin\\Companyworkflow\\Model\\Instance';
        if (!class_exists($instClass)) {
            return ['status' => 'pending', 'subject' => null]; // dependencia caída → reintentar (temporal)
        }
        /** @var \CommonDBTM $inst */
        $inst = new $instClass();
        if (!$inst->getFromDB($instanceId)) {
            return ['status' => 'error', 'subject' => null]; // instancia faltante → inconsistente/auditable
        }
        $itemtype = (string) ($inst->fields['itemtype'] ?? '');
        $itemsId  = (int) ($inst->fields['items_id'] ?? 0);
        if ($itemtype === '' || $itemsId <= 0) {
            return ['status' => 'error', 'subject' => null]; // instancia sin sujeto → inconsistente
        }
        return ['status' => 'ok', 'subject' => [
            'itemtype'     => $itemtype,
            'items_id'     => $itemsId,
            'entities_id'  => (int) ($inst->fields['entities_id'] ?? 0),
            'is_recursive' => (int) ($inst->fields['is_recursive'] ?? 0),
        ]];
    }

    /** @return object|null instancia de WorkflowApi de companyworkflow, o null si no está disponible */
    protected function workflowApi(): ?object
    {
        $cls = 'GlpiPlugin\\Companyworkflow\\Api\\WorkflowApi';
        return class_exists($cls) ? new $cls() : null;
    }
}
