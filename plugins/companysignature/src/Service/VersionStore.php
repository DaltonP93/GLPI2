<?php

/**
 * Persistencia de versiones documentales INMUTABLES (decisión D1).
 *
 * `record()` es idempotente e inmutable:
 *   - si ya existe la (sujeto, versión) con el MISMO `content_sha256` → la devuelve (no duplica);
 *   - si existe con hash DISTINTO → viola la inmutabilidad → falla (fail-closed);
 *   - si no existe → inserta una fila nueva con el snapshot canónico EXACTO + `content_sha256`.
 *
 * La parte probatoria (`canonical_snapshot`, `content_sha256`) es inmutable. Sólo los campos del
 * artefacto PDF derivado (`documents_id`, `pdf_sha256`, `pdf_status`) se completan/reintentan luego
 * (`markPdf*`), sin tocar la prueba.
 *
 * El número de versión lo declara el DOMINIO dentro del snapshot (`document_version`), coherente con
 * el contrato D4 (companysignature no decide campos de negocio ni numeración).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use GlpiPlugin\Companysignature\Model\DocumentVersion;

final class VersionStore
{
    private Canonicalizer $canonicalizer;
    private Hasher $hasher;

    public function __construct(?Canonicalizer $canonicalizer = null, ?Hasher $hasher = null)
    {
        $this->canonicalizer = $canonicalizer ?? new Canonicalizer();
        $this->hasher        = $hasher ?? new Hasher($this->canonicalizer);
    }

    /**
     * Registra (idempotente) una versión inmutable a partir de un snapshot canónico.
     *
     * @param array<string,mixed> $snapshot  { schema, subject_type, subject_id, entity_id, document_version, payload }
     * @throws \InvalidArgumentException  snapshot inválido / violación de inmutabilidad
     * @throws \RuntimeException          fallo de persistencia
     */
    public function record(array $snapshot, bool $isSubstantive = true, int $isRecursive = 0, int $authorUserId = 0): DocumentVersion
    {
        /** @var \DBmysql $DB */
        global $DB;

        $canonical = $this->canonicalizer->canonical($snapshot); // valida contrato + fail-closed en floats
        $hash      = $this->hasher->sha256($canonical);

        $subjectType = (string) $snapshot['subject_type'];
        $subjectId   = (int) $snapshot['subject_id'];
        $entityId    = (int) $snapshot['entity_id'];
        $version     = (int) $snapshot['document_version'];
        if ($subjectType === '' || $subjectId <= 0 || $version <= 0) {
            throw new \InvalidArgumentException('snapshot inválido: subject_type/subject_id/document_version requeridos');
        }

        $existing = $this->find($subjectType, $subjectId, $version);
        if ($existing !== null) {
            if (!hash_equals($existing->contentHash(), $hash)) {
                throw new \InvalidArgumentException(
                    "inmutabilidad violada: la versión {$version} de {$subjectType}#{$subjectId} ya existe con otro contenido"
                );
            }
            return $existing; // idempotente
        }

        $now = $_SESSION['glpi_currenttime'] ?? gmdate('Y-m-d H:i:s');
        $model = new DocumentVersion();
        $id = (int) $model->add([
            'subject_itemtype' => $subjectType,
            'subject_items_id' => $subjectId,
            'entities_id'      => $entityId,
            'is_recursive'     => $isRecursive,
            'version'          => $version,
            'schema_id'        => (string) $snapshot['schema'],
            'canonical_snapshot' => $canonical,
            'content_sha256'   => $hash,
            'pdf_sha256'       => null,
            'documents_id'     => 0,
            'pdf_status'       => DocumentVersion::PDF_PENDING,
            'is_substantive'   => $isSubstantive ? 1 : 0,
            'users_id_author'  => $authorUserId,
            'date_creation'    => $now,
        ]);
        if ($id <= 0) {
            // UNIQUE(subject_itemtype, subject_items_id, version): carrera → releer.
            $again = $this->find($subjectType, $subjectId, $version);
            if ($again !== null && hash_equals($again->contentHash(), $hash)) {
                return $again;
            }
            throw new \RuntimeException('no se pudo persistir la versión documental');
        }
        $model->getFromDB($id);
        return $model;
    }

    public function find(string $subjectType, int $subjectId, int $version): ?DocumentVersion
    {
        $m = new DocumentVersion();
        if ($m->getFromDBByCrit([
            'subject_itemtype' => $subjectType,
            'subject_items_id' => $subjectId,
            'version'          => $version,
        ])) {
            return $m;
        }
        return null;
    }

    /**
     * Versión documental EN VIGOR en un instante dado: la de mayor `version` cuya `date_creation`
     * es ≤ `$atDatetime`. Regla determinista compartida por el listener en vivo y el reconciliador,
     * de modo que una decisión se vincula a la versión que existía cuando ocurrió (no a una posterior).
     *
     * @param string $atDatetime  'Y-m-d H:i:s' (hora del evento de historial)
     */
    public function versionInEffectAt(string $subjectType, int $subjectId, string $atDatetime): ?DocumentVersion
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($subjectType === '' || $subjectId <= 0 || $atDatetime === '') {
            return null;
        }
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => DocumentVersion::getTable(),
            'WHERE'  => [
                'subject_itemtype' => $subjectType,
                'subject_items_id' => $subjectId,
                ['date_creation'   => ['<=', $atDatetime]],
            ],
            'ORDER'  => 'version DESC',
            'LIMIT'  => 1,
        ]) as $row) {
            $m = new DocumentVersion();
            if ($m->getFromDB((int) $row['id'])) {
                return $m;
            }
        }
        return null;
    }

    /** Última versión (mayor `version`) del sujeto, o null si aún no hay ninguna. */
    public function latest(string $subjectType, int $subjectId): ?DocumentVersion
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => DocumentVersion::getTable(),
            'WHERE'  => ['subject_itemtype' => $subjectType, 'subject_items_id' => $subjectId],
            'ORDER'  => 'version DESC',
            'LIMIT'  => 1,
        ]) as $row) {
            $m = new DocumentVersion();
            if ($m->getFromDB((int) $row['id'])) {
                return $m;
            }
        }
        return null;
    }

    /** Marca el artefacto PDF como generado (derivado; no toca la prueba). Idempotente por naturaleza. */
    public function markPdfReady(int $versionId, int $documentsId, string $pdfSha256): bool
    {
        $m = new DocumentVersion();
        if (!$m->getFromDB($versionId)) {
            return false;
        }
        return (bool) $m->update([
            'id'           => $versionId,
            'documents_id' => $documentsId,
            'pdf_sha256'   => $pdfSha256,
            'pdf_status'   => DocumentVersion::PDF_READY,
        ]);
    }

    /** Marca el artefacto PDF en error (la evidencia NO se ve afectada; se puede reintentar). */
    public function markPdfError(int $versionId): bool
    {
        $m = new DocumentVersion();
        if (!$m->getFromDB($versionId)) {
            return false;
        }
        return (bool) $m->update([
            'id'         => $versionId,
            'pdf_status' => DocumentVersion::PDF_ERROR,
        ]);
    }
}
