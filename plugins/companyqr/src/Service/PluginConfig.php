<?php

/**
 * Acceso a la configuración del plugin (contexto Config `plugin:companyqr`).
 *
 * Usa la API soportada de GLPI (Config::getConfigurationValues), sin SQL directo.
 * Nada de secretos aquí (ver docs/security/secrets-management.md).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

use Config;

final class PluginConfig
{
    public const CONTEXT = 'plugin:companyqr';

    /** Valores por defecto (se persisten en la instalación). */
    public const DEFAULTS = [
        'anonymous_enabled'        => '0',
        'anon_fields'              => 'public_code,type',
        'default_itilcategories_id' => '0',
        'default_urgency'          => '3',
        'code_prefix_map'          => '{"Computer":"PC","Monitor":"MON","Printer":"IMP","NetworkEquipment":"NET","Phone":"TEL"}',
        'label_size'               => '70.75x24',
        'label_bg'                 => '#f7e300',
        'label_header'             => 'TI • ACTIVOS',
        'label_show_org'           => '0',
        'label_fields'             => 'public_code,type',
        'scan_retention_months'    => '12',
        'anon_rate_max'            => '5',
        'anon_rate_window_seconds' => '900',
    ];

    /** @return array<string,mixed> */
    public static function all(): array
    {
        return Config::getConfigurationValues(self::CONTEXT);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $conf = self::all();
        return $conf[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    public static function anonymousEnabled(): bool
    {
        return (int) self::get('anonymous_enabled', '0') === 1;
    }

    /** @return array<string,string> mapa itemtype -> prefijo de código */
    public static function prefixMap(): array
    {
        $raw = (string) self::get('code_prefix_map', '{}');
        $map = json_decode($raw, true);
        return is_array($map) ? $map : [];
    }

    /** @return array{0:float,1:float} ancho x alto en mm */
    public static function labelSizeMm(): array
    {
        $raw = (string) self::get('label_size', '70.75x24');
        $parts = explode('x', strtolower($raw));
        $w = isset($parts[0]) ? (float) $parts[0] : 70.75;
        $h = isset($parts[1]) ? (float) $parts[1] : 24.0;
        return [$w > 0 ? $w : 70.75, $h > 0 ? $h : 24.0];
    }
}
