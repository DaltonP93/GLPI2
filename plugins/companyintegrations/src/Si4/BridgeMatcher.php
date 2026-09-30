<?php

/**
 * Upsert IDEMPOTENTE de `asset_bridge` 1:1 (SI4-2, ADR-0021 §8). PURO.
 *
 * Entrada: las filas de `asset_bridge` que coinciden con CUALQUIERA de las identidades de la unidad (receipt_unit_uuid,
 * snipe_asset_id, snipe_asset_tag, (glpi_itemtype, glpi_items_id)).
 *
 *   NONE      ninguna fila ⇒ insertar
 *   EXACT     una fila con TODAS las identidades y la misma unidad ⇒ ya hecho (crash tras insertar)
 *   ADOPT     una fila SI-1 (sin receipt_unit_uuid) con TODAS las demás identidades iguales ⇒ completar el uuid
 *   CONFLICT  coincidencia parcial, otra unidad, otra entidad o más de una fila ⇒ MANUAL_REVIEW (nunca se reasigna)
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class BridgeMatcher
{
    public const NONE     = 'none';
    public const EXACT    = 'exact';
    public const ADOPT    = 'adopt';
    public const CONFLICT = 'conflict';

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array{receipt_unit_uuid:string, snipe_asset_id:int, snipe_asset_tag:string, glpi_itemtype:string, glpi_items_id:int, glpi_entity_id:int} $want
     * @return array{kind:string, id:int, detail:string}
     */
    public static function classify(array $rows, array $want): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) ($r['id'] ?? 0)] = $r;
        }
        unset($byId[0]);
        if ($byId === []) {
            return ['kind' => self::NONE, 'id' => 0, 'detail' => ''];
        }
        if (count($byId) > 1) {
            return ['kind' => self::CONFLICT, 'id' => 0, 'detail' => 'identidades repartidas en ' . count($byId) . ' puentes'];
        }
        $id = (int) array_key_first($byId);
        $r  = $byId[$id];
        $same = (int) ($r['snipe_asset_id'] ?? 0) === $want['snipe_asset_id']
            && (string) ($r['snipe_asset_tag'] ?? '') === $want['snipe_asset_tag']
            && (string) ($r['glpi_itemtype'] ?? '') === $want['glpi_itemtype']
            && (int) ($r['glpi_items_id'] ?? 0) === $want['glpi_items_id'];
        if (!$same) {
            return ['kind' => self::CONFLICT, 'id' => $id, 'detail' => 'el puente #' . $id . ' une otras identidades'];
        }
        if ((int) ($r['glpi_entity_id'] ?? -1) !== $want['glpi_entity_id']) {
            return ['kind' => self::CONFLICT, 'id' => $id, 'detail' => 'el puente #' . $id . ' está en otra entidad'];
        }
        $uuid = $r['receipt_unit_uuid'] ?? null;
        if ($uuid === $want['receipt_unit_uuid']) {
            return ['kind' => self::EXACT, 'id' => $id, 'detail' => ''];
        }
        if ($uuid === null || $uuid === '') {
            return ['kind' => self::ADOPT, 'id' => $id, 'detail' => ''];
        }
        return ['kind' => self::CONFLICT, 'id' => $id, 'detail' => 'el puente #' . $id . ' pertenece a otra unidad'];
    }
}
