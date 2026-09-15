<?php

/**
 * Clasifica respuestas HTTP de Snipe-IT y decide reintentos. Lógica PURA (unit-testable).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

final class ErrorClassifier
{
    public const OK        = 'ok';
    public const AUTH      = 'auth';      // 401/403 → NUNCA reintentar (credencial/permiso)
    public const NOT_FOUND = 'notfound';  // 404
    public const CONFLICT  = 'conflict';  // 409
    public const RATELIMIT = 'ratelimit'; // 429 → reintentar respetando Retry-After
    public const SERVER    = 'server';    // 5xx → reintentar
    public const CLIENT    = 'client';    // otros 4xx → no reintentar

    public function classify(int $status): string
    {
        if ($status >= 200 && $status < 300) {
            return self::OK;
        }
        return match ($status) {
            401, 403 => self::AUTH,
            404      => self::NOT_FOUND,
            409      => self::CONFLICT,
            429      => self::RATELIMIT,
            default  => $status >= 500 ? self::SERVER : self::CLIENT,
        };
    }

    /** ¿Un fallo de este tipo es reintentable? */
    public function isRetryable(string $kind): bool
    {
        return $kind === self::SERVER || $kind === self::RATELIMIT;
    }
}
