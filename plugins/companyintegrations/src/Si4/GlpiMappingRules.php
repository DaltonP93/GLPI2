<?php

/**
 * Regla PURA del mapeo categoría ⇒ destino GLPI (SI4-2, ADR-0021 §2): sin fila aprobada, itemtype vacío o modelo
 * negativo ⇒ bloqueado (BLOCKED_CONFIG). Que el itemtype esté SOPORTADO y el modelo EXISTA lo comprueba el gateway.
 *
 * PINNING por saga (ADR-0021 §2): el primer uso válido fija en la saga `glpi_mapping_id` + `glpi_itemtype` +
 * `glpi_model_id` + `glpi_mapping_hash` (huella de esos valores y la categoría). Desde entonces la saga usa SÓLO ese
 * destino: un cambio administrativo del mapeo afecta a unidades nuevas, nunca al retry de la misma unidad.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class GlpiMappingRules
{
    /** Columnas del pin en `si4_sagas`: se escriben JUNTAS y una sola vez (el store rechaza re-pinnear). */
    public const PIN_COLUMNS = ['glpi_mapping_id', 'glpi_itemtype', 'glpi_model_id', 'glpi_mapping_hash'];
    private const PIN_VERSION = 'si4g-map/v1';

    /**
     * @param array<int,array{id:int, glpi_itemtype:string, glpi_model_id:int}> $approvedRows filas APROBADAS para la categoría exacta
     * @return array{ok:bool, mapping_id:int, itemtype:string, model_id:int, reason:string}
     */
    public static function decide(string $category, array $approvedRows): array
    {
        if ($category === '') {
            return self::no('la unidad no trae categoría');
        }
        if ($approvedRows === []) {
            return self::no('mapping ausente: categoría sin tipo de activo GLPI aprobado (map_glpi_assettypes)');
        }
        if (count($approvedRows) > 1) {
            return self::no('mapping ambiguo: más de un tipo de activo GLPI aprobado para la categoría');
        }
        $row = $approvedRows[0];
        $id = (int) ($row['id'] ?? 0);
        $itemtype = (string) $row['glpi_itemtype'];
        $model = (int) $row['glpi_model_id'];
        if ($id <= 0) {
            return self::no('mapping inválido: fila sin id');
        }
        if (!self::validItemtype($itemtype)) {
            return self::no('mapping inválido: itemtype GLPI vacío o mal formado');
        }
        if ($model < 0) {
            return self::no('mapping inválido: modelo GLPI negativo');
        }
        return ['ok' => true, 'mapping_id' => $id, 'itemtype' => $itemtype, 'model_id' => $model, 'reason' => ''];
    }

    /** Huella del destino pinneado: detecta un pin alterado o una categoría distinta de la de la unidad. */
    public static function pinHash(int $mappingId, string $category, string $itemtype, int $modelId): string
    {
        return hash('sha256', self::PIN_VERSION . "\n" . $mappingId . "\n" . $category . "\n" . $itemtype . "\n" . $modelId);
    }

    /**
     * Destino PINNEADO de la saga. null = la saga todavía no fijó su destino (primer uso de SI4-2).
     *
     * @param array<string,mixed> $saga
     * @return array{ok:bool, mapping_id:int, itemtype:string, model_id:int, reason:string}|null
     */
    public static function pinned(array $saga, string $category): ?array
    {
        $hash = (string) ($saga['glpi_mapping_hash'] ?? '');
        if ($hash === '') {
            return null;
        }
        $id = (int) ($saga['glpi_mapping_id'] ?? 0);
        $itemtype = (string) ($saga['glpi_itemtype'] ?? '');
        $model = $saga['glpi_model_id'] ?? null;
        if ($id <= 0 || !self::validItemtype($itemtype) || $model === null || (int) $model < 0
            || !hash_equals($hash, self::pinHash($id, $category, $itemtype, (int) $model))) {
            return self::no('destino GLPI pinneado inconsistente (huella distinta o columnas alteradas)');
        }
        return ['ok' => true, 'mapping_id' => $id, 'itemtype' => $itemtype, 'model_id' => (int) $model, 'reason' => ''];
    }

    /**
     * ¿`$set` (transición de saga) escribe el pin? Si toca alguna columna del pin debe traer las cuatro, no vacías.
     *
     * @param array<string,mixed> $set
     * @throws \InvalidArgumentException pin incompleto
     */
    public static function isPinWrite(array $set): bool
    {
        if (array_intersect(self::PIN_COLUMNS, array_keys($set)) === []) {
            return false;
        }
        foreach (self::PIN_COLUMNS as $c) {
            if (!array_key_exists($c, $set) || $set[$c] === null || $set[$c] === '') {
                throw new \InvalidArgumentException('el pin del destino GLPI se escribe completo (' . implode(', ', self::PIN_COLUMNS) . ')');
            }
        }
        return true;
    }

    private static function validItemtype(string $itemtype): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_\\\\]{0,99}$/', $itemtype) === 1;
    }

    /** @return array{ok:bool, mapping_id:int, itemtype:string, model_id:int, reason:string} */
    private static function no(string $reason): array
    {
        return ['ok' => false, 'mapping_id' => 0, 'itemtype' => '', 'model_id' => 0, 'reason' => $reason];
    }
}
