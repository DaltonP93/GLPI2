<?php

/**
 * Puerta ÚNICA de companypurchasing hacia `companysignature` (P2D-2).
 *
 * Compras NUNCA canonicaliza/hashea/compone evidencia ni escribe tablas de Firma: sólo llama a
 * `SignatureApi` (`recordDocumentVersion`, `composePdf`). Devuelve arrays planos para no acoplar el
 * orquestador a los modelos de Firma. Fail-closed si el plugin no está disponible.
 *
 * No es `final`: los tests deterministas (p. ej. "el PDF falla") la subclasean.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companysignature\Api\SignatureApi;

class SignatureGateway
{
    private ?SignatureApi $api = null;

    public function available(): bool
    {
        return class_exists(SignatureApi::class);
    }

    protected function api(): SignatureApi
    {
        if (!$this->available()) {
            throw new \RuntimeException('companysignature no disponible (fail-closed)');
        }
        return $this->api ??= new SignatureApi();
    }

    /**
     * Registra (idempotente/inmutable en Firma) la versión documental del snapshot.
     *
     * @param array<string,mixed> $snapshot  envoltura de `ScopeSnapshotBuilder::envelope()`
     * @return array{document_versions_id:int, document_version:int, content_sha256:string}
     */
    public function record(array $snapshot): array
    {
        $dv = $this->api()->recordDocumentVersion($snapshot, true);
        $id = (int) $dv->getID();
        $hash = $dv->contentHash();
        if ($id <= 0 || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new \RuntimeException('Firma devolvió una versión documental inválida (fail-closed)');
        }
        return ['document_versions_id' => $id, 'document_version' => $dv->versionNumber(), 'content_sha256' => $hash];
    }

    /**
     * Compone (idempotente) el PDF aprobado como Document nativo.
     * @return array{status:string, documents_id:int}
     */
    public function composePdf(int $documentVersionsId): array
    {
        $dv = $this->api()->composePdf($documentVersionsId);
        return ['status' => (string) ($dv->fields['pdf_status'] ?? ''), 'documents_id' => (int) ($dv->fields['documents_id'] ?? 0)];
    }
}
