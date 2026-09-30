<?php

/**
 * Saneamiento de mensajes de error de SI-4 antes de persistirlos (saga, bitácora, `last_error` del outbox) o
 * registrarlos: sin controles, sin credenciales (Bearer/Basic, `usuario:clave@` en URL, `token=`…), acotado. PURO.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Service\LogSanitizer;

final class Si4Errors
{
    public const MAX = 240;

    public static function sanitize(string $error): string
    {
        $e = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $error) ?? '';
        $e = preg_replace('/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $e) ?? '';
        $e = preg_replace('#(https?://)[^/\s:@]+:[^/\s@]+@#i', '$1[redacted]@', $e) ?? '';
        $e = (new LogSanitizer())->redact($e);
        return mb_substr(trim($e), 0, self::MAX);
    }
}
