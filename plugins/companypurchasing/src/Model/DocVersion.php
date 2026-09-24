<?php

/**
 * Ledger de VERSIONES DOCUMENTALES de dominio (tabla `glpi_plugin_companypurchasing_doc_versions`) — P2D-2.
 *
 * Compras es DUEÑA de la numeración de `document_version` (companysignature valida/inmoviliza, no
 * numera). Monotónica POR SOLICITUD (todas las versiones de todos los scopes comparten la secuencia,
 * porque Firma exige `UNIQUE(sujeto, versión)`), concurrency-safe y NUNCA reutilizada.
 * `UNIQUE(requests_id, document_version)`. Guarda el hash del payload semántico (para reutilizar la
 * versión si el contenido no cambió) y, tras registrar en Firma, `document_versions_id`/`content_sha256`.
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companypurchasing_`), no del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonDBTM;

class DocVersion extends CommonDBTM
{
    public static $rightname = 'plugin_companypurchasing';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companypurchasing_doc_versions';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Document version', 'Document versions', $nb, 'companypurchasing');
    }
}
