<?php

/**
 * Registrador de evidencia APPEND-ONLY e IDEMPOTENTE (gate §13).
 *
 * - **Idempotente**: la `idempotency_key` (UNIQUE) evita dos evidencias para la misma decisión ante
 *   retry · evento duplicado · restart · doble entrega. Si ya existe → devuelve la existente (no-op).
 * - **Append-only**: nunca hace `UPDATE`/`DELETE` de negocio; una invalidación es OTRA fila que
 *   referencia (`references_evidences_id`) a la evidencia previa.
 * - **Timestamp UTC** + zona de PRESENTACIÓN (config). `verification_token` opaco/aleatorio.
 * - Deja rastro en el **`Log` nativo** del sujeto (best-effort) y **emite** un evento propio.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use GlpiPlugin\Companysignature\Model\ApprovalEvidence;

final class EvidenceRecorder
{
    private TokenGenerator $tokens;

    public function __construct(?TokenGenerator $tokens = null)
    {
        $this->tokens = $tokens ?? new TokenGenerator();
    }

    /**
     * Registra (idempotente) una evidencia. Devuelve la evidencia (nueva o preexistente) o null si falla.
     *
     * @param array{
     *   idempotency_key:string, subject_itemtype:string, subject_items_id:int, entities_id:int,
     *   is_recursive?:int, workflow_instances_id?:int, workflow_event_ref?:string,
     *   document_versions_id?:int, document_version?:int, content_sha256?:string,
     *   actor_users_id?:int, actor_role?:string, actor_context?:array<string,mixed>,
     *   decision:string, event_type:string, comment?:string, references_evidences_id?:int
     * } $p
     */
    public function record(array $p): ?ApprovalEvidence
    {
        /** @var \DBmysql $DB */
        global $DB;

        $idem = trim((string) ($p['idempotency_key'] ?? ''));
        if ($idem === '') {
            return null; // sin clave no hay garantía de idempotencia → fail-closed
        }

        // 1) Idempotencia: ¿ya existe?
        $existing = $this->findByKey($idem);
        if ($existing !== null) {
            return $existing;
        }

        // 2) Inserción append-only, transaccional.
        $nowUtc = gmdate('Y-m-d H:i:s');
        $tz     = PluginConfig::presentationTimezone();
        $ctx    = $p['actor_context'] ?? [];

        $DB->beginTransaction();
        try {
            $evidence = new ApprovalEvidence();
            $id = 0;
            // Reintenta ante colisión (improbable) del token; la idempotency_key es la garantía real.
            for ($attempt = 0; $attempt < 5 && $id <= 0; $attempt++) {
                $token = $this->tokens->generate();
                $id = (int) $evidence->add([
                    'verification_token'      => $token,
                    'idempotency_key'         => $idem,
                    'workflow_instances_id'   => (int) ($p['workflow_instances_id'] ?? 0),
                    'workflow_event_ref'      => (string) ($p['workflow_event_ref'] ?? ''),
                    'subject_itemtype'        => (string) $p['subject_itemtype'],
                    'subject_items_id'        => (int) $p['subject_items_id'],
                    'entities_id'             => (int) $p['entities_id'],
                    'is_recursive'            => (int) ($p['is_recursive'] ?? 0),
                    'document_versions_id'    => (int) ($p['document_versions_id'] ?? 0),
                    'document_version'        => (int) ($p['document_version'] ?? 0),
                    'content_sha256'          => (string) ($p['content_sha256'] ?? ''),
                    'actor_users_id'          => (int) ($p['actor_users_id'] ?? 0),
                    'actor_role'              => (string) ($p['actor_role'] ?? ''),
                    'actor_context'           => $ctx !== [] ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : null,
                    'decision'                => (string) $p['decision'],
                    'event_type'              => (string) $p['event_type'],
                    'comment'                 => ($p['comment'] ?? '') !== '' ? (string) $p['comment'] : null,
                    'references_evidences_id' => (int) ($p['references_evidences_id'] ?? 0),
                    'event_date'              => $nowUtc,
                    'presentation_timezone'   => $tz,
                    'date_creation'           => $nowUtc,
                ]);
            }
            if ($id <= 0) {
                // Posible carrera sobre la UNIQUE(idempotency_key): releer y devolver la ganadora.
                $this->safeRollback($DB);
                return $this->findByKey($idem);
            }
            $DB->commit();
            $evidence->getFromDB($id);
        } catch (\Throwable $e) {
            $this->safeRollback($DB);
            // Carrera dura sobre la UNIQUE: si otro proceso la creó, devolverla (idempotente).
            $winner = $this->findByKey($idem);
            return $winner; // null si realmente falló
        }

        // 3) Efectos secundarios best-effort (no revierten lo confirmado).
        $this->writeNativeLog($evidence);
        $this->emit(
            $evidence->fields['event_type'] === ApprovalEvidence::EVENT_INVALIDATION
                ? 'companysignature:approval_invalidated'
                : 'companysignature:evidence_recorded',
            [
                'evidence_id'          => (int) $evidence->getID(),
                'subject_itemtype'     => (string) $evidence->fields['subject_itemtype'],
                'subject_items_id'     => (int) $evidence->fields['subject_items_id'],
                'entities_id'          => (int) $evidence->fields['entities_id'],
                'decision'             => (string) $evidence->fields['decision'],
                'document_version'     => (int) $evidence->fields['document_version'],
                'content_sha256'       => (string) $evidence->fields['content_sha256'],
                'verification_token'   => (string) $evidence->fields['verification_token'],
            ]
        );

        return $evidence;
    }

    public function findByKey(string $idempotencyKey): ?ApprovalEvidence
    {
        $m = new ApprovalEvidence();
        if ($idempotencyKey !== '' && $m->getFromDBByCrit(['idempotency_key' => $idempotencyKey])) {
            return $m;
        }
        return null;
    }

    public function findByToken(string $token): ?ApprovalEvidence
    {
        $m = new ApprovalEvidence();
        if ($token !== '' && $m->getFromDBByCrit(['verification_token' => $token])) {
            return $m;
        }
        return null;
    }

    private function writeNativeLog(ApprovalEvidence $evidence): void
    {
        try {
            if (!class_exists('Log')) {
                return;
            }
            $subjectType = (string) $evidence->fields['subject_itemtype'];
            $subjectId   = (int) $evidence->fields['subject_items_id'];
            if ($subjectType === '' || $subjectId <= 0 || !class_exists($subjectType)) {
                return;
            }
            $msg = sprintf(
                '[companysignature] %s v%d sha256:%s token:%s',
                (string) $evidence->fields['decision'],
                (int) $evidence->fields['document_version'],
                substr((string) $evidence->fields['content_sha256'], 0, 12),
                (string) $evidence->fields['verification_token']
            );
            \Log::history($subjectId, $subjectType, [0, '', $msg], '', \Log::HISTORY_LOG_SIMPLE_MESSAGE);
        } catch (\Throwable) {
            // best-effort
        }
    }

    /** @param array<string,mixed> $payload */
    private function emit(string $name, array $payload): void
    {
        if (!PluginConfig::boolean('emit_events')) {
            return;
        }
        try {
            if (class_exists(\Plugin::class) && method_exists(\Plugin::class, 'doHookFunction')) {
                \Plugin::doHookFunction($name, $payload);
            }
        } catch (\Throwable) {
            // best-effort
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
}
