<?php

/**
 * Política EXPLÍCITA del Infocom de SI4-2 (ADR-0021 §7). PURA (sin GLPI, sin float).
 *
 * Fuente canónica: el handoff v1 de Compras (P2D-3). `value` = `unit_cost` de ESTA unidad (nunca total ÷ cantidad)
 * convertido EXACTAMENTE a `glpi_infocoms.value decimal(20,4)` (GLPI 11.0.8):
 *   - escala ≤ 4 ⇒ se completa con ceros (PYG escala 0 siempre es exacto);
 *   - escala 5–6 ⇒ sólo si los dígitos sobrantes son 0;
 *   - más de 16 dígitos enteros ⇒ no representable.
 * No representable, o moneda distinta de la configurada para el Infocom (GLPI no guarda moneda por Infocom) ⇒
 * MANUAL_REVIEW ANTES de escribir nada en GLPI: jamás se redondea ni se convierte.
 *
 * Campos PROPIOS del Infocom: `value`, `suppliers_id` (proveedor de la compra), `order_number` (número de solicitud) y
 * `delivery_date` (fecha local de la recepción física). `buy_date`/`order_date` NO: el handoff v1 no trae la fecha de
 * compra. Un Infocom existente sólo se COMPLETA en campos propios vacíos/por defecto; igual ⇒ nada; distinto ⇒ conflicto.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class InfocomPolicy
{
    /** Escala de `glpi_infocoms.value` (decimal(20,4)) y dígitos enteros máximos. */
    public const GLPI_SCALE = 4;
    public const GLPI_INT_DIGITS = 16;

    /** Campos del Infocom que escribe SI4-2 (ningún otro). */
    public const OWNED = ['value', 'suppliers_id', 'order_number', 'delivery_date'];

    /**
     * @param array<string,mixed> $payload handoff v1
     * @return array{ok:bool, fields:array<string,int|string>, class:string, reason:string}
     */
    public static function plan(array $payload, string $infocomCurrency): array
    {
        $currency = (string) ($payload['currency'] ?? '');
        if ($currency === '' || $currency !== $infocomCurrency) {
            return self::fail('infocom_currency', 'moneda de la unidad (' . $currency . ') distinta de la del Infocom (' . $infocomCurrency . '): no se convierte');
        }
        $value = self::toGlpiValue((string) ($payload['unit_cost'] ?? ''));
        if ($value === null) {
            return self::fail('infocom_scale', 'unit_cost no representable exactamente en decimal(20,4): no se redondea');
        }
        $supplier = $payload['supplier_id'] ?? 0;
        if (!is_int($supplier) || $supplier <= 0) {
            return self::fail('infocom_supplier', 'handoff sin proveedor');
        }
        $date = self::localDate((string) ($payload['received_at'] ?? ''));
        if ($date === null) {
            return self::fail('infocom_date', 'received_at inválido');
        }
        $order = mb_substr((string) ($payload['request_number'] ?? ''), 0, 255);
        if ($order === '') {
            return self::fail('infocom_order', 'handoff sin número de solicitud');
        }
        return ['ok' => true, 'fields' => ['value' => $value, 'suppliers_id' => $supplier, 'order_number' => $order,
            'delivery_date' => $date], 'class' => '', 'reason' => ''];
    }

    /**
     * "1500000" ⇒ "1500000.0000"; "3.667" ⇒ "3.6670"; "1.234500" ⇒ "1.2345"; "1.23456" ⇒ null (no representable).
     */
    public static function toGlpiValue(string $amount): ?string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            return null;
        }
        $int  = ltrim($m[1], '0');
        $int  = $int === '' ? '0' : $int;
        $frac = $m[2] ?? '';
        if (strlen($int) > self::GLPI_INT_DIGITS) {
            return null;
        }
        if (strlen($frac) > self::GLPI_SCALE) {
            if (trim(substr($frac, self::GLPI_SCALE), '0') !== '') {
                return null; // dígitos significativos más allá de 4 decimales: NUNCA se redondea
            }
            $frac = substr($frac, 0, self::GLPI_SCALE);
        }
        return $int . '.' . str_pad($frac, self::GLPI_SCALE, '0');
    }

    /** Normaliza un `value` leído de GLPI ("2339.0000", "2339.00", "2339") para comparar como string. */
    public static function normalizeStored(mixed $v): ?string
    {
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (!is_string($v) || $v === '') {
            return null;
        }
        return self::toGlpiValue($v);
    }

    /**
     * Fecha LOCAL de la recepción. El handoff real trae ISO 8601 con desplazamiento (`date('c')` en la zona de la
     * sesión de Compras): la fecha es la que ese mismo texto expresa (no se convierte de zona).
     */
    public static function localDate(string $receivedAt): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}:\d{2}(?:[+-]\d{2}:\d{2}|Z)?)?$/', $receivedAt, $m) !== 1) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /**
     * Compara el Infocom actual (null = no existe) con el plan: qué completar y qué está en conflicto.
     *
     * @param array<string,mixed>|null $current
     * @param array<string,int|string> $plan
     * @return array{fill:array<string,int|string>, conflicts:array<int,string>}
     */
    public static function reconcile(?array $current, array $plan): array
    {
        if ($current === null) {
            return ['fill' => $plan, 'conflicts' => []];
        }
        $fill = [];
        $conflicts = [];
        foreach (self::OWNED as $f) {
            $want = $plan[$f];
            $have = $current[$f] ?? null;
            switch ($f) {
                case 'value':
                    $h = self::normalizeStored($have);
                    $empty = $h === null || $h === '0.0000';
                    $equal = $h === $want;
                    break;
                case 'suppliers_id':
                    $h = (int) ($have ?? 0);
                    $empty = $h === 0;
                    $equal = $h === $want;
                    break;
                default: // order_number, delivery_date
                    $h = $have === null ? '' : (string) $have;
                    $empty = $h === '' || $h === '0000-00-00';
                    $equal = $h === (string) $want;
            }
            if ($equal) {
                continue;
            }
            if ($empty) {
                $fill[$f] = $want;
            } else {
                $conflicts[] = $f;
            }
        }
        return ['fill' => $fill, 'conflicts' => $conflicts];
    }

    /** @return array{ok:bool, fields:array<string,int|string>, class:string, reason:string} */
    private static function fail(string $class, string $reason): array
    {
        return ['ok' => false, 'fields' => [], 'class' => $class, 'reason' => $reason];
    }
}
