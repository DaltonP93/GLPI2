<?php

/**
 * Persistencia de `asset_bridge` (+ alias vigente del tag) para SI4-2 (ADR-0021 §8). Tablas PROPIAS del plugin.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

interface BridgeStore
{
    /**
     * Filas que coinciden con CUALQUIERA de las identidades (sin duplicados).
     *
     * @return array<int,array<string,mixed>>
     */
    public function findRelated(string $uuid, int $snipeAssetId, string $snipeTag, string $itemtype, int $itemsId): array;

    /**
     * Inserta el puente y su alias vigente en UNA transacción.
     *
     * @param array{receipt_unit_uuid:string, snipe_asset_id:int, snipe_asset_tag:string, glpi_itemtype:string, glpi_items_id:int, glpi_entity_id:int, serial:?string, correlation_id:string} $row
     * @throws \RuntimeException violación de unicidad (otra unidad ganó la carrera) o alias del tag en otro puente
     */
    public function insert(array $row): int;

    /**
     * Completa `receipt_unit_uuid` de un puente SI-1 (sólo si sigue NULL) y asegura el alias vigente.
     *
     * @throws \RuntimeException la fila ya no está libre o el alias apunta a otro puente
     */
    public function adopt(int $bridgeId, string $uuid, string $snipeTag, string $correlationId): void;

    /** Asegura el alias vigente del tag para un puente existente (idempotente). @throws \RuntimeException alias ajeno */
    public function ensureAlias(int $bridgeId, string $snipeTag): void;
}
