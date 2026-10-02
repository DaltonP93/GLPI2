<?php

/**
 * Cálculos PUROS de métricas (P2D-4; ADR-0023 §10). Sin GLPI: unit-testables.
 *
 *   - Dinero: acumuladores en MICRO-unidades (strings, `Decimal`) SEPARADOS por `currency_code`; nunca float, nunca
 *     se suman monedas distintas; el formato final usa la escala de cada moneda (PYG ⇒ 0) y jamás redondea.
 *   - Duración por etapa: a partir del ledger del motor (entradas a estados), tiempo en cada estado hasta la entrada
 *     siguiente (la etapa abierta cuenta hasta `$now`).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class MetricsMath
{
    /** Eventos del ledger que representan ENTRAR a un estado. */
    public const ENTRY_EVENTS = ['started', 'transitioned', 'approval_invalidated'];

    /**
     * Suma un importe ALMACENADO (`DECIMAL(20,6)`) × cantidad al acumulador de su moneda. FAIL-CLOSED ante importes
     * o monedas inválidas (lanza; el llamador decide).
     *
     * @param array<string,string> $acc  moneda → micro-unidades
     */
    public static function addStored(array &$acc, string $currency, string $stored, int $qty = 1): void
    {
        $currency = strtoupper(trim($currency));
        if (!CurrencyPolicy::isWellFormed($currency) || $qty < 0 || !Decimal::isValid(trim($stored), Decimal::SCALE)) {
            throw new \InvalidArgumentException('importe o moneda inválidos para métricas');
        }
        $micro = Decimal::mulStrByInt(Decimal::toMicro(trim($stored)), $qty);
        $acc[$currency] = Decimal::addStr($acc[$currency] ?? '0', $micro);
    }

    /** Resta (descuentos) del acumulador de la moneda; FAIL-CLOSED si quedaría negativo. @param array<string,string> $acc */
    public static function subStored(array &$acc, string $currency, string $stored): void
    {
        $currency = strtoupper(trim($currency));
        if (!Decimal::isValid(trim($stored), Decimal::SCALE)) {
            throw new \InvalidArgumentException('importe inválido para métricas');
        }
        $acc[$currency] = Decimal::subStr($acc[$currency] ?? '0', Decimal::toMicro(trim($stored)));
    }

    /** Fusiona acumuladores (moneda a moneda; nunca entre monedas). @param array<string,string> $acc @param array<string,string> $add */
    public static function merge(array &$acc, array $add): void
    {
        foreach ($add as $cur => $micro) {
            $acc[$cur] = Decimal::addStr($acc[$cur] ?? '0', $micro);
        }
    }

    /**
     * Totales formateados por moneda (orden alfabético estable) a la escala de cada moneda.
     *
     * @param array<string,string> $acc
     * @param array<string,int>    $overrides
     * @return array<string,string> moneda → importe exacto
     */
    public static function format(array $acc, array $overrides = []): array
    {
        ksort($acc, SORT_STRING);
        $out = [];
        foreach ($acc as $cur => $micro) {
            try {
                $out[$cur] = Decimal::formatMicro($micro, CurrencyPolicy::scale($cur, $overrides));
            } catch (\RuntimeException) {
                // La escala vigente perdería dígitos (p. ej. una compra pinneada con más decimales): se muestra la
                // representación EXACTA completa en vez de redondear.
                $out[$cur] = rtrim(rtrim(Decimal::formatMicro($micro, Decimal::SCALE), '0'), '.');
            }
        }
        return $out;
    }

    /**
     * Segundos en cada estado a partir de filas del ledger (de una o varias instancias).
     *
     * @param array<int,array{instances_id:int|string, event:string, to_code:string, date:string, id?:int|string}> $rows
     * @return array<string,array{count:int, total:int, max:int}>
     */
    public static function stageDurations(array $rows, int $now): array
    {
        $byInstance = [];
        foreach ($rows as $r) {
            if (!in_array((string) ($r['event'] ?? ''), self::ENTRY_EVENTS, true) || (string) ($r['to_code'] ?? '') === '') {
                continue;
            }
            $ts = strtotime((string) ($r['date'] ?? ''));
            if ($ts === false) {
                continue;
            }
            $byInstance[(int) $r['instances_id']][] = ['ts' => $ts, 'code' => (string) $r['to_code'], 'id' => (int) ($r['id'] ?? 0)];
        }
        $agg = [];
        foreach ($byInstance as $entries) {
            usort($entries, static fn (array $a, array $b): int => [$a['ts'], $a['id']] <=> [$b['ts'], $b['id']]);
            $n = count($entries);
            for ($i = 0; $i < $n; $i++) {
                $end = $i + 1 < $n ? $entries[$i + 1]['ts'] : $now;
                $secs = max(0, $end - $entries[$i]['ts']);
                $code = $entries[$i]['code'];
                $agg[$code] ??= ['count' => 0, 'total' => 0, 'max' => 0];
                $agg[$code]['count']++;
                $agg[$code]['total'] += $secs;
                $agg[$code]['max'] = max($agg[$code]['max'], $secs);
            }
        }
        ksort($agg, SORT_STRING);
        return $agg;
    }

    /**
     * Resumen en HORAS enteras (sin float): promedio = ⌊total / count⌋, máximo.
     *
     * @param array{count:int, total:int, max:int} $a
     * @return array{count:int, avg_hours:int, max_hours:int}
     */
    public static function hours(array $a): array
    {
        $count = max(0, (int) $a['count']);
        return [
            'count'     => $count,
            'avg_hours' => $count > 0 ? intdiv(intdiv((int) $a['total'], $count), 3600) : 0,
            'max_hours' => intdiv(max(0, (int) $a['max']), 3600),
        ];
    }

    /**
     * Agrupa importes con decimales en el separador del idioma (presentación; el valor sigue EXACTO).
     * `es*` ⇒ miles "." y decimales ","; otro ⇒ miles "," y decimales ".".
     */
    public static function display(string $amount, string $language): string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            return $amount;
        }
        [$thousands, $decimal] = str_starts_with(strtolower($language), 'es') ? ['.', ','] : [',', '.'];
        $int = $m[1];
        $grouped = '';
        while (strlen($int) > 3) {
            $grouped = $thousands . substr($int, -3) . $grouped;
            $int = substr($int, 0, -3);
        }
        $grouped = $int . $grouped;
        return isset($m[2]) && $m[2] !== '' ? $grouped . $decimal . $m[2] : $grouped;
    }
}
