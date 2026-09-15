<?php

/**
 * Cliente Snipe-IT READ-ONLY (SI-1). Sin dependencias de GLPI (transporte inyectable → tests de
 * contrato sin socket). Resiliencia: timeout, retry con backoff, circuit breaker, correlation_id.
 * Mapeo de errores: 401/403→AUTH (sin reintento), 404→null, 409→CONFLICT, 429→reintento y luego
 * RATE_LIMIT, 5xx/timeout→reintento y luego TRANSPORT. Logs SANEADOS (el token nunca se registra).
 *
 * SI-1: sólo GET (lectura). No crea/edita nada en Snipe.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

use GlpiPlugin\Companyintegrations\Service\BackoffPolicy;
use GlpiPlugin\Companyintegrations\Service\CircuitBreaker;
use GlpiPlugin\Companyintegrations\Service\CorrelationId;
use GlpiPlugin\Companyintegrations\Service\ErrorClassifier;
use GlpiPlugin\Companyintegrations\Service\LogSanitizer;

final class SnipeItClient
{
    private HttpTransport $transport;
    private SnipeClientConfig $config;
    private CircuitBreaker $breaker;
    private BackoffPolicy $backoff;
    private ErrorClassifier $classifier;
    private LogSanitizer $sanitizer;
    /** @var callable(string,string,array<string,mixed>):void */
    private $logger;
    private bool $sleep;
    private string $lastCorrelationId = '';

    public function __construct(
        HttpTransport $transport,
        SnipeClientConfig $config,
        ?callable $logger = null,
        bool $sleep = true
    ) {
        $this->transport  = $transport;
        $this->config     = $config;
        $this->breaker    = new CircuitBreaker($config->breakerThreshold, $config->breakerCooldownSec);
        $this->backoff    = new BackoffPolicy($config->backoffBaseMs);
        $this->classifier = new ErrorClassifier();
        $this->sanitizer  = new LogSanitizer();
        $this->logger     = $logger ?? static function (): void {};
        $this->sleep      = $sleep;
    }

    public function lastCorrelationId(): string
    {
        return $this->lastCorrelationId;
    }

    // --------------------------------------------------------------- API alto nivel (read-only)

    /** ¿Responde y autentica? (true si un GET mínimo devuelve 2xx). */
    public function ping(): bool
    {
        try {
            $this->get('/api/v1/hardware', ['limit' => 1]);
            return true;
        } catch (SnipeException) {
            return false;
        }
    }

    /**
     * Lista hardware (una página). Devuelve las filas (`rows`).
     * @return array<int,array<string,mixed>>
     */
    public function listHardware(int $offset = 0, int $limit = 50): array
    {
        $resp = $this->get('/api/v1/hardware', ['offset' => $offset, 'limit' => $limit]);
        $data = $resp->json() ?? [];
        $rows = $data['rows'] ?? [];
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string,mixed>|null  activo o null si no existe (404). */
    public function getHardwareByTag(string $tag): ?array
    {
        $resp = $this->get('/api/v1/hardware/bytag/' . rawurlencode($tag));
        if ($resp->status === 404) {
            return null;
        }
        $data = $resp->json();
        return is_array($data) ? $data : null;
    }

    /** @return array<string,mixed>|null */
    public function getHardwareById(int $id): ?array
    {
        $resp = $this->get('/api/v1/hardware/' . $id);
        if ($resp->status === 404) {
            return null;
        }
        $data = $resp->json();
        return is_array($data) ? $data : null;
    }

    /**
     * Lee la configuración (para validar el template de etiquetas — read-only).
     * @return array<string,mixed>|null
     */
    public function getSettings(): ?array
    {
        $resp = $this->get('/api/v1/settings');
        if ($resp->status === 404) {
            return null;
        }
        return $resp->json();
    }

    // --------------------------------------------------------------- núcleo

    /**
     * GET con resiliencia. Devuelve HttpResponse (incluye 404 para que el llamador decida).
     * @param array<string,int|string> $query
     * @throws SnipeException
     */
    public function get(string $path, array $query = []): HttpResponse
    {
        $this->lastCorrelationId = CorrelationId::generate();
        $url = $this->config->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $headers = [
            'Authorization'   => 'Bearer ' . $this->config->token,
            'Accept'          => 'application/json',
            'X-Correlation-ID' => $this->lastCorrelationId,
        ];

        $attempt = 0;
        while (true) {
            $now = time();
            if (!$this->breaker->allow($now)) {
                $this->log('error', 'circuit open; request bloqueado', ['path' => $path]);
                throw new SnipeException(SnipeException::CIRCUIT_OPEN, 'Circuit breaker abierto', $this->lastCorrelationId);
            }

            try {
                $resp = $this->transport->send('GET', $url, $headers, null, $this->config->timeoutMs);
            } catch (HttpTransportException $e) {
                // Timeout/red/TLS → transitorio.
                $this->breaker->onFailure($now);
                if ($attempt < $this->config->maxRetries) {
                    $this->waitMs($this->backoff->delayMs($attempt));
                    $attempt++;
                    continue;
                }
                $this->log('error', 'transporte agotado', ['path' => $path, 'error' => $e->getMessage()]);
                throw new SnipeException(SnipeException::TRANSPORT, 'Fallo de transporte tras reintentos', $this->lastCorrelationId, $e);
            }

            $kind = $this->classifier->classify($resp->status);

            if ($kind === ErrorClassifier::OK || $kind === ErrorClassifier::NOT_FOUND) {
                $this->breaker->onSuccess();
                return $resp;
            }
            if ($kind === ErrorClassifier::AUTH) {
                // No cuenta para el breaker (es credencial/permiso, no caída del servicio).
                $this->log('error', 'autenticación/permiso rechazado', ['path' => $path, 'status' => $resp->status]);
                throw new SnipeException(SnipeException::AUTH, 'Auth/permiso rechazado (' . $resp->status . ')', $this->lastCorrelationId);
            }
            if ($kind === ErrorClassifier::CONFLICT) {
                $this->log('warning', 'conflicto', ['path' => $path, 'status' => 409]);
                throw new SnipeException(SnipeException::CONFLICT, 'Conflicto (409)', $this->lastCorrelationId);
            }
            if ($kind === ErrorClassifier::CLIENT) {
                $this->log('warning', 'error de cliente', ['path' => $path, 'status' => $resp->status]);
                throw new SnipeException(SnipeException::CLIENT, 'Error de cliente (' . $resp->status . ')', $this->lastCorrelationId);
            }

            // RATELIMIT (429) o SERVER (5xx): transitorios → reintento.
            $this->breaker->onFailure($now);
            if ($attempt < $this->config->maxRetries) {
                $retryAfterMs = null;
                if ($kind === ErrorClassifier::RATELIMIT) {
                    $ra = $resp->header('retry-after');
                    if ($ra !== null && ctype_digit(trim($ra))) {
                        $retryAfterMs = (int) trim($ra) * 1000;
                    }
                }
                $this->waitMs($this->backoff->delayMs($attempt, $retryAfterMs));
                $attempt++;
                continue;
            }

            if ($kind === ErrorClassifier::RATELIMIT) {
                $this->log('error', 'rate limit agotado', ['path' => $path]);
                throw new SnipeException(SnipeException::RATE_LIMIT, 'Rate limit tras reintentos (429)', $this->lastCorrelationId);
            }
            $this->log('error', 'error de servidor agotado', ['path' => $path, 'status' => $resp->status]);
            throw new SnipeException(SnipeException::TRANSPORT, 'Error de servidor tras reintentos (' . $resp->status . ')', $this->lastCorrelationId);
        }
    }

    private function waitMs(int $ms): void
    {
        if ($this->sleep && $ms > 0) {
            usleep($ms * 1000);
        }
    }

    /** @param array<string,mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        $context['correlation_id'] = $this->lastCorrelationId;
        ($this->logger)($level, $this->sanitizer->redact($message), $this->sanitizer->redactArray($context));
    }
}
