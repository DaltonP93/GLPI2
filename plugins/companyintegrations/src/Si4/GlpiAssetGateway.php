<?php

/**
 * Puerto de SI4-2 hacia GLPI (ADR-0021): activo del itemtype configurado, su Infocom y el proveedor. La
 * implementación de producción (`CoreGlpiAssetGateway`) usa SÓLO la API soportada de GLPI 11.0.8 (`CommonDBTM::find/
 * getFromDB/add/update/can`, `Infocom::getFromDBforDevice`); nunca SQL contra tablas del core.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

interface GlpiAssetGateway
{
    /** null = itemtype soportado y modelo (si > 0) existente; si no, el motivo (⇒ BLOCKED_CONFIG). */
    public function targetProblem(string $itemtype, int $modelId): ?string;

    /**
     * Candidatos por `otherserial` = `$tag` y por `serial` = `$serial` (si no es null), en TODAS las entidades, sin
     * plantillas, incluida la papelera. Sin duplicados.
     *
     * @return array<int,array{id:int, entities_id:int, serial:?string, otherserial:?string, is_deleted:int, is_dynamic:int}>
     */
    public function findCandidates(string $itemtype, string $tag, ?string $serial): array;

    /** @return array{id:int, entities_id:int, serial:?string, otherserial:?string, is_deleted:int, is_dynamic:int}|null */
    public function get(string $itemtype, int $id): ?array;

    /** ¿El usuario de la sesión puede CREAR este itemtype en la entidad? (derecho + entidad) */
    public function canCreate(string $itemtype, int $entityId): bool;

    /** ¿El usuario de la sesión puede ACTUALIZAR este activo? */
    public function canUpdate(string $itemtype, int $id): bool;

    /**
     * Alta nativa (`add()`), sin Infocom automático. 0 = rechazada (reglas/unicidad/hook).
     *
     * @param array{entities_id:int, serial:?string, otherserial:string, name:string, model_id:int} $fields
     */
    public function create(string $itemtype, array $fields): int;

    /** Escribe SÓLO el número de inventario (`otherserial`) de un activo existente. */
    public function setInventoryNumber(string $itemtype, int $id, string $otherserial): bool;

    /** ¿El proveedor existe y no está en la papelera? */
    public function supplierUsable(int $supplierId): bool;

    /** @return array<string,mixed>|null Infocom del activo (a lo sumo uno: UNIQUE(itemtype, items_id)) */
    public function getInfocom(string $itemtype, int $id): ?array;

    /** ¿Puede crear (`$infocomId` = 0) o actualizar el Infocom de este activo? */
    public function canInfocom(string $itemtype, int $id, int $infocomId): bool;

    /** @param array<string,int|string> $fields @return int id del Infocom (0 = rechazado) */
    public function addInfocom(string $itemtype, int $id, array $fields): int;

    /** @param array<string,int|string> $fields */
    public function updateInfocom(int $infocomId, array $fields): bool;
}
