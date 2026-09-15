<?php

/**
 * Circuit breaker simple (closed → open → half-open). Lógica PURA (el tiempo se inyecta).
 *
 * Tras `threshold` fallos consecutivos abre el circuito por `cooldownSec`; pasado ese tiempo
 * permite un intento de sondeo (half-open). Un éxito lo cierra; un fallo lo reabre.
 * Una falla de Snipe deja de golpear a Snipe → no tumba a GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

final class CircuitBreaker
{
    private int $threshold;
    private int $cooldownSec;
    private int $consecutiveFailures = 0;
    private ?int $openedAt = null;

    public function __construct(int $threshold = 5, int $cooldownSec = 30)
    {
        $this->threshold   = max(1, $threshold);
        $this->cooldownSec = max(1, $cooldownSec);
    }

    /** ¿Se permite un request ahora? (abre paso en half-open tras el cooldown). */
    public function allow(int $nowTs): bool
    {
        if ($this->openedAt === null) {
            return true;
        }
        return $nowTs >= ($this->openedAt + $this->cooldownSec);
    }

    public function isOpen(int $nowTs): bool
    {
        return !$this->allow($nowTs);
    }

    public function onSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->openedAt = null;
    }

    public function onFailure(int $nowTs): void
    {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= $this->threshold) {
            $this->openedAt = $nowTs;
        }
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }
}
