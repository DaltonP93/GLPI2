<?php

/**
 * Cálculo PURO y EXACTO de los términos comerciales de una cotización (gate §6, P2D-2).
 *
 * - Nunca `float`: todo pasa por `Money` (aritmética decimal de strings; PYG escala 0).
 * - Los totales se DERIVAN (no se almacenan): `line_total = final_unit_price × quantity` (cantidad ENTERA
 *   de la línea de la solicitud) y `total = subtotal + impuestos + flete − descuentos`.
 * - FAIL-CLOSED: importe con escala inválida, cantidad < 1, cobertura incompleta/duplicada de líneas o
 *   total negativo ⇒ excepción (jamás un término comercial parcial que luego se firmará).
 *
 * Sin dependencias de GLPI: unit-testable.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class QuoteMath
{
    /**
     * @param array<int,array{line_id:int, quantity:int, final_unit_price:string}> $lines  ya ORDENADAS
     *        (line_no, id) — el orden se conserva tal cual en el resultado (determinismo del snapshot).
     * @param array<string,int> $overrides
     * @return array{
     *   currency:string,
     *   lines:array<int,array{line_id:int, quantity:string, final_unit_price:string, line_total:string}>,
     *   subtotal:string, discounts:string, taxes:string, freight:string, total:string
     * }
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function compute(array $lines, string $discounts, string $taxes, string $freight, string $currency, array $overrides = []): array
    {
        if ($lines === []) {
            throw new \InvalidArgumentException('la cotización no tiene líneas con precio (fail-closed)');
        }
        $subtotal = Money::zero($currency, $overrides);
        $outLines = [];
        foreach ($lines as $l) {
            $qty = (int) ($l['quantity'] ?? 0);
            if ($qty < 1) {
                throw new \InvalidArgumentException('cantidad de línea inválida para cotizar (fail-closed)');
            }
            $price = Money::of((string) ($l['final_unit_price'] ?? ''), $currency, $overrides);
            $lineTotal = $price->timesInt($qty);
            $subtotal = $subtotal->plus($lineTotal);
            $outLines[] = [
                'line_id'          => (int) ($l['line_id'] ?? 0),
                'quantity'         => (string) $qty,
                'final_unit_price' => $price->amount(),
                'line_total'       => $lineTotal->amount(),
            ];
        }
        $d = Money::of($discounts, $currency, $overrides);
        $t = Money::of($taxes, $currency, $overrides);
        $f = Money::of($freight, $currency, $overrides);
        // minus() lanza si el descuento supera subtotal+impuestos+flete (total negativo prohibido).
        $total = $subtotal->plus($t)->plus($f)->minus($d);

        return [
            'currency'  => $subtotal->currency(),
            'lines'     => $outLines,
            'subtotal'  => $subtotal->amount(),
            'discounts' => $d->amount(),
            'taxes'     => $t->amount(),
            'freight'   => $f->amount(),
            'total'     => $total->amount(),
        ];
    }

    /**
     * Cobertura EXACTA: cada línea de la solicitud tiene exactamente un precio en la cotización y la
     * cotización no referencia líneas ajenas. FAIL-CLOSED.
     *
     * @param array<int,int> $requestLineIds
     * @param array<int,int> $quotedLineIds
     * @throws \InvalidArgumentException
     */
    public static function assertCoverage(array $requestLineIds, array $quotedLineIds): void
    {
        if (count($quotedLineIds) !== count(array_unique($quotedLineIds))) {
            throw new \InvalidArgumentException('la cotización repite una línea (fail-closed)');
        }
        $missing = array_diff($requestLineIds, $quotedLineIds);
        $foreign = array_diff($quotedLineIds, $requestLineIds);
        if ($missing !== []) {
            throw new \InvalidArgumentException('la cotización no cotiza todas las líneas (faltan: ' . implode(',', $missing) . ') (fail-closed)');
        }
        if ($foreign !== []) {
            throw new \InvalidArgumentException('la cotización referencia líneas ajenas a la solicitud (fail-closed)');
        }
    }
}
