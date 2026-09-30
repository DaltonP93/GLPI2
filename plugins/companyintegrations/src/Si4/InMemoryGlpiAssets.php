<?php

/**
 * DOBLE DE PRUEBA de `GlpiAssetGateway` (tests de contrato sin GLPI). Emula lo verificado en GLPI 11.0.8: búsqueda por
 * `otherserial`/`serial` SIN distinguir mayúsculas (colación de la BD), sin UNIQUE en esos campos, un Infocom por
 * activo (UNIQUE(itemtype, items_id)), derechos por itemtype/entidad y reglas de alta que pueden alterar la entrada.
 * La implementación real (`CoreGlpiAssetGateway`) se prueba en el selftest de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class InMemoryGlpiAssets implements GlpiAssetGateway
{
    /** @var array<string,array<int,array<string,mixed>>> itemtype ⇒ id ⇒ fila */
    public array $items = [];
    /** @var array<int,array<string,mixed>> */
    public array $infocoms = [];
    /** @var array<int,bool> id ⇒ en papelera */
    public array $suppliers = [3 => false];
    /** @var array<string,array<int,bool>> itemtype ⇒ modelos existentes */
    public array $models = ['Computer' => [41 => true], 'Monitor' => [42 => true]];
    /** @var array<int,bool> entidades a las que accede el usuario de la sesión */
    public array $entityAccess = [1 => true, 2 => true];
    /** @var array<string,bool> derechos: "<itemtype>:create" / "<itemtype>:update" / "infocom:create" / "infocom:update" */
    public array $denied = [];
    /** @var callable(array<string,mixed>):array<string,mixed>|null regla de alta (emula RuleAsset/hooks que alteran la entrada) */
    public $addRule = null;
    public bool $refuseAdd = false;
    public int $addCalls = 0;
    public int $infocomAddCalls = 0;
    public int $inventoryNumberWrites = 0;
    private int $seq = 100;
    private int $icSeq = 500;

    public function targetProblem(string $itemtype, int $modelId): ?string
    {
        if (!in_array($itemtype, CoreGlpiAssetGateway::SUPPORTED_ITEMTYPES, true)) {
            return 'itemtype no soportado por SI4-2: ' . $itemtype;
        }
        if ($modelId < 0 || ($modelId > 0 && !isset($this->models[$itemtype][$modelId]))) {
            return 'modelo GLPI inexistente (' . $itemtype . 'Model #' . $modelId . ')';
        }
        return null;
    }

    public function findCandidates(string $itemtype, string $tag, ?string $serial): array
    {
        $out = [];
        foreach ($this->items[$itemtype] ?? [] as $r) {
            if ((int) $r['is_template'] === 1) {
                continue;
            }
            if (strcasecmp(rtrim((string) $r['otherserial']), rtrim($tag)) === 0
                || ($serial !== null && strcasecmp(rtrim((string) $r['serial']), rtrim($serial)) === 0)) {
                $out[] = self::row($r);
            }
        }
        return $out;
    }

    public function get(string $itemtype, int $id): ?array
    {
        return isset($this->items[$itemtype][$id]) ? self::row($this->items[$itemtype][$id]) : null;
    }

    public function canCreate(string $itemtype, int $entityId): bool
    {
        return empty($this->denied[$itemtype . ':create']) && !empty($this->entityAccess[$entityId]);
    }

    public function canUpdate(string $itemtype, int $id): bool
    {
        $r = $this->items[$itemtype][$id] ?? null;
        return $r !== null && empty($this->denied[$itemtype . ':update']) && !empty($this->entityAccess[(int) $r['entities_id']]);
    }

    public function create(string $itemtype, array $fields): int
    {
        $this->addCalls++;
        if ($this->refuseAdd) {
            return 0;
        }
        $row = [
            'entities_id' => $fields['entities_id'], 'serial' => $fields['serial'], 'otherserial' => $fields['otherserial'],
            'name' => $fields['name'], 'model_id' => $fields['model_id'], 'is_deleted' => 0, 'is_template' => 0, 'is_dynamic' => 0,
        ];
        if ($this->addRule !== null) {
            $row = ($this->addRule)($row);
        }
        $id = ++$this->seq;
        $this->items[$itemtype][$id] = ['id' => $id] + $row;
        return $id;
    }

    public function setInventoryNumber(string $itemtype, int $id, string $otherserial): bool
    {
        if (!isset($this->items[$itemtype][$id])) {
            return false;
        }
        $this->inventoryNumberWrites++;
        $this->items[$itemtype][$id]['otherserial'] = $otherserial;
        if ((int) $this->items[$itemtype][$id]['is_dynamic'] === 1) {
            $this->items[$itemtype][$id]['locked'][] = 'otherserial'; // Lockedfield nativo
        }
        return true;
    }

    public function supplierUsable(int $supplierId): bool
    {
        return array_key_exists($supplierId, $this->suppliers) && $this->suppliers[$supplierId] === false;
    }

    public function getInfocom(string $itemtype, int $id): ?array
    {
        foreach ($this->infocoms as $ic) {
            if ($ic['itemtype'] === $itemtype && (int) $ic['items_id'] === $id) {
                return $ic;
            }
        }
        return null;
    }

    public function canInfocom(string $itemtype, int $id, int $infocomId): bool
    {
        return empty($this->denied[$infocomId > 0 ? 'infocom:update' : 'infocom:create']);
    }

    public function addInfocom(string $itemtype, int $id, array $fields): int
    {
        $this->infocomAddCalls++;
        if ($this->getInfocom($itemtype, $id) !== null) {
            return 0; // Infocom::prepareInputForAdd rechaza un segundo Infocom (UNIQUE itemtype, items_id)
        }
        $icId = ++$this->icSeq;
        $this->infocoms[$icId] = ['id' => $icId, 'itemtype' => $itemtype, 'items_id' => $id] + self::stored($fields)
            + ['value' => '0.0000', 'suppliers_id' => 0, 'order_number' => null, 'delivery_date' => null, 'buy_date' => null];
        return $icId;
    }

    public function updateInfocom(int $infocomId, array $fields): bool
    {
        if (!isset($this->infocoms[$infocomId])) {
            return false;
        }
        $this->infocoms[$infocomId] = self::stored($fields) + $this->infocoms[$infocomId];
        return true;
    }

    /** Activo preexistente (p. ej. creado por el GLPI Agent). */
    public function seed(string $itemtype, int $entityId, ?string $serial, ?string $otherserial = null, bool $dynamic = true, bool $deleted = false): int
    {
        $id = ++$this->seq;
        $this->items[$itemtype][$id] = ['id' => $id, 'entities_id' => $entityId, 'serial' => $serial, 'otherserial' => $otherserial,
            'name' => 'agent-' . $id, 'model_id' => 0, 'is_deleted' => $deleted ? 1 : 0, 'is_template' => 0, 'is_dynamic' => $dynamic ? 1 : 0];
        return $id;
    }

    /** Cuenta activos (no plantilla) de un itemtype cuyo serial u otherserial coincide. */
    public function count(string $itemtype, ?string $serial = null, ?string $otherserial = null): int
    {
        $n = 0;
        foreach ($this->items[$itemtype] ?? [] as $r) {
            if (($serial !== null && $r['serial'] === $serial) || ($otherserial !== null && $r['otherserial'] === $otherserial)) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * GLPI guarda `value` como decimal(20,4) y devuelve string con 4 decimales.
     *
     * @param array<string,int|string> $fields
     * @return array<string,int|string>
     */
    private static function stored(array $fields): array
    {
        if (isset($fields['value'])) {
            $fields['value'] = InfocomPolicy::toGlpiValue((string) $fields['value']) ?? '0.0000';
        }
        return $fields;
    }

    /**
     * @param array<string,mixed> $r
     * @return array{id:int, entities_id:int, serial:?string, otherserial:?string, is_deleted:int, is_dynamic:int}
     */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['id'], 'entities_id' => (int) $r['entities_id'], 'serial' => $r['serial'], 'otherserial' => $r['otherserial'],
            'is_deleted' => (int) $r['is_deleted'], 'is_dynamic' => (int) $r['is_dynamic']];
    }
}
