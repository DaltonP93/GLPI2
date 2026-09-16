<?php

/**
 * Listener de eventos de `companyworkflow` (gate §4/§10/§13).
 *
 * Consume:
 *   - `companyworkflow:transitioned`        → registra evidencia de la DECISIÓN (approve/reject/return).
 *   - `companyworkflow:approval_invalidated`→ registra evidencia de INVALIDACIÓN (append-only) que
 *                                             referencia a la aprobación previa. Nunca borra/modifica.
 *
 * IDEMPOTENTE: tolera retry · evento duplicado · restart · doble entrega (clave estable + versión
 * documental + tipo). `companysignature` NO reimplementa quórum/estados/aprobadores: eso es de
 * `companyworkflow`. Domain-agnostic (D5): no conoce Compras.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use Session;
use GlpiPlugin\Companysignature\Model\ApprovalEvidence;

final class WorkflowEventListener
{
    private EvidenceRecorder $recorder;
    private VersionStore $versions;
    private IdempotencyKey $keys;

    /** Mapa acción de workflow → decisión de evidencia (otras acciones se ignoran). */
    private const DECISION_MAP = [
        'approve' => ApprovalEvidence::DECISION_APPROVED,
        'reject'  => ApprovalEvidence::DECISION_REJECTED,
        'return'  => ApprovalEvidence::DECISION_RETURNED,
    ];

    public function __construct(?EvidenceRecorder $recorder = null, ?VersionStore $versions = null, ?IdempotencyKey $keys = null)
    {
        $this->recorder = $recorder ?? new EvidenceRecorder();
        $this->versions = $versions ?? new VersionStore();
        $this->keys     = $keys ?? new IdempotencyKey();
    }

    /**
     * @param array<string,mixed> $payload  { instances_id, from, to, action, actor, ... }
     */
    public function onTransitioned(array $payload): ?ApprovalEvidence
    {
        if (!PluginConfig::boolean('listen_workflow_events')) {
            return null;
        }
        $action = (string) ($payload['action'] ?? '');
        if (!isset(self::DECISION_MAP[$action])) {
            return null; // submit/cancel/etc. no generan evidencia de decisión
        }
        $subject = $this->resolveSubject((int) ($payload['instances_id'] ?? 0));
        if ($subject === null) {
            return null;
        }
        $decision = self::DECISION_MAP[$action];
        $eventRef = (string) ($payload['from'] ?? '') . '->' . (string) ($payload['to'] ?? '') . ':' . $action;

        $latest  = $this->versions->latest($subject['itemtype'], $subject['items_id']);
        $docVer  = $latest !== null ? $latest->versionNumber() : 0;
        $verId   = $latest !== null ? (int) $latest->getID() : 0;
        $content = $latest !== null ? $latest->contentHash() : '';

        $idem = $this->keys->forDecision((int) $payload['instances_id'], $eventRef, $docVer, $decision);

        return $this->recorder->record([
            'idempotency_key'       => $idem,
            'subject_itemtype'      => $subject['itemtype'],
            'subject_items_id'      => $subject['items_id'],
            'entities_id'           => $subject['entities_id'],
            'is_recursive'          => $subject['is_recursive'],
            'workflow_instances_id' => (int) $payload['instances_id'],
            'workflow_event_ref'    => $eventRef,
            'document_versions_id'  => $verId,
            'document_version'      => $docVer,
            'content_sha256'        => $content,
            'actor_users_id'        => (int) ($payload['actor'] ?? (Session::getLoginUserID() ?: 0)),
            'actor_role'            => '',
            'actor_context'         => ['action' => $action, 'from' => (string) ($payload['from'] ?? ''), 'to' => (string) ($payload['to'] ?? '')],
            'decision'              => $decision,
            'event_type'            => ApprovalEvidence::EVENT_DECISION,
            'comment'               => (string) ($payload['comment'] ?? ''),
            'references_evidences_id' => 0,
        ]);
    }

    /**
     * @param array<string,mixed> $payload  { instances_id, reason, from, to, idempotency_key, actor, context }
     */
    public function onApprovalInvalidated(array $payload): ?ApprovalEvidence
    {
        if (!PluginConfig::boolean('listen_workflow_events')) {
            return null;
        }
        $subject = $this->resolveSubject((int) ($payload['instances_id'] ?? 0));
        if ($subject === null) {
            return null;
        }
        $latest  = $this->versions->latest($subject['itemtype'], $subject['items_id']);
        $docVer  = $latest !== null ? $latest->versionNumber() : 0;
        $verId   = $latest !== null ? (int) $latest->getID() : 0;
        $content = $latest !== null ? $latest->contentHash() : '';

        $prior = $this->latestApproval($subject['itemtype'], $subject['items_id']);
        $wfIdem = (string) ($payload['idempotency_key'] ?? '');
        $idem = $this->keys->forInvalidation((int) $payload['instances_id'], $wfIdem, $docVer);

        return $this->recorder->record([
            'idempotency_key'       => $idem,
            'subject_itemtype'      => $subject['itemtype'],
            'subject_items_id'      => $subject['items_id'],
            'entities_id'           => $subject['entities_id'],
            'is_recursive'          => $subject['is_recursive'],
            'workflow_instances_id' => (int) $payload['instances_id'],
            'workflow_event_ref'    => 'invalidated:' . (string) ($payload['from'] ?? '') . '->' . (string) ($payload['to'] ?? ''),
            'document_versions_id'  => $verId,
            'document_version'      => $docVer,
            'content_sha256'        => $content,
            'actor_users_id'        => (int) ($payload['actor'] ?? (Session::getLoginUserID() ?: 0)),
            'actor_role'            => '',
            'actor_context'         => ['reason' => (string) ($payload['reason'] ?? '')],
            'decision'              => ApprovalEvidence::DECISION_INVALIDATED,
            'event_type'            => ApprovalEvidence::EVENT_INVALIDATION,
            'comment'               => (string) ($payload['reason'] ?? ''),
            'references_evidences_id' => $prior !== null ? (int) $prior->getID() : 0,
        ]);
    }

    /**
     * Resuelve el sujeto (itemtype/items_id/entidad) de una instancia de `companyworkflow`.
     * @return array{itemtype:string, items_id:int, entities_id:int, is_recursive:int}|null
     */
    private function resolveSubject(int $instanceId): ?array
    {
        if ($instanceId <= 0) {
            return null;
        }
        $instClass = 'GlpiPlugin\\Companyworkflow\\Model\\Instance';
        if (!class_exists($instClass)) {
            return null; // companyworkflow no disponible → no-op
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

    private function latestApproval(string $subjectType, int $subjectId): ?ApprovalEvidence
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => ApprovalEvidence::getTable(),
            'WHERE'  => [
                'subject_itemtype' => $subjectType,
                'subject_items_id' => $subjectId,
                'decision'         => ApprovalEvidence::DECISION_APPROVED,
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]) as $row) {
            $m = new ApprovalEvidence();
            if ($m->getFromDB((int) $row['id'])) {
                return $m;
            }
        }
        return null;
    }
}
