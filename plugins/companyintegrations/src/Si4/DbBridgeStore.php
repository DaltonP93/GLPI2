<?php

/**
 * `BridgeStore` de PRODUCCIÓN sobre tablas PROPIAS (`asset_bridge`, `asset_tag_aliases`). Nunca toca tablas del core
 * ni de Compras. Los UNIQUE (`receipt_unit_uuid`, `snipe_asset_id`, `snipe_asset_tag`, `glpi_item`, alias `asset_tag`)
 * son la última barrera contra duplicados (ADR-0021 §8).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Model\AssetTagAlias;

final class DbBridgeStore implements BridgeStore
{
    public function findRelated(string $uuid, int $snipeAssetId, string $snipeTag, string $itemtype, int $itemsId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'FROM'  => AssetBridge::getTable(),
            'WHERE' => ['OR' => [
                ['receipt_unit_uuid' => $uuid],
                ['snipe_asset_id' => $snipeAssetId],
                ['snipe_asset_tag' => $snipeTag],
                ['glpi_itemtype' => $itemtype, 'glpi_items_id' => $itemsId],
            ]],
        ]) as $row) {
            $out[(int) $row['id']] = $row;
        }
        return array_values($out);
    }

    public function insert(array $row): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $now = $this->now();
        $DB->beginTransaction();
        try {
            $DB->insert(AssetBridge::getTable(), [
                'receipt_unit_uuid' => $row['receipt_unit_uuid'],
                'snipe_asset_id'    => $row['snipe_asset_id'],
                'snipe_asset_tag'   => $row['snipe_asset_tag'],
                'glpi_itemtype'     => $row['glpi_itemtype'],
                'glpi_items_id'     => $row['glpi_items_id'],
                'glpi_entity_id'    => $row['glpi_entity_id'],
                'serial'            => $row['serial'],
                'sync_status'       => AssetBridge::STATUS_MATCHED,
                'correlation_id'    => mb_substr($row['correlation_id'], 0, 64),
                'last_sync_at'      => $now,
                'date_creation'     => $now,
                'date_mod'          => $now,
            ]);
            $id = (int) $DB->insertId();
            if ($id <= 0) {
                throw new \RuntimeException('asset_bridge: inserción sin id');
            }
            $this->aliasIn($DB, $id, $row['snipe_asset_tag'], $now);
            $DB->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->rollback($DB);
            throw new \RuntimeException('asset_bridge: ' . Si4Errors::sanitize($e->getMessage()), 0, $e);
        }
    }

    public function adopt(int $bridgeId, string $uuid, string $snipeTag, string $correlationId): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $now = $this->now();
        $DB->beginTransaction();
        try {
            $DB->update(AssetBridge::getTable(), [
                'receipt_unit_uuid' => $uuid,
                'correlation_id'    => mb_substr($correlationId, 0, 64),
                'last_sync_at'      => $now,
                'date_mod'          => $now,
            ], ['id' => $bridgeId, 'receipt_unit_uuid' => null]);
            if ($DB->affectedRows() !== 1) {
                throw new \RuntimeException('el puente ya no está libre para adoptar');
            }
            $this->aliasIn($DB, $bridgeId, $snipeTag, $now);
            $DB->commit();
        } catch (\Throwable $e) {
            $this->rollback($DB);
            throw new \RuntimeException('asset_bridge: ' . Si4Errors::sanitize($e->getMessage()), 0, $e);
        }
    }

    public function ensureAlias(int $bridgeId, string $snipeTag): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->beginTransaction();
        try {
            $this->aliasIn($DB, $bridgeId, $snipeTag, $this->now());
            $DB->commit();
        } catch (\Throwable $e) {
            $this->rollback($DB);
            throw new \RuntimeException('asset_tag_aliases: ' . Si4Errors::sanitize($e->getMessage()), 0, $e);
        }
    }

    /** Alias vigente del tag: se crea si falta; si existe para OTRO puente ⇒ excepción (no se reasigna). */
    private function aliasIn(\DBmysql $DB, int $bridgeId, string $tag, string $now): void
    {
        foreach ($DB->request(['FROM' => AssetTagAlias::getTable(), 'WHERE' => ['asset_tag' => $tag]]) as $a) {
            if ((int) $a['asset_bridge_id'] !== $bridgeId || (string) $a['asset_tag'] !== $tag) {
                throw new \RuntimeException('el alias del tag pertenece a otro puente');
            }
            return;
        }
        $DB->insert(AssetTagAlias::getTable(), [
            'asset_bridge_id' => $bridgeId,
            'asset_tag'       => $tag,
            'is_current'      => 1,
            'valid_from'      => $now,
            'date_creation'   => $now,
        ]);
    }

    private function now(): string
    {
        return (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
    }

    private function rollback(\DBmysql $DB): void
    {
        try {
            if (!method_exists($DB, 'inTransaction') || $DB->inTransaction()) {
                $DB->rollBack();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }
}
