<?php

/**
 * Evidencia de aprobación electrónica interna (tabla propia `glpi_plugin_companysignature_evidences`).
 *
 * APPEND-ONLY (gate §3/§13): una fila por decisión/invalidación; **nunca** se hace `UPDATE`
 * destructivo ni `DELETE` de negocio. Una invalidación produce OTRA fila que referencia a la
 * evidencia previa (`references_evidences_id`). No es firma digital certificada.
 *
 * REGLA 0: tabla PROPIA del plugin (prefijo `glpi_plugin_companysignature_`), no del core. La
 * visibilidad de datos del sujeto depende de la ACL nativa de GLPI sobre el objeto referenciado
 * y de `entities_id` de esta tabla.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Model;

use CommonDBTM;

class ApprovalEvidence extends CommonDBTM
{
    /** Derecho de plugin (bits definidos abajo). */
    public static $rightname = 'plugin_companysignature';

    /**
     * Bits del derecho `plugin_companysignature`. READ (1) = ver evidencias.
     * Los demás son bits propios (no colisionan porque el rightname es nuestro).
     */
    public const RIGHT_RECORD = 2; // registrar evidencia / versión documental
    public const RIGHT_VERIFY = 4; // verificar un token de evidencia
    public const RIGHT_CONFIG = 8; // configurar el plugin

    /** Decisión registrada (refleja la acción del workflow; no la re-decide). */
    public const DECISION_APPROVED    = 'approved';
    public const DECISION_REJECTED    = 'rejected';
    public const DECISION_RETURNED    = 'returned';
    public const DECISION_INVALIDATED = 'invalidated';

    /** Tipo de evento de evidencia. */
    public const EVENT_DECISION     = 'decision';
    public const EVENT_INVALIDATION = 'invalidation';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companysignature_evidences';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Approval evidence', 'Approval evidences', $nb, 'companysignature');
    }

    /**
     * Bits de derecho disponibles para el formulario de perfiles de GLPI.
     *
     * @param bool $interface
     * @return array<int,string|array>
     */
    public function getRights($interface = 'central')
    {
        return [
            READ                => __('Read'),
            self::RIGHT_RECORD  => __('Record evidence / document version', 'companysignature'),
            self::RIGHT_VERIFY  => __('Verify evidence token', 'companysignature'),
            self::RIGHT_CONFIG  => __('Configure plugin', 'companysignature'),
        ];
    }
}
