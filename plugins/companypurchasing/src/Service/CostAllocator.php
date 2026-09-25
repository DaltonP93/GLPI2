<?php

/**
 * Costo ATRIBUIBLE exacto por línea y por unidad (P2D-3; gate §6). PURO (sin GLPI), sin `float`, sin bcmath.
 *
 * Nunca `total ÷ cantidad de activos`. La base es el `final_unit_price` de la línea de la cotización
 * seleccionada; los ajustes de CABECERA (`discounts`, `taxes`, `freight`) sólo entran si la `CostPolicy`
 * pinneada lo dice. Todo se calcula en UNIDADES MENORES de la moneda (PYG: guaraníes, escala 0; USD:
 * centavos, escala 2), con aritmética de strings (`Decimal`), por lo que no hay redondeo binario.
 *
 * Método `line_value_largest_remainder` (asignación de un ajuste A entre líneas):
 *   1. peso de la línea i = `line_value_i` = precio final × cantidad (en unidades menores). Si todas las
 *      líneas valen 0, el peso es la cantidad (siempre ≥ 1).
 *   2. cuota_i = ⌊A · peso_i / Σpeso⌋ y resto_i = (A · peso_i) mod Σpeso.
 *   3. Las unidades menores sobrantes (A − Σcuota, siempre < nº de líneas) se dan de a una a las líneas con
 *      MAYOR resto; empate ⇒ la línea que aparece antes (orden line_no, id). Σ asignado = A exacto.
 *   costo_línea = line_value + impuestos + flete − descuentos (sólo los incluidos). Negativo ⇒ fail-closed.
 *
 * Reparto `floor_remainder_to_first_units` (costo de línea C entre Q unidades): base = ⌊C/Q⌋, r = C mod Q;
 * la unidad ordinal k (1..Q, en orden de recepción) cuesta base + 1 unidad menor si k ≤ r, si no base.
 * Σ unidades = C exacto, sin depender de cómo se partan los lotes.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class CostAllocator
{
    /**
     * @param array<int,array{line_id:int, quantity:int|string, final_unit_price:string}> $lines  ORDENADAS
     *        (line_no, id): el orden desempata la asignación.
     * @param array<string,int> $overrides escalas por moneda
     * @return array<int,array{line_id:int, quantity:int, final_unit_price:string, line_value:string,
     *               discounts:string, taxes:string, freight:string, line_cost:string}>  por posición
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function allocate(
        array $lines,
        string $discounts,
        string $taxes,
        string $freight,
        string $currency,
        CostPolicy $policy,
        array $overrides = []
    ): array {
        if ($lines === []) {
            throw new \InvalidArgumentException('sin líneas para costear (fail-closed)');
        }
        $scale = CurrencyPolicy::scale(strtoupper($currency), $overrides);
        $values = [];
        $qtys = [];
        $prices = [];
        foreach ($lines as $i => $l) {
            $qty = (int) ($l['quantity'] ?? 0);
            if ($qty < 1 || (string) $qty !== trim((string) ($l['quantity'] ?? ''))) {
                throw new \InvalidArgumentException('cantidad de línea inválida para costear (fail-closed)');
            }
            $price = Money::of((string) ($l['final_unit_price'] ?? ''), $currency, $overrides);
            $prices[$i] = $price->amount();
            $qtys[$i] = $qty;
            $values[$i] = self::toMinor($price->timesInt($qty), $scale);
        }
        $weights = $values;
        if (array_reduce($values, static fn (bool $zero, string $v): bool => $zero && $v === '0', true)) {
            $weights = array_map('strval', $qtys);
        }
        $alloc = [];
        foreach (['discounts' => [$discounts, $policy->includeDiscounts()], 'taxes' => [$taxes, $policy->includeTaxes()], 'freight' => [$freight, $policy->includeFreight()]] as $k => [$raw, $included]) {
            $amount = self::toMinor(Money::of($raw, $currency, $overrides), $scale);
            $alloc[$k] = $included ? self::largestRemainder($amount, $weights) : array_fill_keys(array_keys($weights), '0');
        }
        $out = [];
        foreach ($lines as $i => $l) {
            $plus = Decimal::addStr(Decimal::addStr($values[$i], $alloc['taxes'][$i]), $alloc['freight'][$i]);
            if (Decimal::cmpStr($plus, $alloc['discounts'][$i]) < 0) {
                throw new \RuntimeException('costo de línea negativo tras descuentos (fail-closed)');
            }
            $cost = Decimal::subStr($plus, $alloc['discounts'][$i]);
            $out[] = [
                'line_id'          => (int) ($l['line_id'] ?? 0),
                'quantity'         => $qtys[$i],
                'final_unit_price' => $prices[$i],
                'line_value'       => self::fromMinor($values[$i], $scale),
                'discounts'        => self::fromMinor($alloc['discounts'][$i], $scale),
                'taxes'            => self::fromMinor($alloc['taxes'][$i], $scale),
                'freight'          => self::fromMinor($alloc['freight'][$i], $scale),
                'line_cost'        => self::fromMinor($cost, $scale),
            ];
        }
        return $out;
    }

    /**
     * Costo de la unidad ordinal `$ordinal` (1..`$quantity`) de una línea cuyo costo total es `$lineCost`.
     *
     * @param array<string,int> $overrides
     * @throws \InvalidArgumentException
     */
    public static function unitCost(string $lineCost, int $quantity, int $ordinal, string $currency, array $overrides = []): string
    {
        if ($quantity < 1 || $ordinal < 1 || $ordinal > $quantity) {
            throw new \InvalidArgumentException('ordinal de unidad fuera de rango (fail-closed)');
        }
        $scale = CurrencyPolicy::scale(strtoupper($currency), $overrides);
        $minor = self::toMinor(Money::of($lineCost, $currency, $overrides), $scale);
        [$base, $rem] = Decimal::divModStr($minor, (string) $quantity);
        $unit = Decimal::cmpStr((string) $ordinal, $rem) <= 0 ? Decimal::addStr($base, '1') : $base;
        return self::fromMinor($unit, $scale);
    }

    /**
     * Asignación por mayor resto (ver cabecera). PURA.
     *
     * @param array<int,string> $weights  enteros no negativos (unidades menores), Σ > 0
     * @return array<int,string>          misma clave que `$weights`; Σ = `$amount`
     */
    public static function largestRemainder(string $amount, array $weights): array
    {
        $total = '0';
        foreach ($weights as $w) {
            $total = Decimal::addStr($total, $w);
        }
        if ($total === '0') {
            throw new \InvalidArgumentException('pesos de asignación nulos (fail-closed)');
        }
        $shares = [];
        $rems = [];
        $assigned = '0';
        foreach ($weights as $i => $w) {
            [$q, $r] = Decimal::divModStr(Decimal::mulStr($amount, $w), $total);
            $shares[$i] = $q;
            $rems[$i] = $r;
            $assigned = Decimal::addStr($assigned, $q);
        }
        $left = (int) Decimal::subStr($amount, $assigned); // < nº de líneas
        if ($left > 0) {
            $order = array_keys($weights);
            $pos = array_flip($order);
            usort($order, static function ($a, $b) use ($rems, $pos): int {
                $c = Decimal::cmpStr($rems[$b], $rems[$a]);
                return $c !== 0 ? $c : $pos[$a] <=> $pos[$b];
            });
            for ($k = 0; $k < $left; $k++) {
                $shares[$order[$k]] = Decimal::addStr($shares[$order[$k]], '1');
            }
        }
        return $shares;
    }

    /** Importe (validado a la escala de su moneda) → unidades menores (string entero). */
    private static function toMinor(Money $m, int $scale): string
    {
        [$q, $r] = Decimal::divModStr($m->microUnits(), '1' . str_repeat('0', Decimal::SCALE - $scale));
        if ($r !== '0') {
            throw new \RuntimeException('importe con más decimales que la escala de la moneda (fail-closed)');
        }
        return $q;
    }

    /** Unidades menores → decimal exacto a la escala de la moneda. */
    private static function fromMinor(string $minor, int $scale): string
    {
        return Decimal::formatMicro($minor . str_repeat('0', Decimal::SCALE - $scale), $scale);
    }
}
