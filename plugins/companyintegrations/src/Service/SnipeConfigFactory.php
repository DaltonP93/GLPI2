<?php

/**
 * Construye SnipeClientConfig desde la configuración del plugin + el TOKEN desde variable de
 * entorno/secret (nunca desde Git/BD). Separa el secreto de la configuración versionable.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

use GlpiPlugin\Companyintegrations\Client\SnipeClientConfig;

final class SnipeConfigFactory
{
    public function fromConfig(): SnipeClientConfig
    {
        $token = (string) (getenv(PluginConfig::TOKEN_ENV) ?: '');

        return new SnipeClientConfig(
            (string) PluginConfig::get('snipe_base_url', ''),
            $token,
            (int) PluginConfig::get('timeout_ms', '5000'),
            (int) PluginConfig::get('max_retries', '3'),
            (int) PluginConfig::get('backoff_base_ms', '200'),
            (int) PluginConfig::get('breaker_threshold', '5'),
            (int) PluginConfig::get('breaker_cooldown_sec', '30')
        );
    }
}
