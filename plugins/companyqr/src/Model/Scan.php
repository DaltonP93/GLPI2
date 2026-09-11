<?php

/**
 * Evento de escaneo / auditoría mínima (tabla propia glpi_plugin_companyqr_scans).
 *
 * Append-oriented, SIN PII: no guarda IP ni User-Agent (ver ADR-0011 y
 * docs/functional/companyqr.md, sección Privacidad y retención).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Model;

use CommonDBTM;

class Scan extends CommonDBTM
{
    public static $rightname = 'plugin_companyqr';

    /** Resultados posibles de un intento de escaneo/acción. */
    public const RESULT_RESOLVED       = 'resolved';
    public const RESULT_LOGIN_REQUIRED = 'login_required';
    public const RESULT_NOT_FOUND      = 'not_found';
    public const RESULT_REVOKED        = 'revoked';
    public const RESULT_ASSET_GONE     = 'asset_gone';
    public const RESULT_REPORT_CREATED = 'report_created';
    public const RESULT_DENIED         = 'denied';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyqr_scans';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Scan event', 'Scan events', $nb, 'companyqr');
    }
}
