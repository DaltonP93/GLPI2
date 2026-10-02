<?php

/**
 * Formulario de CONFIGURACIÓN de Compras (P2D-4): claves editables desde la UI y su validación PURA (fail-closed).
 * Las políticas JSON avanzadas (mapas de scopes/checkpoints) siguen administrándose por `Config` y se validan al
 * pinnearse; la UI no las expone. Unit-testable.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class ConfigForm
{
    /** Clave → tipo (`currency` | `group` | `int1` (≥1) | `int0opt` (vacío o ≥1) | `bool`). */
    public const KEYS = [
        'default_currency'             => 'currency',
        'approver_group_area_head'     => 'group',
        'approver_group_purchasing'    => 'group',
        'approver_group_finance'       => 'group',
        'quorum_area_head'             => 'int1',
        'quorum_purchasing'            => 'int1',
        'quorum_finance'               => 'int1',
        'sla_hours_area_head'          => 'int0opt',
        'sla_hours_purchasing'         => 'int0opt',
        'sla_hours_finance'            => 'int0opt',
        'cost_include_discounts'       => 'bool',
        'cost_include_taxes'           => 'bool',
        'cost_include_freight'         => 'bool',
        'outbox_max_attempts'          => 'int1',
        'outbox_max_lease_seconds'     => 'int1',
        'receipt_max_units_per_batch'  => 'int1',
        'delivery_max_units_per_batch' => 'int1',
        'inbox_scan_cap'               => 'int1',
        'metrics_max_requests'         => 'int1',
        'notifications_enabled'        => 'bool',
        'sync_on_workflow_events'      => 'bool',
    ];

    /**
     * Valida y normaliza la entrada (sólo claves conocidas; las ausentes no se tocan).
     *
     * @param array<string,mixed> $input
     * @return array<string,string>
     * @throws \InvalidArgumentException
     */
    public static function validate(array $input): array
    {
        $out = [];
        foreach (self::KEYS as $key => $type) {
            if (!array_key_exists($key, $input)) {
                if ($type === 'bool') {
                    $out[$key] = '0'; // checkbox desmarcado
                }
                continue;
            }
            $v = is_scalar($input[$key]) ? trim((string) $input[$key]) : '';
            $out[$key] = match ($type) {
                'currency' => CurrencyPolicy::isWellFormed(strtoupper($v)) ? strtoupper($v) : throw new \InvalidArgumentException("{$key}: moneda inválida"),
                'group'    => preg_match('/^\d{1,10}$/', $v) === 1 ? (string) (int) $v : throw new \InvalidArgumentException("{$key}: grupo inválido"),
                'int1'     => preg_match('/^\d{1,9}$/', $v) === 1 && (int) $v >= 1 ? (string) (int) $v : throw new \InvalidArgumentException("{$key}: debe ser un entero ≥ 1"),
                'int0opt'  => $v === '' ? '' : (preg_match('/^\d{1,6}$/', $v) === 1 && (int) $v >= 1 ? (string) (int) $v : throw new \InvalidArgumentException("{$key}: vacío o entero ≥ 1")),
                'bool'     => in_array($v, ['0', '1', 'on'], true) ? ($v === '0' ? '0' : '1') : throw new \InvalidArgumentException("{$key}: valor inválido"),
                default    => throw new \InvalidArgumentException("{$key}: tipo desconocido"),
            };
        }
        return $out;
    }
}
