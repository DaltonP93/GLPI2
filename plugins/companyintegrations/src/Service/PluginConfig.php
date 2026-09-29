<?php

/**
 * Configuración del plugin (contexto Config `plugin:companyintegrations`). API soportada, sin
 * SQL directo. **SIN SECRETOS**: el token de Snipe NO se guarda aquí; se lee de una variable de
 * entorno / secret (ver SnipeConfigFactory y docs/security/snipeit-integration-security.md).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

use Config;

final class PluginConfig
{
    public const CONTEXT = 'plugin:companyintegrations';

    /** Nombre de la variable de entorno que contiene el token (el valor NUNCA se versiona). */
    public const TOKEN_ENV = 'COMPANYINTEGRATIONS_SNIPEIT_TOKEN';

    public const DEFAULTS = [
        'snipe_base_url'        => '',
        'timeout_ms'            => '5000',
        'max_retries'           => '3',
        'backoff_base_ms'       => '200',
        'breaker_threshold'     => '5',
        'breaker_cooldown_sec'  => '30',
        // TLS: HTTP inseguro deshabilitado por defecto (sólo DEV vía override explícito).
        'allow_insecure_http'   => '0',
        // Itemtypes GLPI candidatos para el match por serial (config-first, sin hardcode de negocio).
        'match_itemtypes'       => 'Computer,Monitor,NetworkEquipment,Printer,Phone',
        // Paginación de reconciliación.
        'reconcile_page_size'   => '50',
        'reconcile_max_assets'  => '10000',
        // --- SI-4 (incremento SI4-1, ADR-0020). Deshabilitado por defecto: no programar en producción hasta completar SI-4. ---
        'si4_enabled'                    => '0',
        // Prefijo del asset_tag determinista (<prefijo><32 hex del receipt_unit_uuid>).
        'si4_asset_tag_prefix'           => 'GP2-',
        // Status label de Snipe para activos nuevos: SIN valor por defecto (se valida contra Snipe en el preflight).
        'si4_snipe_status_id'            => '0',
        'si4_lease_seconds'              => '900',
        'si4_max_units_per_run'          => '50',
        'si4_retry_base_seconds'         => '60',
        'si4_retry_max_seconds'          => '3600',
        'si4_config_retry_seconds'       => '3600',
        'si4_auth_retry_seconds'         => '900',
        'si4_uncertain_cooldown_seconds' => '300',
        'si4_worker_id'                  => '',
    ];

    /** @return array<string,mixed> */
    public static function all(): array
    {
        if (!class_exists('Config')) {
            return self::DEFAULTS;
        }
        $conf = Config::getConfigurationValues(self::CONTEXT);
        return is_array($conf) ? $conf : [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $conf = self::all();
        return $conf[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    /** @return array<int,string> */
    public static function matchItemtypes(): array
    {
        $raw = (string) self::get('match_itemtypes', 'Computer');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
