<?php

/**
 * Rate limiting con almacenamiento TEMPORAL (cache PSR-16 de GLPI, $GLPI_CACHE).
 * NO persiste en base de datos; retención corta (TTL). Ver ADR-0011 (privacidad).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

final class RateLimiter
{
    /**
     * Registra un intento y dice si el actor está DENTRO del límite.
     *
     * @param string $bucket clave lógica (p. ej. "report:<token>")
     * @param int    $max    máximo de intentos permitidos en la ventana
     * @param int    $ttl    ventana en segundos
     * @return bool true si se permite (no superó el límite)
     */
    public function allow(string $bucket, int $max, int $ttl): bool
    {
        global $GLPI_CACHE;

        // Si no hay cache disponible, no bloqueamos (fail-open del rate limit;
        // la autorización real sigue siendo la ACL, no esto).
        if (!isset($GLPI_CACHE) || !is_object($GLPI_CACHE)) {
            return true;
        }

        $key = 'companyqr_rl_' . sha1($bucket);
        $count = (int) ($GLPI_CACHE->get($key, 0));
        $count++;
        $GLPI_CACHE->set($key, $count, $ttl);

        return $count <= $max;
    }
}
