<?php

/**
 * Generador de correlation_id (propagado a logs/auditoría/headers). PURO.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

final class CorrelationId
{
    public static function generate(string $prefix = 'ci'): string
    {
        try {
            $rand = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $rand = substr(md5((string) mt_rand() . microtime()), 0, 16);
        }
        return $prefix . '-' . $rand;
    }
}
