<?php

/**
 * Resolución ESTABLE por asset tag: busca el tag ACTUAL en el puente y, si no está, en los alias
 * HISTÓRICOS → una etiqueta física impresa con un tag viejo sigue resolviendo al mismo activo.
 * NO decide acceso: el asset tag identifica; GLPI autoriza (la ACL se aplica en el controlador).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Model\AssetTagAlias;

final class AssetResolver
{
    /** @return AssetBridge|null puente resuelto (por tag actual o histórico) o null. */
    public function resolveByTag(string $tag): ?AssetBridge
    {
        $tag = trim($tag);
        if ($tag === '') {
            return null;
        }

        $bridge = new AssetBridge();
        if ($bridge->getFromDBByCrit(['snipe_asset_tag' => $tag])) {
            return $bridge;
        }

        // Alias histórico.
        $alias = new AssetTagAlias();
        if ($alias->getFromDBByCrit(['asset_tag' => $tag])) {
            $b = new AssetBridge();
            if ($b->getFromDB((int) $alias->fields['asset_bridge_id'])) {
                return $b;
            }
        }
        return null;
    }
}
