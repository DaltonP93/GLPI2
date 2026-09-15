<?php

/**
 * Acceso a la configuración del plugin (contexto Config `plugin:companyworkflow`).
 * API soportada de GLPI (Config::getConfigurationValues), sin SQL directo, sin secretos.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

use Config;

final class PluginConfig
{
    public const CONTEXT = 'plugin:companyworkflow';

    /** Valores por defecto (se persisten en la instalación). Sin datos de negocio hardcodeados. */
    public const DEFAULTS = [
        'notifications_enabled' => '1',
        'sla_check_enabled'     => '1',
        'escalation_enabled'    => '1',
        // Límite defensivo de aprobadores resueltos por etapa (evita expansiones enormes).
        'max_resolved_approvers' => '500',
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

    public static function boolean(string $key): bool
    {
        return (int) self::get($key, '0') === 1;
    }
}
