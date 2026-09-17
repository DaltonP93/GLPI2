<?php

/**
 * Verificación INTERNA AUTENTICADA de una evidencia por su `verification_token` (gate §7/§13).
 *
 * Principio heredado de companyqr: **"el token identifica; GLPI autoriza"**. NO hay verificador
 * anónimo/público en v1. Exige: login · ACL (`RIGHT_VERIFY`) · multi-entidad estricta · no revelar
 * datos de otra entidad · token no enumerable · auditoría de la verificación.
 *
 * Recomputa el hash de la versión referida a partir del **snapshot canónico exacto** almacenado y
 * confirma que coincide con `content_sha256` (íntegro/no manipulado). Refleja la **invalidación**:
 * si una evidencia de invalidación posterior existe para el mismo sujeto, el estado es `invalidated`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use Session;
use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;

final class VerificationService
{
    public const STATUS_VALID       = 'valid';
    public const STATUS_INVALIDATED = 'invalidated';
    public const STATUS_TAMPERED    = 'tampered';
    public const STATUS_NOT_FOUND   = 'not_found';
    public const STATUS_DENIED      = 'denied';

    private Hasher $hasher;

    public function __construct(?Hasher $hasher = null)
    {
        $this->hasher = $hasher ?? new Hasher();
    }

    /**
     * Verifica un token. Devuelve un resultado neutro que NO filtra existencia ni datos de otra
     * entidad (para tokens inválidos o fuera de ACL, el estado es `not_found`/`denied`).
     *
     * @return array{status:string, evidence:?array<string,mixed>}
     */
    public function verify(string $token): array
    {
        // ACL mínima: derecho de verificación.
        if (!Session::haveRight(ApprovalEvidence::$rightname, ApprovalEvidence::RIGHT_VERIFY)) {
            return ['status' => self::STATUS_DENIED, 'evidence' => null];
        }
        if (!TokenGenerator::isWellFormed($token)) {
            return ['status' => self::STATUS_NOT_FOUND, 'evidence' => null];
        }

        $evidence = new ApprovalEvidence();
        if (!$evidence->getFromDBByCrit(['verification_token' => $token])) {
            $this->audit($token, self::STATUS_NOT_FOUND, 0);
            return ['status' => self::STATUS_NOT_FOUND, 'evidence' => null];
        }

        // Multi-entidad ESTRICTA: si el verificador no tiene acceso a la entidad de la evidencia,
        // respondemos `not_found` (no revelamos que existe en otra entidad).
        $ent = (int) ($evidence->fields['entities_id'] ?? 0);
        $rec = (int) ($evidence->fields['is_recursive'] ?? 0);
        if (!Session::haveAccessToEntity($ent, (bool) $rec)) {
            $this->audit($token, self::STATUS_NOT_FOUND, (int) $evidence->getID());
            return ['status' => self::STATUS_NOT_FOUND, 'evidence' => null];
        }

        // Integridad FAIL-CLOSED (§2): SIN versión/snapshot/hash válido, el estado NUNCA puede ser
        // `valid`. Se recomputa el hash del snapshot canónico exacto y debe coincidir con el
        // almacenado en la versión Y con el fijado en la evidencia.
        $status = self::STATUS_VALID;
        $version = new DocumentVersion();
        $verId   = (int) ($evidence->fields['document_versions_id'] ?? 0);
        $evStored = (string) ($evidence->fields['content_sha256'] ?? '');
        if ($verId <= 0 || !$version->getFromDB($verId)) {
            $status = self::STATUS_TAMPERED; // falta la versión referida
        } else {
            $canonical = (string) ($version->fields['canonical_snapshot'] ?? '');
            $stored    = (string) ($version->fields['content_sha256'] ?? '');
            if ($canonical === '' || !$this->isValidHash($stored) || !$this->isValidHash($evStored)) {
                $status = self::STATUS_TAMPERED; // snapshot/hash ausente o malformado
            } else {
                $recomputed = $this->hasher->sha256($canonical);
                if (!hash_equals($stored, $recomputed) || !hash_equals($evStored, $recomputed)) {
                    $status = self::STATUS_TAMPERED; // el contenido no reproduce el hash
                }
            }
        }

        // Refleja invalidación EXACTA (§5): sólo si ESTA evidencia fue referenciada por una invalidación.
        if ($status === self::STATUS_VALID && $this->isSuperseded($evidence)) {
            $status = self::STATUS_INVALIDATED;
        }

        $this->audit($token, $status, (int) $evidence->getID());

        return [
            'status'   => $status,
            'evidence' => $this->present($evidence, $version),
        ];
    }

    /**
     * ¿Esta evidencia fue invalidada? EXACTO (§5): existe una evidencia de invalidación cuyo
     * `references_evidences_id` apunta precisamente a ESTA evidencia. Una invalidación del workflow A
     * NO supersede una aprobación del workflow B, aunque compartan sujeto.
     */
    private function isSuperseded(ApprovalEvidence $evidence): bool
    {
        if ((string) $evidence->fields['event_type'] === ApprovalEvidence::EVENT_INVALIDATION) {
            return false; // la propia invalidación no se auto-supersede
        }
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'COUNT' => 'c',
            'FROM'  => ApprovalEvidence::getTable(),
            'WHERE' => ['event_type' => ApprovalEvidence::EVENT_INVALIDATION, 'references_evidences_id' => (int) $evidence->getID()],
        ]) as $row) {
            return ((int) $row['c']) > 0;
        }
        return false;
    }

    private function isValidHash(string $hash): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $hash) === 1;
    }

    /**
     * Proyección de identidad MÍNIMA necesaria (quién/cuándo aprobó), ya validada la ACL/entidad.
     * @return array<string,mixed>
     */
    private function present(ApprovalEvidence $evidence, DocumentVersion $version): array
    {
        $tz = (string) ($evidence->fields['presentation_timezone'] ?? 'UTC');
        return [
            'decision'          => (string) $evidence->fields['decision'],
            'event_type'        => (string) $evidence->fields['event_type'],
            'subject_itemtype'  => (string) $evidence->fields['subject_itemtype'],
            'subject_items_id'  => (int) $evidence->fields['subject_items_id'],
            'entities_id'       => (int) $evidence->fields['entities_id'],
            'document_version'  => (int) $evidence->fields['document_version'],
            'content_sha256'    => (string) $evidence->fields['content_sha256'],
            'actor_users_id'    => (int) $evidence->fields['actor_users_id'],
            'actor_role'        => (string) $evidence->fields['actor_role'],
            'event_date_utc'    => (string) $evidence->fields['event_date'],
            'presentation_tz'   => $tz,
            'event_date_local'  => $this->toLocal((string) $evidence->fields['event_date'], $tz),
            'pdf_status'        => (string) ($version->fields['pdf_status'] ?? ''),
            'pdf_sha256'        => (string) ($version->fields['pdf_sha256'] ?? ''),
            'documents_id'      => (int) ($version->fields['documents_id'] ?? 0),
        ];
    }

    private function toLocal(string $utc, string $tz): string
    {
        if ($utc === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $dt->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $utc;
        }
    }

    private function audit(string $token, string $status, int $evidenceId): void
    {
        try {
            if (class_exists('Log') && $evidenceId > 0) {
                $actor = (int) (Session::getLoginUserID() ?: 0);
                \Log::history(
                    $evidenceId,
                    ApprovalEvidence::class,
                    [0, '', sprintf('[companysignature] verify status=%s by user=%d', $status, $actor)],
                    '',
                    \Log::HISTORY_LOG_SIMPLE_MESSAGE
                );
            }
        } catch (\Throwable) {
            // best-effort; la verificación es de sólo lectura.
        }
    }
}
