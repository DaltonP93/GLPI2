<?php

/**
 * Verifica (read-only) que la configuración de etiquetas de Snipe produzca un QR
 * `prefix + asset_tag` (target `plain_asset_tag`) apuntando al gateway GLPI2. Lógica PURA.
 *
 * No modifica nada en Snipe: sólo INSPECCIONA los settings recibidos por API.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

final class LabelConfigChecker
{
    /**
     * @param array<string,mixed>|null $settings settings devueltos por Snipe (o null)
     * @return array{ok:bool, target:?string, has_prefix:bool, detail:string}
     */
    public function check(?array $settings): array
    {
        if (!is_array($settings)) {
            return ['ok' => false, 'target' => null, 'has_prefix' => false, 'detail' => 'sin settings'];
        }
        $target = $settings['label2_2d_target'] ?? ($settings['label2_2d_type'] ?? null);
        $target = $target !== null ? (string) $target : null;
        $prefix = (string) ($settings['label2_2d_prefix'] ?? '');
        $ok = ($target === 'plain_asset_tag') && $prefix !== '';
        return [
            'ok'         => $ok,
            'target'     => $target,
            'has_prefix' => $prefix !== '',
            'detail'     => $ok
                ? 'QR = prefix + asset_tag (apunta al gateway)'
                : 'target debe ser plain_asset_tag con prefijo del gateway',
        ];
    }
}
