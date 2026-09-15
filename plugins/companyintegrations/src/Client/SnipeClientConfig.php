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
    public bool $allowInsecureHttp;

    /**
     * @param bool $allowInsecureHttp  Override SÓLO para DEV. Por defecto false → una `base_url` que
     *                                 no sea https:// se RECHAZA (un http:// no usa TLS y expondría
     *                                 el Bearer token). Prohibido en producción.
     * @throws \InvalidArgumentException  base_url vacía o no-https sin override explícito.
     */
    public function __construct(
        string $baseUrl,
        string $token,
        int $timeoutMs = 5000,
        int $maxRetries = 3,
        int $backoffBaseMs = 200,
        int $breakerThreshold = 5,
        int $breakerCooldownSec = 30,
        bool $allowInsecureHttp = false
    ) {
        $baseUrl = trim(rtrim($baseUrl, '/'));
        $this->allowInsecureHttp = $allowInsecureHttp;
        $this->assertSecureUrl($baseUrl, $allowInsecureHttp);

        $this->baseUrl            = $baseUrl;
        $this->token             = $token;
        $this->timeoutMs         = max(1, $timeoutMs);
        $this->maxRetries        = max(0, $maxRetries);
        $this->backoffBaseMs     = max(1, $backoffBaseMs);
        $this->breakerThreshold  = max(1, $breakerThreshold);
        $this->breakerCooldownSec = max(1, $breakerCooldownSec);
    }

    /** TLS obligatorio por defecto. */
    private function assertSecureUrl(string $baseUrl, bool $allowInsecureHttp): void
    {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('base_url requerida');
        }
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if ($scheme === 'https') {
            return;
        }
        if ($scheme === 'http' && $allowInsecureHttp) {
            return; // override explícito SÓLO para DEV
        }
        throw new \InvalidArgumentException(
            "base_url debe usar https:// (TLS). Recibido esquema '" . ($scheme ?: 'ninguno')
            . "'. HTTP inseguro sólo con override explícito de DEV."
        );
    }

    public function hasToken(): bool
    {
        return trim($this->token) !== '';
    }
}
