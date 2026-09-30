<?php

/**
 * Identidad remota DETERMINISTA de una unidad en Snipe-IT (ADR-0020 §1). PURO.
 *
 *   asset_tag = <prefijo> + 32 hex en mayúsculas de `receipt_unit_uuid` (sin guiones)
 *
 * Biyectiva: el tag identifica la unidad y la unidad el tag, así que el tag se conoce ANTES del POST y un reintento
 * tras un crash lo encuentra con el lookup exacto `bytag`. El prefijo sale de configuración (nunca literal).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class AssetTagDeriver
{
    public const PREFIX_PATTERN = '/^[A-Z0-9][A-Z0-9-]{0,15}$/';
    public const UUID_PATTERN   = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match(self::PREFIX_PATTERN, $prefix) === 1;
    }

    /** @throws \InvalidArgumentException prefijo o UUID inválidos (fail-closed) */
    public static function tagFor(string $prefix, string $receiptUnitUuid): string
    {
        if (!self::isValidPrefix($prefix)) {
            throw new \InvalidArgumentException('si4_asset_tag_prefix inválido');
        }
        if (preg_match(self::UUID_PATTERN, $receiptUnitUuid) !== 1) {
            throw new \InvalidArgumentException('receipt_unit_uuid inválido');
        }
        return $prefix . strtoupper(str_replace('-', '', $receiptUnitUuid));
    }
}
