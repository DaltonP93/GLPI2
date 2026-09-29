<?php

/**
 * Contrato de ESCRITURA con Snipe-IT para SI-4 (incremento SI4-1, ADR-0020). Sin dependencias de GLPI:
 * transporte inyectable → tests de contrato sin socket ni Snipe real.
 *
 *   lookupByTag(tag)      GET  /api/v1/hardware/bytag/{tag}?deleted=true  → filas (vivas y soft-deleted); [] si no existe
 *   createAsset(fields)   POST /api/v1/hardware                           → CreateResult clasificado (UN solo disparo)
 *   statusLabel(id)       GET  /api/v1/statuslabels/{id}                  → fila o null (preflight de configuración)
 *
 * Contrato real verificado (Snipe-IT v8.7.2): éxito y error de negocio llegan con HTTP 200; se decide por el cuerpo
 * (`SnipeEnvelope`). Las LECTURAS se reintentan (429/5xx/timeout, backoff + Retry-After, circuit breaker); el POST
 * NUNCA se reintenta a ciegas: un timeout/5xx es INCIERTO y el llamador debe buscar por tag antes de volver a crear.
 *
 * Seguridad: TLS obligatorio (SnipeClientConfig rechaza http:// salvo override de DEV; CurlTransport verifica el
 * certificado), token sólo en el header Authorization y NUNCA en logs (LogSanitizer), User-Agent propio (Snipe puede
 * bloquear UA vacío), correlation_id propagado.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

use GlpiPlugin\Companyintegrations\Service\BackoffPolicy;
use GlpiPlugin\Companyintegrations\Service\CircuitBreaker;
use GlpiPlugin\Companyintegrations\Service\ErrorClassifier;
use GlpiPlugin\Companyintegrations\Service\LogSanitizer;

final class SnipeAssetWriter
{
    public const USER_AGENT = 'GLPI2-companyintegrations/SI4';

    private HttpTransport $transport;
    private SnipeClientConfig $config;
    private CircuitBreaker $breaker;
    private BackoffPolicy $backoff;
    private ErrorClassifier $classifier;
    private LogSanitizer $sanitizer;
    /** @var callable(string,string,array<string,mixed>):void */
    private $logger;
    private bool $sleep;

    public function __construct(HttpTransport $transport, SnipeClientConfig $config, ?callable $logger = null, bool $sleep = true)
    {
        $this->transport  = $transport;
        $this->config     = $config;
        $this->breaker    = new CircuitBreaker($config->breakerThreshold, $config->breakerCooldownSec);
        $this->backoff    = new BackoffPolicy($config->backoffBaseMs);
        $this->classifier = new ErrorClassifier();
        $this->sanitizer  = new LogSanitizer();
        $this->logger     = $logger ?? static function (): void {};
        $this->sleep      = $sleep;
    }

    /**
     * Activos con ese tag EXACTO, incluidos los soft-deleted (`?deleted=true` ⇒ Snipe devuelve siempre `{total, rows}`).
     * `[]` si no existe (Snipe: HTTP 200 + `status:"error"`).
     *
     * @return array<int,array<string,mixed>>
     * @throws SnipeException auth / rate_limit / transport / circuit_open / client
     */
    public function lookupByTag(string $tag, string $correlationId): array
    {
        $resp = $this->read('/api/v1/hardware/bytag/' . rawurlencode($tag), ['deleted' => 'true'], $correlationId);
        $json = $resp->json();
        if (SnipeEnvelope::isError($json)) {
            return [];
        }
        $rows = SnipeEnvelope::rows($json);
        if ($rows !== null) {
            return $rows;
        }
        if (is_array($json) && isset($json['id'])) {
            return [$json]; // defensa: un único activo sin envoltura de listado
        }
        $this->log('error', 'respuesta de lookup ilegible', ['status' => $resp->status], $correlationId);
        throw new SnipeException(SnipeException::TRANSPORT, 'Respuesta de lookup ilegible', $correlationId);
    }

    /**
     * Fila del status label o null si no existe (preflight: el status configurado debe existir en Snipe).
     *
     * @return array<string,mixed>|null
     * @throws SnipeException
     */
    public function statusLabel(int $id, string $correlationId): ?array
    {
        $resp = $this->read('/api/v1/statuslabels/' . $id, [], $correlationId);
        $json = $resp->json();
        if ($resp->status === 404 || SnipeEnvelope::isError($json) || !is_array($json) || (int) ($json['id'] ?? 0) !== $id) {
            return null;
        }
        return $json;
    }

    /**
     * Crea el activo con UN solo POST. Nunca lanza por resultados HTTP: todo sale clasificado en `CreateResult`.
     *
     * @param array<string,scalar|null> $fields  asset_tag, model_id, status_id, company_id, serial, name, order_number, notes
     */
    public function createAsset(array $fields, string $correlationId): CreateResult
    {
        $now = time();
        if (!$this->breaker->allow($now)) {
            $this->log('error', 'circuit open; POST no enviado', [], $correlationId);
            return new CreateResult(CreateResult::CIRCUIT, 0, 0, [], null, 'circuit open');
        }
        $tag = (string) ($fields['asset_tag'] ?? '');
        try {
            $body = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new CreateResult(CreateResult::REJECTED, 0, 0, [], null, 'cuerpo no serializable');
        }
        try {
            $resp = $this->transport->send('POST', $this->config->baseUrl . '/api/v1/hardware', $this->headers($correlationId, true), $body, $this->config->timeoutMs);
        } catch (HttpTransportException $e) {
            $this->breaker->onFailure($now);
            $this->log('warning', 'POST incierto (transporte/timeout)', ['error' => $e->getMessage()], $correlationId);
            return new CreateResult(CreateResult::UNCERTAIN, 0, 0, [], null, 'transport');
        }

        $kind = $this->classifier->classify($resp->status);
        if ($kind === ErrorClassifier::AUTH) {
            $this->log('error', 'POST rechazado por autenticación/permiso', ['status' => $resp->status], $correlationId);
            return new CreateResult(CreateResult::AUTH, $resp->status);
        }
        if ($kind === ErrorClassifier::RATELIMIT) {
            $this->breaker->onFailure($now);
            $ra = $resp->header('retry-after');
            $sec = ($ra !== null && ctype_digit(trim($ra))) ? (int) trim($ra) : null;
            $this->log('warning', 'POST rechazado por rate limit (sin efecto)', ['retry_after' => $sec], $correlationId);
            return new CreateResult(CreateResult::RATE_LIMIT, 429, 0, [], $sec);
        }
        if ($kind === ErrorClassifier::CONFLICT) {
            $this->log('warning', 'POST 409 (conflicto explícito)', [], $correlationId);
            return new CreateResult(CreateResult::CONFLICT, 409);
        }
        if ($kind === ErrorClassifier::SERVER) {
            $this->breaker->onFailure($now);
            $this->log('warning', 'POST incierto (5xx)', ['status' => $resp->status], $correlationId);
            return new CreateResult(CreateResult::UNCERTAIN, $resp->status, 0, [], null, 'server');
        }
        if ($kind !== ErrorClassifier::OK) {
            $this->log('warning', 'POST rechazado (4xx)', ['status' => $resp->status], $correlationId);
            return new CreateResult(CreateResult::REJECTED, $resp->status);
        }

        $this->breaker->onSuccess();
        $json = $resp->json();
        if (SnipeEnvelope::isError($json)) {
            $fields = SnipeEnvelope::errorFields($json);
            $this->log('warning', 'POST con error de validación', ['fields' => implode(',', $fields)], $correlationId);
            return $fields !== []
                ? new CreateResult(CreateResult::VALIDATION, 200, 0, $fields)
                : new CreateResult(CreateResult::REJECTED, 200, 0, [], null, 'error sin campos');
        }
        $payload = SnipeEnvelope::payload($json);
        $id = (int) ($payload['id'] ?? 0);
        if ($payload === null || $id <= 0 || strcasecmp(SnipeEnvelope::text($payload['asset_tag'] ?? ''), $tag) !== 0) {
            // 2xx sin un activo verificable: NO se asume ni éxito ni fracaso.
            $this->log('warning', 'POST 2xx sin activo verificable (incierto)', [], $correlationId);
            return new CreateResult(CreateResult::UNCERTAIN, $resp->status, 0, [], null, 'unverifiable');
        }
        return new CreateResult(CreateResult::CREATED, $resp->status, $id);
    }

    // ------------------------------------------------------------------ núcleo de lectura

    /**
     * GET con resiliencia (reintentos acotados). Devuelve la respuesta 2xx/404; lanza SnipeException para el resto.
     *
     * @param array<string,string> $query
     * @throws SnipeException
     */
    private function read(string $path, array $query, string $correlationId): HttpResponse
    {
        $url = $this->config->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $attempt = 0;
        while (true) {
            $now = time();
            if (!$this->breaker->allow($now)) {
                throw new SnipeException(SnipeException::CIRCUIT_OPEN, 'Circuit breaker abierto', $correlationId);
            }
            try {
                $resp = $this->transport->send('GET', $url, $this->headers($correlationId, false), null, $this->config->timeoutMs);
            } catch (HttpTransportException $e) {
                $this->breaker->onFailure($now);
                if ($attempt < $this->config->maxRetries) {
                    $this->waitMs($this->backoff->delayMs($attempt));
                    $attempt++;
                    continue;
                }
                $this->log('error', 'lectura: transporte agotado', ['path' => $path, 'error' => $e->getMessage()], $correlationId);
                throw new SnipeException(SnipeException::TRANSPORT, 'Fallo de transporte tras reintentos', $correlationId, $e);
            }
            $kind = $this->classifier->classify($resp->status);
            if ($kind === ErrorClassifier::OK || $kind === ErrorClassifier::NOT_FOUND) {
                $this->breaker->onSuccess();
                return $resp;
            }
            if ($kind === ErrorClassifier::AUTH) {
                $this->log('error', 'lectura: autenticación/permiso rechazado', ['path' => $path, 'status' => $resp->status], $correlationId);
                throw new SnipeException(SnipeException::AUTH, 'Auth/permiso rechazado (' . $resp->status . ')', $correlationId);
            }
            if ($kind === ErrorClassifier::CONFLICT || $kind === ErrorClassifier::CLIENT) {
                $this->log('warning', 'lectura: error de cliente', ['path' => $path, 'status' => $resp->status], $correlationId);
                throw new SnipeException(SnipeException::CLIENT, 'Error de cliente (' . $resp->status . ')', $correlationId);
            }
            $this->breaker->onFailure($now);
            $retryAfterMs = null;
            if ($kind === ErrorClassifier::RATELIMIT) {
                $ra = $resp->header('retry-after');
                if ($ra !== null && ctype_digit(trim($ra))) {
                    $retryAfterMs = (int) trim($ra) * 1000;
                }
            }
            if ($attempt < $this->config->maxRetries) {
                $this->waitMs($this->backoff->delayMs($attempt, $retryAfterMs));
                $attempt++;
                continue;
            }
            if ($kind === ErrorClassifier::RATELIMIT) {
                throw new SnipeException(SnipeException::RATE_LIMIT, 'Rate limit tras reintentos (429)', $correlationId);
            }
            throw new SnipeException(SnipeException::TRANSPORT, 'Error de servidor tras reintentos (' . $resp->status . ')', $correlationId);
        }
    }

    /** @return array<string,string> */
    private function headers(string $correlationId, bool $json): array
    {
        $h = [
            'Authorization'    => 'Bearer ' . $this->config->token,
            'Accept'           => 'application/json',
            'User-Agent'       => self::USER_AGENT,
            'X-Correlation-ID' => $correlationId,
        ];
        if ($json) {
            $h['Content-Type'] = 'application/json';
        }
        return $h;
    }

    private function waitMs(int $ms): void
    {
        if ($this->sleep && $ms > 0) {
            usleep($ms * 1000);
        }
    }

    /** @param array<string,mixed> $context */
    private function log(string $level, string $message, array $context, string $correlationId): void
    {
        $context['correlation_id'] = $correlationId;
        ($this->logger)($level, $this->sanitizer->redact($message), $this->sanitizer->redactArray($context));
    }
}
