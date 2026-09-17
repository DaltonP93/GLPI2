<?php

/**
 * Fachada PHP de companysignature para plugins de dominio (companypurchasing y futuros) y para el
 * propio plugin. Domain-agnostic (D4/D5): el dominio suministra el **snapshot canónico**; Firma
 * canonicaliza, hashea, versiona (inmutable), compone el PDF nativo (D1) y verifica.
 *
 * Fail-closed: valida ACL (`plugin_companysignature`) y multi-entidad antes de escribir.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Api;

use Session;
use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;
use GlpiPlugin\Companysignature\Service\ApprovedPdfComposer;
use GlpiPlugin\Companysignature\Service\VerificationService;
use GlpiPlugin\Companysignature\Service\VersionStore;

final class SignatureApi
{
    private VersionStore $versions;
    private ApprovedPdfComposer $composer;
    private VerificationService $verifier;

    public function __construct(
        ?VersionStore $versions = null,
        ?ApprovedPdfComposer $composer = null,
        ?VerificationService $verifier = null
    ) {
        $this->versions = $versions ?? new VersionStore();
        $this->composer = $composer ?? new ApprovedPdfComposer($this->versions);
        $this->verifier = $verifier ?? new VerificationService();
    }

    /**
     * Registra (idempotente, inmutable) una versión documental a partir de un snapshot canónico.
     *
     * @param array<string,mixed> $snapshot  { schema, subject_type, subject_id, entity_id, document_version, payload }
     * @throws \InvalidArgumentException  ACL/entidad/contrato inválidos
     */
    public function recordDocumentVersion(array $snapshot, bool $isSubstantive = true): DocumentVersion
    {
        if (!Session::haveRight(ApprovalEvidence::$rightname, ApprovalEvidence::RIGHT_RECORD)) {
            throw new \InvalidArgumentException('sin derecho para registrar versión documental');
        }
        $entityId = (int) ($snapshot['entity_id'] ?? 0);
        if (!Session::haveAccessToEntity($entityId)) {
            throw new \InvalidArgumentException('sin acceso a la entidad del snapshot');
        }
        $author = (int) (Session::getLoginUserID() ?: 0);
        return $this->versions->record($snapshot, $isSubstantive, 0, $author);
    }

    /** Genera (idempotente) el PDF aprobado como `Document` nativo. No bloquea la evidencia. */
    public function composePdf(int $versionId): DocumentVersion
    {
        return $this->composer->compose($versionId);
    }

    /**
     * Verifica un `verification_token` (interna autenticada + ACL + multi-entidad).
     * @return array{status:string, evidence:?array<string,mixed>}
     */
    public function verify(string $token): array
    {
        return $this->verifier->verify($token);
    }

    public function versions(): VersionStore
    {
        return $this->versions;
    }
}
