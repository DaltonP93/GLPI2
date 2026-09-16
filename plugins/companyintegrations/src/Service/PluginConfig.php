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
