<?php

/**
 * `GlpiAssetGateway` de PRODUCCIÓN: SÓLO API soportada de GLPI 11.0.8 (ADR-0021, hechos 1–11).
 *
 * - Búsqueda: `CommonDBTM::find()` por `otherserial` y por `serial` (consulta soportada, sin restricción de entidad a
 *   propósito: un candidato de otra entidad se DETECTA para no adoptarlo ni duplicarlo).
 * - Alta: `CommonDBTM::add($input, ['disable_infocom_creation' => true])` (hooks, reglas, unicidad e historial nativos).
 * - Número de inventario: `CommonDBTM::update()` (si el activo es dinámico, GLPI crea el Lockedfield).
 * - Infocom: `Infocom::getFromDBforDevice()` / `add()` / `update()`; derechos con `can()`.
 *
 * Itemtypes SOPORTADOS (capacidad técnica verificada, no decisión de negocio): tienen `serial`, `otherserial`,
 * `entities_id`, FK de modelo y admiten Infocom. El itemtype de cada unidad lo decide el mapeo aprobado.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class CoreGlpiAssetGateway implements GlpiAssetGateway
{
    public const SUPPORTED_ITEMTYPES = ['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Phone', 'Printer'];

    public function targetProblem(string $itemtype, int $modelId): ?string
    {
        if (!in_array($itemtype, self::SUPPORTED_ITEMTYPES, true) || !class_exists($itemtype) || !is_subclass_of($itemtype, \CommonDBTM::class)) {
            return 'itemtype no soportado por SI4-2: ' . $itemtype;
        }
        if (!\Infocom::canApplyOn($itemtype)) {
            return 'el itemtype no admite Infocom: ' . $itemtype;
        }
        $item = new $itemtype();
        foreach (['serial', 'otherserial', 'entities_id', 'is_deleted', 'is_template'] as $f) {
            if (!$item->isField($f)) {
                return 'el itemtype no tiene el campo ' . $f;
            }
        }
        if ($modelId < 0) {
            return 'modelo GLPI inválido';
        }
        if ($modelId > 0) {
            $modelClass = $item->getModelClass();
            if ($modelClass === null) {
                return 'el itemtype no tiene modelos';
            }
            $model = new $modelClass();
            if (!$model->getFromDB($modelId)) {
                return 'modelo GLPI inexistente (' . $modelClass . ' #' . $modelId . ')';
            }
        }
        return null;
    }

    public function findCandidates(string $itemtype, string $tag, ?string $serial): array
    {
        $item = new $itemtype();
        $rows = $item->find(['otherserial' => $tag, 'is_template' => 0]);
        if ($serial !== null) {
            $rows += $item->find(['serial' => $serial, 'is_template' => 0]);
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::row($r);
        }
        return $out;
    }

    public function get(string $itemtype, int $id): ?array
    {
        $item = new $itemtype();
        return $id > 0 && $item->getFromDB($id) ? self::row($item->fields) : null;
    }

    public function canCreate(string $itemtype, int $entityId): bool
    {
        $item = new $itemtype();
        $input = ['entities_id' => $entityId, 'is_recursive' => 0];
        return $item->can(-1, CREATE, $input);
    }

    public function canUpdate(string $itemtype, int $id): bool
    {
        return (new $itemtype())->can($id, UPDATE);
    }

    public function create(string $itemtype, array $fields): int
    {
        $item = new $itemtype();
        $input = [
            'entities_id'  => $fields['entities_id'],
            'is_recursive' => 0,
            'otherserial'  => $fields['otherserial'],
            'name'         => $fields['name'],
        ];
        if ($fields['serial'] !== null) {
            $input['serial'] = $fields['serial'];
        }
        if ($fields['model_id'] > 0 && ($fk = $item->getModelForeignKeyField()) !== null) {
            $input[$fk] = $fields['model_id'];
        }
        $id = $item->add($input, ['disable_infocom_creation' => true]);
        return is_int($id) && $id > 0 ? $id : 0;
    }

    public function setInventoryNumber(string $itemtype, int $id, string $otherserial): bool
    {
        return (bool) (new $itemtype())->update(['id' => $id, 'otherserial' => $otherserial]);
    }

    public function supplierUsable(int $supplierId): bool
    {
        $s = new \Supplier();
        return $supplierId > 0 && $s->getFromDB($supplierId) && (int) ($s->fields['is_deleted'] ?? 0) === 0;
    }

    public function getInfocom(string $itemtype, int $id): ?array
    {
        $ic = new \Infocom();
        return $ic->getFromDBforDevice($itemtype, $id) ? $ic->fields : null;
    }

    public function canInfocom(string $itemtype, int $id, int $infocomId): bool
    {
        $ic = new \Infocom();
        if ($infocomId > 0) {
            return $ic->can($infocomId, UPDATE);
        }
        $input = ['itemtype' => $itemtype, 'items_id' => $id];
        return $ic->can(-1, CREATE, $input);
    }

    public function addInfocom(string $itemtype, int $id, array $fields): int
    {
        $icId = (new \Infocom())->add(['itemtype' => $itemtype, 'items_id' => $id] + $fields);
        return is_int($icId) && $icId > 0 ? $icId : 0;
    }

    public function updateInfocom(int $infocomId, array $fields): bool
    {
        return (bool) (new \Infocom())->update(['id' => $infocomId] + $fields);
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int, entities_id:int, serial:?string, otherserial:?string, is_deleted:int, is_dynamic:int}
     */
    private static function row(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'entities_id' => (int) ($r['entities_id'] ?? -1),
            'serial'      => isset($r['serial']) ? (string) $r['serial'] : null,
            'otherserial' => isset($r['otherserial']) ? (string) $r['otherserial'] : null,
            'is_deleted'  => (int) ($r['is_deleted'] ?? 0),
            'is_dynamic'  => (int) ($r['is_dynamic'] ?? 0),
        ];
    }
}
