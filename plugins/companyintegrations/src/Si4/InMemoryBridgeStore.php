<?php

/**
 * DOBLE DE PRUEBA de `BridgeStore` con los MISMOS UNIQUE que las tablas reales (tests de contrato sin BD). La
 * implementación real se prueba en el selftest de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class InMemoryBridgeStore implements BridgeStore
{
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];
    /** @var array<string,int> alias tag ⇒ bridge id */
    public array $aliases = [];
    private int $seq = 0;

    public function findRelated(string $uuid, int $snipeAssetId, string $snipeTag, string $itemtype, int $itemsId): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ($r['receipt_unit_uuid'] === $uuid || (int) $r['snipe_asset_id'] === $snipeAssetId
                || strcasecmp((string) $r['snipe_asset_tag'], $snipeTag) === 0
                || ($r['glpi_itemtype'] === $itemtype && (int) $r['glpi_items_id'] === $itemsId)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public function insert(array $row): int
    {
        foreach ($this->rows as $r) {
            if (($r['receipt_unit_uuid'] !== null && $r['receipt_unit_uuid'] === $row['receipt_unit_uuid'])
                || (int) $r['snipe_asset_id'] === $row['snipe_asset_id']
                || strcasecmp((string) $r['snipe_asset_tag'], $row['snipe_asset_tag']) === 0
                || ($r['glpi_itemtype'] === $row['glpi_itemtype'] && (int) $r['glpi_items_id'] === $row['glpi_items_id'])) {
                throw new \RuntimeException('asset_bridge: Duplicate entry (UNIQUE)');
            }
        }
        if (isset($this->aliases[strtoupper($row['snipe_asset_tag'])])) {
            throw new \RuntimeException('asset_tag_aliases: el alias del tag pertenece a otro puente');
        }
        $id = ++$this->seq;
        $this->rows[$id] = ['id' => $id] + $row + ['sync_status' => 'matched'];
        $this->aliases[strtoupper($row['snipe_asset_tag'])] = $id;
        return $id;
    }

    public function adopt(int $bridgeId, string $uuid, string $snipeTag, string $correlationId): void
    {
        if (!isset($this->rows[$bridgeId]) || $this->rows[$bridgeId]['receipt_unit_uuid'] !== null) {
            throw new \RuntimeException('el puente ya no está libre para adoptar');
        }
        foreach ($this->rows as $r) {
            if ($r['receipt_unit_uuid'] === $uuid) {
                throw new \RuntimeException('asset_bridge: Duplicate entry receipt_unit_uuid');
            }
        }
        $this->ensureAlias($bridgeId, $snipeTag);
        $this->rows[$bridgeId]['receipt_unit_uuid'] = $uuid;
        $this->rows[$bridgeId]['correlation_id'] = $correlationId;
    }

    public function ensureAlias(int $bridgeId, string $snipeTag): void
    {
        $k = strtoupper($snipeTag);
        if (isset($this->aliases[$k]) && $this->aliases[$k] !== $bridgeId) {
            throw new \RuntimeException('asset_tag_aliases: el alias del tag pertenece a otro puente');
        }
        $this->aliases[$k] = $bridgeId;
    }

    /** Puente SI-1 (reconciliación): sin receipt_unit_uuid. Sólo para preparar escenarios de prueba. */
    public function seedSi1(int $snipeAssetId, string $tag, string $itemtype, int $itemsId, int $entityId, ?string $uuid = null): int
    {
        $id = ++$this->seq;
        $this->rows[$id] = ['id' => $id, 'receipt_unit_uuid' => $uuid, 'snipe_asset_id' => $snipeAssetId, 'snipe_asset_tag' => $tag,
            'glpi_itemtype' => $itemtype, 'glpi_items_id' => $itemsId, 'glpi_entity_id' => $entityId, 'serial' => null,
            'correlation_id' => '', 'sync_status' => 'matched'];
        return $id;
    }
}
