<?php

/**
 * Política de escala decimal por moneda (gate §Dinero).
 *
 * - PYG: escala 0 (sin decimales) — regla dura, NO overridable.
 * - Otras monedas: escala por defecto configurable; el objetivo de v1 NO es un módulo FX, sólo
 *   representar importes exactos en la moneda de la compra.
 *
 * La escala nunca supera la interna de `Decimal` (6).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class CurrencyPolicy
{
    /** Escala por defecto para monedas no listadas. */
    public const DEFAULT_SCALE = 2;

    /** Escalas fijas NO overridables (regla de negocio). */
    private const FIXED = ['PYG' => 0];

    /** Escalas por defecto conocidas (overridables salvo las FIXED). */
    private const DEFAULTS = ['PYG' => 0, 'USD' => 2, 'EUR' => 2, 'BRL' => 2, 'ARS' => 2];

    /** @return bool ¿el código de moneda tiene forma ISO-4217 (3 letras)? */
    public static function isWellFormed(string $currency): bool
    {
        return preg_match('/^[A-Z]{3}$/', $currency) === 1;
    }

    /**
     * Escala permitida para una moneda. `$overrides` (p. ej. desde config) puede ajustar monedas
     * NO fijas; PYG siempre es 0.
     *
     * @param array<string,int> $overrides
     */
    public static function scale(string $currency, array $overrides = []): int
    {
        $c = strtoupper(trim($currency));
        if (isset(self::FIXED[$c])) {
            return self::FIXED[$c];
        }
        if (isset($overrides[$c])) {
            return max(0, min(Decimal::SCALE, (int) $overrides[$c]));
        }
        return self::DEFAULTS[$c] ?? self::DEFAULT_SCALE;
    }
}
