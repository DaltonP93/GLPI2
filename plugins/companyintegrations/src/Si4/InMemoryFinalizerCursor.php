<?php

/**
 * DOBLE DE PRUEBA de `FinalizerCursor` con la misma semántica compare-and-set que `DbFinalizerCursor`. Compartir la
 * MISMA instancia entre dos `Si4Finalizer` emula el almacenamiento durable entre procesos CLI; la persistencia real
 * (tabla propia `si4_runtime`) se prueba en el selftest de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class InMemoryFinalizerCursor implements FinalizerCursor
{
    public int $value = 0;
    public int $writes = 0;

    public function get(): int
    {
        return $this->value;
    }

    public function advance(int $expected, int $next): bool
    {
        if ($this->value !== $expected) {
            return false;
        }
        $this->value = max(0, $next);
        $this->writes++;
        return true;
    }
}
