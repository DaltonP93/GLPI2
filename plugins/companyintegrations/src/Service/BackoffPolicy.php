<?php

/**
 * Backoff exponencial acotado (con soporte de Retry-After). Lógica PURA (unit-testable).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

final class BackoffPolicy
{
    private int $baseMs;
    private int $maxMs;

    public function __construct(int $baseMs = 200, int $maxMs = 16000)
    {
        $this->baseMs = max(1, $baseMs);
        $this->maxMs  = max($this->baseMs, $maxMs);
    }

    /**
     * Milisegundos a esperar antes del intento `$attempt` (0-indexado). Si el servidor indicó
     * Retry-After (ms), se respeta ese valor (acotado a maxMs).
     */
    public function delayMs(int $attempt, ?int $retryAfterMs = null): int
    {
        if ($retryAfterMs !== null && $retryAfterMs >= 0) {
            return min($retryAfterMs, $this->maxMs);
        }
        $attempt = max(0, $attempt);
        $delay = $this->baseMs * (2 ** $attempt);
        return (int) min($delay, $this->maxMs);
    }
}
