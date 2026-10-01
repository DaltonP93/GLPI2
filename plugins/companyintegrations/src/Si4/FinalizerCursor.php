<?php

/**
 * Cursor DURABLE del finalizador de SI4-3 (ADR-0022 §6): el `id` de la ÚLTIMA saga QR_READY INSPECCIONADA (no la
 * última completada). Vive fuera del proceso (cada corrida CLI termina) para que el recorrido sea round-robin acotado:
 * una saga pendiente nunca impide inspeccionar las posteriores y, al llegar al final, se vuelve a 0 (wrap-around).
 *
 * Concurrencia: `advance()` es compare-and-set sobre el valor LEÍDO. Si otra corrida ya lo movió, no se pisa (el cursor
 * nunca retrocede por una carrera ni se corrompe); a lo sumo una saga se inspecciona dos veces, y `complete()` con la
 * guarda `state = QR_READY` sigue siendo la barrera exactamente-una-vez.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

interface FinalizerCursor
{
    /** Último `id` inspeccionado (0 = inicio de una ronda). */
    public function get(): int;

    /** Compare-and-set: mueve el cursor de `$expected` (lo leído) a `$next`. false = otra corrida lo movió antes. */
    public function advance(int $expected, int $next): bool;
}
