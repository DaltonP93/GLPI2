<?php

/**
 * Configuración OPERACIONAL del worker SI-4 (value object PURO; se construye desde `PluginConfig::all()`).
 * Sin secretos (el token de Snipe vive en la variable de entorno, ver `SnipeConfigFactory`).
 *
 * Presupuestos de tiempo (ADR-0020 §6): el lease debe cubrir el peor caso de una unidad (lookup con reintentos +
 * POST + verificación posterior) y, antes de un POST, el lease restante debe cubrir el POST en vuelo + persistencia.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class Si4Config
{
    /** Backoff máximo (s) por reintento de lectura del cliente (BackoffPolicy tope 16000 ms). */
    private const READ_BACKOFF_CAP_SEC = 16;

    public bool $enabled;
    public string $prefix;
    public int $statusId;
    public int $leaseSeconds;
    public int $maxPerRun;
    public int $retryBaseSec;
    public int $retryMaxSec;
    public int $configRetrySec;
    public int $authRetrySec;
    public int $uncertainCooldownSec;
    public string $workerId;

    /** @param array<string,mixed> $c valores de configuración (strings, como los guarda `Config`) */
    public static function fromArray(array $c): self
    {
        $s = new self();
        $s->enabled              = (string) ($c['si4_enabled'] ?? '0') === '1';
        $s->prefix               = trim((string) ($c['si4_asset_tag_prefix'] ?? ''));
        $s->statusId             = self::int($c, 'si4_snipe_status_id', 0);
        $s->leaseSeconds         = self::int($c, 'si4_lease_seconds', 900);
        $s->maxPerRun            = self::int($c, 'si4_max_units_per_run', 50);
        $s->retryBaseSec         = self::int($c, 'si4_retry_base_seconds', 60);
        $s->retryMaxSec          = self::int($c, 'si4_retry_max_seconds', 3600);
        $s->configRetrySec       = self::int($c, 'si4_config_retry_seconds', 3600);
        $s->authRetrySec         = self::int($c, 'si4_auth_retry_seconds', 900);
        $s->uncertainCooldownSec = self::int($c, 'si4_uncertain_cooldown_seconds', 300);
        $s->workerId             = trim((string) ($c['si4_worker_id'] ?? ''));
        return $s;
    }

    /** Lease mínimo (s) para el peor caso de una unidad: 2 lecturas con reintentos + 1 POST + margen. */
    public static function minLeaseSeconds(int $timeoutMs, int $maxRetries): int
    {
        $t = (int) ceil(max(1, $timeoutMs) / 1000);
        return 2 * (max(0, $maxRetries) + 1) * ($t + self::READ_BACKOFF_CAP_SEC) + $t + 60;
    }

    /** Lease restante (s) exigido ANTES de un POST: el POST en vuelo + persistir su resultado. */
    public static function writeBudgetSeconds(int $timeoutMs): int
    {
        return (int) ceil(max(1, $timeoutMs) / 1000) + 60;
    }

    /**
     * Errores de configuración (vacío = válida). Fail-closed: con errores el worker no reclama nada.
     *
     * @return array<int,string>
     */
    public function errors(int $timeoutMs, int $maxRetries): array
    {
        $e = [];
        if (!AssetTagDeriver::isValidPrefix($this->prefix)) {
            $e[] = 'si4_asset_tag_prefix inválido (^[A-Z0-9][A-Z0-9-]{0,15}$)';
        }
        if ($this->statusId <= 0) {
            $e[] = 'si4_snipe_status_id sin configurar';
        }
        $min = self::minLeaseSeconds($timeoutMs, $maxRetries);
        if ($this->leaseSeconds < $min) {
            $e[] = "si4_lease_seconds debe ser >= {$min} para el timeout/reintentos configurados";
        }
        if ($this->maxPerRun < 1 || $this->maxPerRun > 1000) {
            $e[] = 'si4_max_units_per_run fuera de rango (1..1000)';
        }
        if ($this->retryBaseSec < 1 || $this->retryMaxSec < $this->retryBaseSec) {
            $e[] = 'si4_retry_base_seconds/si4_retry_max_seconds inválidos';
        }
        if ($this->configRetrySec < 60 || $this->authRetrySec < 60) {
            $e[] = 'si4_config_retry_seconds/si4_auth_retry_seconds deben ser >= 60';
        }
        if ($this->uncertainCooldownSec < 2 * (int) ceil(max(1, $timeoutMs) / 1000) || $this->uncertainCooldownSec < 60) {
            $e[] = 'si4_uncertain_cooldown_seconds debe ser >= max(60, 2 × timeout)';
        }
        if ($this->workerId !== '' && preg_match('/^[A-Za-z0-9._:@-]{1,120}$/', $this->workerId) !== 1) {
            $e[] = 'si4_worker_id inválido';
        }
        return $e;
    }

    /** Backoff exponencial acotado (s) según el número de intento del outbox. */
    public function backoffSeconds(int $attempts, ?int $retryAfterSec = null): int
    {
        $exp = $this->retryBaseSec * (2 ** max(0, min(20, $attempts - 1)));
        $d = (int) min($this->retryMaxSec, $exp);
        return max($d, $retryAfterSec ?? 0);
    }

    /** @param array<string,mixed> $c */
    private static function int(array $c, string $k, int $default): int
    {
        $v = $c[$k] ?? null;
        return (is_int($v) || (is_string($v) && preg_match('/^\d{1,9}$/', $v) === 1)) ? (int) $v : $default;
    }
}
