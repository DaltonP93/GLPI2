<?php

/**
 * URL del QR: la ruta AUTENTICADA estándar `/plugins/companyqr/scan/{token}` (ADR-0011 §1). Es el ÚNICO lugar que la
 * construye: la etiqueta (`LabelComposer`) y cualquier consumidor la obtienen de companyqr, nunca la arman por su
 * cuenta (el token opaco no sale del plugin).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

final class ScanUrl
{
    public const ROUTE = '/plugins/companyqr/scan/';

    /** @param string|null $base URL base de GLPI (`$CFG_GLPI['url_base']` si es null) */
    public static function forToken(string $token, ?string $base = null): string
    {
        if ($base === null) {
            global $CFG_GLPI;
            $base = (string) ($CFG_GLPI['url_base'] ?? '');
        }
        return $base . self::ROUTE . rawurlencode($token);
    }
}
