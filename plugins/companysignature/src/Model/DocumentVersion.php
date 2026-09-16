<?php

/**
 * Versión INMUTABLE del contenido aprobado (tabla `glpi_plugin_companysignature_document_versions`).
 *
 * Decisión D1: cada versión documental es inmutable en su parte probatoria
 *   `canonical_snapshot → content_sha256`
 * (nunca se reescribe). El PDF es un artefacto DERIVADO: sus campos (`documents_id`, `pdf_sha256`,
 * `pdf_status`) pueden completarse/reintentarse después sin alterar la prueba, y la regeneración es
 * idempotente (mismo `version` ⇒ mismo PDF lógico, sin duplicar evidencia).
 *
 * REGLA 0: tabla PROPIA del plugin. El PDF se almacena como `Document` NATIVO (hereda
 * ACL/entidad/almacenamiento del core); aquí sólo se referencia su `documents_id`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Model;

use CommonDBTM;

class DocumentVersion extends CommonDBTM
{
    public static $rightname = 'plugin_companysignature';

    /** Estado del artefacto PDF derivado. */
    public const PDF_PENDING = 'pending';
    public const PDF_READY   = 'ready';
    public const PDF_ERROR   = 'error';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companysignature_document_versions';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Approved document version', 'Approved document versions', $nb, 'companysignature');
    }

    public function contentHash(): string
    {
        return (string) ($this->fields['content_sha256'] ?? '');
    }

    public function versionNumber(): int
    {
        return (int) ($this->fields['version'] ?? 0);
    }
}
