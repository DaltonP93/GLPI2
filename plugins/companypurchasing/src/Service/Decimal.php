<?php

/**
 * Aritmética decimal EXACTA en PHP puro (sin `float`, sin depender de bcmath/gmp).
 *
 * Motivación (gate §Dinero): un importe monetario NUNCA se representa como `float` (redondeo binario).
 * Se trabaja con el string decimal exacto y con "micro-unidades" (enteros a escala fija interna 6, que
 * coincide con la parte decimal de `DECIMAL(20,6)`). Las operaciones (suma, multiplicación por entero)
 * se hacen sobre strings de dígitos (schoolbook), de forma determinista y sin overflow de 64 bits.
 *
 * Este helper NO redondea silenciosamente: `formatMicro()` falla si tuviera que descartar un dígito
 * significativo, y la validación de escala vive en `CurrencyPolicy`/`Money`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class Decimal
{
    /** Escala interna (micro-unidades). Coincide con DECIMAL(_,6). */
    public const SCALE = 6;

    /**
     * ¿El string es un decimal no negativo válido con a lo sumo `$allowedScale` decimales
     * y a lo sumo 14 dígitos enteros (cabe en DECIMAL(20,6))?
     */
    public static function isValid(string $amount, int $allowedScale): bool
    {
        if ($allowedScale < 0 || $allowedScale > self::SCALE) {
            return false;
        }
        if (!preg_match('/^\d{1,14}(?:\.(\d{1,6}))?$/', $amount, $m)) {
            return false;
        }
        $frac = $m[1] ?? '';
        return strlen($frac) <= $allowedScale;
    }

    /**
     * Convierte un decimal no negativo YA VALIDADO en su string de micro-unidades (entero, escala 6).
     * Ej.: "1000.50" → "1000500000"; "1000" → "1000000000"; "0" → "0".
     */
    public static function toMicro(string $amount): string
    {
        $parts = explode('.', $amount, 2);
        $intPart = $parts[0];
        $frac    = $parts[1] ?? '';
        $frac6   = substr($frac . '000000', 0, self::SCALE); // pad derecha a 6 (la parte fraccional ya es ≤6)
        $micro   = ltrim($intPart, '0') . $frac6;
        $micro   = ltrim($micro, '0');
        return $micro === '' ? '0' : $micro;
    }

    /**
     * Formatea un string de micro-unidades a decimal con `$scale` decimales.
     * FAIL-CLOSED: si hubiera dígitos significativos más allá de `$scale`, lanza (nunca redondea).
     */
    public static function formatMicro(string $micro, int $scale): string
    {
        if ($scale < 0 || $scale > self::SCALE) {
            throw new \InvalidArgumentException('escala fuera de rango');
        }
        $micro  = ltrim($micro, '0');
        if ($micro === '') {
            $micro = '0';
        }
        $padded = str_pad($micro, self::SCALE + 1, '0', STR_PAD_LEFT);
        $intPart = substr($padded, 0, -self::SCALE);
        $frac6   = substr($padded, -self::SCALE);

        // No redondear en silencio: los dígitos que se descartarían deben ser cero.
        if (rtrim(substr($frac6, $scale), '0') !== '') {
            throw new \RuntimeException('formatMicro descartaría dígitos significativos (redondeo prohibido)');
        }
        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        if ($scale === 0) {
            return $intPart;
        }
        return $intPart . '.' . substr($frac6, 0, $scale);
    }

    /** Suma de dos strings de enteros no negativos (schoolbook, sin overflow). */
    public static function addStr(string $a, string $b): string
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '') {
            $a = '0';
        }
        if ($b === '') {
            $b = '0';
        }
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $carry = 0;
        $res = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $da = $i >= 0 ? (int) $a[$i] : 0;
            $db = $j >= 0 ? (int) $b[$j] : 0;
            $s = $da + $db + $carry;
            $res = ((string) ($s % 10)) . $res;
            $carry = intdiv($s, 10);
            $i--;
            $j--;
        }
        $res = ltrim($res, '0');
        return $res === '' ? '0' : $res;
    }

    /** Multiplica un string de entero no negativo por un entero no negativo (schoolbook). */
    public static function mulStrByInt(string $a, int $n): string
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('multiplicador negativo');
        }
        if ($n === 0) {
            return '0';
        }
        $a = ltrim($a, '0');
        if ($a === '') {
            return '0';
        }
        $carry = 0;
        $res = '';
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            $prod = ((int) $a[$i]) * $n + $carry;
            $res = ((string) ($prod % 10)) . $res;
            $carry = intdiv($prod, 10);
        }
        while ($carry > 0) {
            $res = ((string) ($carry % 10)) . $res;
            $carry = intdiv($carry, 10);
        }
        $res = ltrim($res, '0');
        return $res === '' ? '0' : $res;
    }

    /** Compara dos strings de enteros no negativos: -1 si a<b, 0 si iguales, 1 si a>b. */
    public static function cmpStr(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '') {
            $a = '0';
        }
        if ($b === '') {
            $b = '0';
        }
        if (strlen($a) !== strlen($b)) {
            return strlen($a) < strlen($b) ? -1 : 1;
        }
        return $a <=> $b; // misma longitud y sólo dígitos ⇒ orden lexicográfico = orden numérico
    }

    /**
     * Producto de dos strings de enteros no negativos (schoolbook), sin overflow (P2D-3: prorrateo de costos,
     * donde `ajuste × peso` puede superar 64 bits).
     */
    public static function mulStr(string $a, string $b): string
    {
        self::assertDigits($a);
        self::assertDigits($b);
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '' || $b === '') {
            return '0';
        }
        $res = '0';
        $shift = '';
        for ($j = strlen($b) - 1; $j >= 0; $j--) {
            $d = (int) $b[$j];
            if ($d !== 0) {
                $res = self::addStr($res, self::mulStrByInt($a, $d) . $shift);
            }
            $shift .= '0';
        }
        return $res;
    }

    /**
     * División entera de strings de enteros no negativos: `[cociente, resto]` con `a = q·b + r`, `0 ≤ r < b`
     * (división larga; cada dígito del cociente se obtiene por restas sucesivas, ≤ 9). FAIL-CLOSED si `b = 0`.
     *
     * @return array{0:string, 1:string}
     */
    public static function divModStr(string $a, string $b): array
    {
        self::assertDigits($a);
        self::assertDigits($b);
        if (ltrim($b, '0') === '') {
            throw new \InvalidArgumentException('división por cero (fail-closed)');
        }
        $q = '';
        $r = '0';
        $len = strlen($a);
        for ($i = 0; $i < $len; $i++) {
            $r = ltrim($r . $a[$i], '0');
            if ($r === '') {
                $r = '0';
            }
            $digit = 0;
            while (self::cmpStr($r, $b) >= 0) {
                $r = self::subStr($r, $b);
                $digit++;
            }
            $q .= (string) $digit;
        }
        $q = ltrim($q, '0');
        return [$q === '' ? '0' : $q, $r];
    }

    /** @throws \InvalidArgumentException si el string no es un entero no negativo (sólo dígitos). */
    private static function assertDigits(string $v): void
    {
        if (preg_match('/^\d+$/', $v) !== 1) {
            throw new \InvalidArgumentException('entero no negativo inválido (fail-closed)');
        }
    }

    /**
     * Resta `a - b` de strings de enteros no negativos (schoolbook). FAIL-CLOSED: si `b > a` lanza
     * (un importe monetario del dominio nunca queda negativo en silencio).
     */
    public static function subStr(string $a, string $b): string
    {
        if (self::cmpStr($a, $b) < 0) {
            throw new \RuntimeException('resta con resultado negativo (fail-closed)');
        }
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '') {
            $a = '0';
        }
        if ($b === '') {
            $b = '0';
        }
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;
        $borrow = 0;
        $res = '';
        while ($i >= 0) {
            $d = ((int) $a[$i]) - $borrow - ($j >= 0 ? (int) $b[$j] : 0);
            if ($d < 0) {
                $d += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $res = ((string) $d) . $res;
            $i--;
            $j--;
        }
        $res = ltrim($res, '0');
        return $res === '' ? '0' : $res;
    }
}
