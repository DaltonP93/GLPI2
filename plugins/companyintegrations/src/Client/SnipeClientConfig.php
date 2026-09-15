<?php

/**
 * Configuración del cliente Snipe-IT (value object). Sin dependencias de GLPI.
 * El token se pasa aquí en memoria; NUNCA se persiste en Git/BD ni se registra en logs.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class SnipeClientConfig
{
    public string $baseUrl;
    public string $token;
    public int $timeoutMs;
    public int $maxRetries;
    public int $backoffBaseMs;
    public int $breakerThreshold;
    public int $breakerCooldownSec;

    public function __construct(
        string $baseUrl,
        string $token,
        int $timeoutMs = 5000,
        int $maxRetries = 3,
        int $backoffBaseMs = 200,
        int $breakerThreshold = 5,
        int $breakerCooldownSec = 30
    ) {
        $this->baseUrl            = rtrim($baseUrl, '/');
        $this->token             = $token;
        $this->timeoutMs         = max(1, $timeoutMs);
        $this->maxRetries        = max(0, $maxRetries);
        $this->backoffBaseMs     = max(1, $backoffBaseMs);
        $this->breakerThreshold  = max(1, $breakerThreshold);
        $this->breakerCooldownSec = max(1, $breakerCooldownSec);
    }

    public function hasToken(): bool
    {
        return trim($this->token) !== '';
    }
}
