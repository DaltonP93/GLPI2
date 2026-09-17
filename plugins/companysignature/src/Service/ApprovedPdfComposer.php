<?php

/**
 * Compositor del PDF aprobado como **`Document` NATIVO** de GLPI (decisión D1).
 *
 * - El PDF es un artefacto DERIVADO de una versión documental inmutable: lleva `version` + hash y
 *   un QR al endpoint propio de verificación (D3). Usa **TCPDF** (dependencia nativa; misma que
 *   `companyqr`).
 * - Se guarda como `Document` + `Document_Item` → hereda ACL/entidad/almacenamiento del core.
 * - **No bloquea la aprobación**: si la generación/alta falla DESPUÉS de registrar la evidencia, la
 *   evidencia permanece; la versión se marca `pdf_status=error` y se puede **reintentar**.
 * - **Idempotente**: si la versión ya tiene un `Document` válido, no se crea otro (mismo PDF lógico).
 *
 * REGLA 0: usa (no modifica) TCPDF, `Document` y `Document_Item` nativos.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use Document;
use Document_Item;
use TCPDF;
use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;

final class ApprovedPdfComposer
{
    /**
     * Última excepción capturada al materializar el PDF (clase + mensaje, SIN secretos). El PDF nunca
     * bloquea la evidencia, por eso se traga la excepción y se marca `pdf_status=error`; pero el motivo
     * REAL queda aquí para diagnóstico (p. ej. el selftest lo imprime), en vez de perderse.
     */
    public static ?string $lastError = null;

    private VersionStore $versions;
    private VerificationQrRenderer $qr;

    public function __construct(?VersionStore $versions = null, ?VerificationQrRenderer $qr = null)
    {
        $this->versions = $versions ?? new VersionStore();
        $this->qr       = $qr ?? new VerificationQrRenderer();
    }

    /**
     * Genera (idempotente) el PDF aprobado de una versión y lo persiste como `Document` nativo.
     * Devuelve la versión recargada (con `pdf_status`/`documents_id`/`pdf_sha256`).
     *
     * @throws \InvalidArgumentException  versión inexistente
     */
    public function compose(int $versionId): DocumentVersion
    {
        /** @var \DBmysql $DB */
        global $DB;

        self::$lastError = null;
        $version = new DocumentVersion();
        if ($versionId <= 0 || !$version->getFromDB($versionId)) {
            throw new \InvalidArgumentException('versión documental inexistente');
        }

        $table = DocumentVersion::getTable();
        $now   = $_SESSION['glpi_currenttime'] ?? gmdate('Y-m-d H:i:s');

        // CONCURRENCY-SAFE (§6): serializar la materialización del PDF por `document_versions_id` con
        // un LOCK CON NOMBRE de MySQL (GET_LOCK), INDEPENDIENTE de transacciones. No se puede envolver
        // `Document::add()` en un `beginTransaction()` + `FOR UPDATE` propio: el `Document` nativo
        // gestiona SUS PROPIAS transacciones/commits y hace E/S de fichero, por lo que anidar rompería
        // nuestro commit y dejaría `pdf_status=error`. El lock advisory serializa a dos workers sin
        // acoplarse a la gestión de transacciones del core: el segundo espera, re-lee `documents_id` y
        // reutiliza el Document ya creado. Se conserva el recovery por marcador (Document creado →
        // caída antes de marcar READY) y el relink idempotente del `Document_Item`.
        $lock = $this->lockName($versionId);
        $held = $this->acquireLock($lock, 10);
        try {
            // Re-lectura BAJO LOCK: otro worker pudo haber materializado ya la versión.
            $version->getFromDB($versionId);
            $existingDocId = (int) ($version->fields['documents_id'] ?? 0);
            if ($existingDocId > 0
                && (string) ($version->fields['pdf_status'] ?? '') === DocumentVersion::PDF_READY
                && (new Document())->getFromDB($existingDocId)) {
                return $version; // idempotente: ya materializado y válido
            }

            $bytes  = $this->renderPdf($version);
            $pdfSha = hash('sha256', $bytes);
            $docId  = $this->storeAsDocument($version, $bytes, $pdfSha); // marker search/relink
            if ($docId <= 0) {
                $DB->update($table, ['pdf_status' => DocumentVersion::PDF_ERROR, 'date_mod' => $now], ['id' => $versionId]);
            } else {
                $DB->update($table, ['documents_id' => $docId, 'pdf_sha256' => $pdfSha, 'pdf_status' => DocumentVersion::PDF_READY, 'date_mod' => $now], ['id' => $versionId]);
            }
        } catch (\Throwable $e) {
            // Nunca compromete la evidencia ya registrada: marca error y permite reintento. El motivo
            // REAL se conserva para diagnóstico (no sólo `pdf_status=error`).
            self::$lastError = get_class($e) . ': ' . $e->getMessage();
            try {
                $DB->update($table, ['pdf_status' => DocumentVersion::PDF_ERROR], ['id' => $versionId]);
            } catch (\Throwable) {
                // best-effort
            }
        } finally {
            if ($held) {
                $this->releaseLock($lock);
            }
        }

        $version->getFromDB($versionId);
        return $version;
    }

    /** Nombre del lock advisory por versión: sólo `[A-Za-z0-9_]`, `<=64`, namespaced por BD. */
    private function lockName(int $versionId): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $db = preg_replace('/[^A-Za-z0-9_]/', '_', (string) ($DB->dbdefault ?? 'glpi'));
        return substr('csig_pdf_' . $db . '_' . $versionId, 0, 64);
    }

    /**
     * Adquiere el lock con nombre (`GET_LOCK`). BEST-EFFORT: si no se obtiene (timeout/error) NO se
     * bloquea el PDF (el PDF nunca debe bloquear la evidencia); el marcador estable sigue evitando
     * duplicados. El nombre se compone sólo de caracteres seguros ⇒ literal SQL sin inyección.
     */
    private function acquireLock(string $name, int $timeoutSeconds): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            $res = $DB->doQuery("SELECT GET_LOCK('" . $name . "', " . max(0, $timeoutSeconds) . ") AS l");
            if ($res === false) {
                return false;
            }
            $row = $DB->fetchAssoc($res);
            return isset($row['l']) && (int) $row['l'] === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Libera el lock con nombre (`RELEASE_LOCK`). Best-effort. */
    private function releaseLock(string $name): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            $DB->doQuery("SELECT RELEASE_LOCK('" . $name . "')");
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function renderPdf(DocumentVersion $version): string
    {
        $subjectType = (string) $version->fields['subject_itemtype'];
        $subjectId   = (int) $version->fields['subject_items_id'];
        $ver         = (int) $version->fields['version'];
        $hash        = (string) $version->fields['content_sha256'];
        $token       = $this->approvalToken($version);
        $verifyUrl   = $this->verifyUrl($token);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('companysignature');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(18, 18, 18);
        $pdf->AddPage();

        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('helvetica', 'B', 15);
        $pdf->Cell(0, 9, __('Approved document — evidence', 'companysignature'), 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(0, 6, sprintf('%s #%d', $subjectType, $subjectId), 0, 1, 'L');
        $pdf->Cell(0, 6, sprintf('%s: %d', __('Document version', 'companysignature'), $ver), 0, 1, 'L');
        $pdf->Ln(2);

        $pdf->SetFont('courier', '', 9);
        $pdf->MultiCell(0, 5, 'content_sha256:' . "\n" . $hash, 0, 'L');
        $pdf->Ln(2);

        // QR de verificación (endpoint interno autenticado).
        try {
            $pdf->write2DBarcode($verifyUrl !== '' ? $verifyUrl : ('sha256:' . $hash), 'QRCODE,H', 18, $pdf->GetY() + 2, 34, 34, [
                'border' => false, 'padding' => 0, 'fgcolor' => [20, 20, 20], 'bgcolor' => false,
            ], 'N');
        } catch (\Throwable) {
            // si el QR fallara, el PDF sigue siendo válido (lleva versión + hash).
        }
        $pdf->SetXY(56, $pdf->GetY() + 2);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->MultiCell(0, 5, __('Internal verification (login + ACL):', 'companysignature') . "\n" . ($verifyUrl !== '' ? $verifyUrl : '—'), 0, 'L');

        return (string) $pdf->Output('approved.pdf', 'S');
    }

    /**
     * Persiste bytes como `Document` nativo enlazado al sujeto, CRASH-SAFE e IDEMPOTENTE (§7).
     *
     * Antes de crear busca un `Document` con el MARCADOR TÉCNICO estable de esta versión (nombre
     * único por `document_versions_id`). Así, si un intento anterior creó el `Document` pero cayó
     * ANTES de `markPdfReady` (y por tanto `documents_id` en nuestra tabla sigue 0), el reintento
     * REUTILIZA/RELINKEA ese mismo `Document` en lugar de crear uno segundo. La reconexión del
     * `Document_Item` también es idempotente y reintentable.
     */
    private function storeAsDocument(DocumentVersion $version, string $bytes, string $pdfSha): int
    {
        $entityId    = (int) $version->fields['entities_id'];
        $rec         = (int) $version->fields['is_recursive'];
        $subjectType = (string) $version->fields['subject_itemtype'];
        $subjectId   = (int) $version->fields['subject_items_id'];
        $marker      = $this->stableMarker($version);

        // Recuperación determinista: ¿ya existe el Document de esta versión (por marcador)?
        $docId = $this->findDocumentByMarker($marker);

        if ($docId <= 0) {
            $dir = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
            $fname = 'csig_v' . (int) $version->fields['version'] . '_' . substr($pdfSha, 0, 10) . '.pdf';
            $full = rtrim((string) $dir, '/') . '/' . $fname;
            if (@file_put_contents($full, $bytes) === false) {
                return 0;
            }
            $doc = new Document();
            $docId = (int) $doc->add([
                'name'                    => $marker, // marcador técnico estable (clave de recuperación)
                'entities_id'             => $entityId,
                'is_recursive'            => $rec,
                '_only_if_upload_succeed' => 1,
                '_filename'               => [$fname],
                '_prefix_filename'        => [''],
            ]);
            if (@file_exists($full)) {
                @unlink($full); // GLPI ya movió el archivo si tuvo éxito; limpiar si quedó.
            }
            if ($docId <= 0) {
                return 0;
            }
        }

        // Enlazar al sujeto (idempotente y reintentable: no duplica el vínculo).
        if (class_exists('Document_Item') && class_exists($subjectType)) {
            $link = new Document_Item();
            if (!$link->getFromDBByCrit([
                'documents_id' => $docId,
                'itemtype'     => $subjectType,
                'items_id'     => $subjectId,
            ])) {
                $link->add([
                    'documents_id' => $docId,
                    'itemtype'     => $subjectType,
                    'items_id'     => $subjectId,
                    'entities_id'  => $entityId,
                    'is_recursive' => $rec,
                ]);
            }
        }
        return $docId;
    }

    /** Marcador técnico estable por versión documental (único ⇒ recuperación determinista). */
    private function stableMarker(DocumentVersion $version): string
    {
        return sprintf(
            '[companysignature] %s#%d v%d (dv:%d)',
            (string) $version->fields['subject_itemtype'],
            (int) $version->fields['subject_items_id'],
            (int) $version->fields['version'],
            (int) $version->getID()
        );
    }

    /** Busca un `Document` por su marcador técnico (nombre exacto). Devuelve id o 0. */
    private function findDocumentByMarker(string $marker): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => Document::getTable(),
            'WHERE'  => ['name' => $marker],
            'ORDER'  => 'id ASC',
            'LIMIT'  => 1,
        ]) as $row) {
            return (int) $row['id'];
        }
        return 0;
    }

    /** Token de la evidencia de APROBACIÓN de esta versión (para el QR), o '' si no hay. */
    private function approvalToken(DocumentVersion $version): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'SELECT' => 'verification_token',
            'FROM'   => ApprovalEvidence::getTable(),
            'WHERE'  => [
                'subject_itemtype'   => (string) $version->fields['subject_itemtype'],
                'subject_items_id'   => (int) $version->fields['subject_items_id'],
                'document_version'   => (int) $version->fields['version'],
                'decision'           => ApprovalEvidence::DECISION_APPROVED,
            ],
            'ORDER' => 'id DESC',
            'LIMIT' => 1,
        ]) as $row) {
            return (string) $row['verification_token'];
        }
        return '';
    }

    private function verifyUrl(string $token): string
    {
        if ($token === '') {
            return '';
        }
        global $CFG_GLPI;
        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        $root = (string) ($CFG_GLPI['root_doc'] ?? '');
        return $base . $root . '/plugins/companysignature/verify/' . rawurlencode($token);
    }
}
