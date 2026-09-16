<?php

/**
 * Acceso a la configuración del plugin (contexto Config `plugin:companysignature`).
 *
 * Usa la API soportada de GLPI (`Config::getConfigurationValues`), sin SQL directo ni secretos
 * (ver docs/security/secrets-management.md).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use Config;

final class PluginConfig
{
    public const CONTEXT = 'plugin:companysignature';

    /** Valores por defecto (se persisten en la instalación). */
    public const DEFAULTS = [
        // Registrar evidencia automáticamente al escuchar eventos de companyworkflow.
        'listen_workflow_events' => '1',
        // Emitir eventos propios (companysignature:evidence_recorded / :approval_invalidated).
        'emit_events'            => '1',
        // Generar el PDF aprobado como Document nativo (D1). Si se desactiva, la evidencia igual se registra.
        'compose_pdf'            => '1',
        // Zona horaria de PRESENTACIÓN de las fechas (los timestamps se guardan en UTC).
        'presentation_timezone'  => 'America/Asuncion',
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

    public static function boolean(string $key): bool
    {
        return (int) self::get($key, '0') === 1;
    }

    public static function presentationTimezone(): string
    {
        $tz = (string) self::get('presentation_timezone', 'America/Asuncion');
        return $tz !== '' ? $tz : 'America/Asuncion';
    }
}
