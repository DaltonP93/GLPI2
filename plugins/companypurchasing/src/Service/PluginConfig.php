<?php

/**
 * Acceso a la configuración del plugin (contexto Config `plugin:companypurchasing`).
 *
 * Usa la API soportada de GLPI (`Config::getConfigurationValues`), sin SQL directo ni secretos.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Config;

final class PluginConfig
{
    public const CONTEXT = 'plugin:companypurchasing';

    /** Valores por defecto (se persisten en la instalación). */
    public const DEFAULTS = [
        // Moneda por defecto de nuevas solicitudes.
        'default_currency'      => 'PYG',
        // Versión vigente del catálogo de scopes de aprobación (§approval scopes). Se pinnea en la
        // solicitud al abandonar DRAFT para que una edición administrativa posterior NO cambie su
        // semántica retroactivamente.
        'current_scopes_version' => '1',
        // Overrides de escala por moneda (JSON {"USD":2,...}); PYG es SIEMPRE 0 (no overridable).
        'currency_scale_overrides' => '{}',
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

    public static function defaultCurrency(): string
    {
        $c = strtoupper((string) self::get('default_currency', 'PYG'));
        return CurrencyPolicy::isWellFormed($c) ? $c : 'PYG';
    }

    public static function currentScopesVersion(): int
    {
        return max(1, (int) self::get('current_scopes_version', '1'));
    }

    /** @return array<string,int> */
    public static function currencyScaleOverrides(): array
    {
        $raw = (string) self::get('currency_scale_overrides', '{}');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $cur => $scale) {
            if (is_string($cur) && CurrencyPolicy::isWellFormed(strtoupper($cur))) {
                $out[strtoupper($cur)] = (int) $scale;
            }
        }
        return $out;
    }
}
