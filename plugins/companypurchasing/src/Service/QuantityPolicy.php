<?php

/**
 * Política de cantidades de líneas (gate §Líneas), pura y unit-testable.
 *
 * v1: cantidad ENTERA positiva. Los ítems físicos inventariables la EXIGEN; los no inventariables la
 * usan igual en v1 (la cantidad DECIMAL por unidad de medida queda como deuda técnica documentada).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class QuantityPolicy
{
    /**
     * Valida y normaliza una cantidad. Devuelve el entero positivo.
     *
     * @throws \InvalidArgumentException
     */
    public static function validate(mixed $raw, bool $inventoriable): int
    {
        if ($raw === null || $raw === '') {
            throw new \InvalidArgumentException('cantidad requerida');
        }
        $s = trim((string) $raw);
        if (preg_match('/^\d{1,12}$/', $s) !== 1) {
            // Rechaza decimales ("2.5"), negativos, notación exponencial, etc.
            throw new \InvalidArgumentException('la cantidad debe ser un entero positivo');
        }
        $n = (int) $s;
        if ($n <= 0) {
            throw new \InvalidArgumentException('la cantidad debe ser mayor que cero');
        }
        // `$inventoriable` no relaja la regla en v1 (ambos exigen entero positivo); se conserva el
        // parámetro para diferenciar la política cuando P2D-3 introduzca unidades de medida.
        return $n;
    }
}
