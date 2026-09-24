<?php

/**
 * Validación AUTORITATIVA de referencias a maestros NATIVOS de GLPI (Group/Supplier/Budget/Document)
 * PARA la entidad de una solicitud — compartida por `RequestManager` (P2D-1) y `QuoteManager` (P2D-2).
 *
 * Extraída sin cambios de semántica de `RequestManager` (P2D-1): misma entidad o ANCESTRO recursivo
 * ACTUAL, resuelto recorriendo la cadena viva `entities_id` con el modelo nativo `Entity` (nunca la
 * caché del árbol `sons_cache`/`ancestors_cache`, que puede quedar stale tras mover una entidad ⇒
 * FAIL-OPEN). Fail-closed ante ciclo, profundidad excesiva o entidad inexistente.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class ReferenceValidator
{
    /**
     * Valida una referencia a un maestro NATIVO de GLPI (Group/Supplier/Budget/Document) contra la entidad de la
     * SOLICITUD (no contra la sesión):
     *   - `0` = sin referencia (OK).
     *   - `>0` debe EXISTIR y ser APLICABLE a la entidad de la solicitud según la semántica NATIVA de
     *     entidades/recursividad de GLPI (misma entidad, o entidad ANCESTRO con `is_recursive`).
     *
     * No alcanza con `Session::haveAccessToEntity($refEntity)`: un usuario con acceso simultáneo a A y B
     * NO debe poder adjuntar a una solicitud de A un objeto exclusivo de la rama B. No se duplican
     * maestros ni se consulta el core por SQL directo saltando sus reglas.
     */
    public function assertReferenceForEntity(string $itemtype, int $id, int $requestEntityId): void
    {
        if ($id <= 0) {
            return;
        }
        if (!class_exists($itemtype)) {
            throw new \RuntimeException("tipo de referencia inválido: {$itemtype}");
        }
        /** @var \CommonDBTM $obj */
        $obj = new $itemtype();
        if (!$obj->getFromDB($id)) {
            throw new \InvalidArgumentException("referencia inexistente: {$itemtype}#{$id}");
        }
        // Un maestro sin dimensión de entidad (no esperado para Group/Supplier/Budget) no se restringe.
        if (!isset($obj->fields['entities_id'])) {
            return;
        }
        $refEntity    = (int) $obj->fields['entities_id'];
        $refRecursive = (bool) ($obj->fields['is_recursive'] ?? false);
        if (!self::isEntityApplicable($refEntity, $refRecursive, $requestEntityId)) {
            throw new \RuntimeException("referencia no aplicable a la entidad de la solicitud: {$itemtype}#{$id}");
        }
    }

    /** Profundidad máxima del árbol de entidades al resolver aplicabilidad (guarda dura). */
    private const ENTITY_TREE_MAX_DEPTH = 1000;

    /**
     * ¿Un objeto en la entidad `$refEntity` (con recursividad `$refRecursive`) es APLICABLE a la entidad
     * `$requestEntity`, según la relación AUTORITATIVA de entidades/recursividad de GLPI?
     *   - misma entidad → siempre aplicable;
     *   - recursivo → aplicable si `$refEntity` es la RAÍZ (0) o un ANCESTRO ACTUAL de `$requestEntity`
     *     (la recursividad se hereda hacia abajo);
     *   - de otra RAMA (ni misma entidad ni ancestro recursivo actual) → NO aplicable.
     *
     * FUENTE DE VERDAD: se recorre la cadena de padres (`entities_id`) de la SOLICITUD con el modelo nativo
     * `Entity` (`getFromDB`), que lee el valor ACTUAL de la columna `entities_id`. Se decide de forma
     * AUTORITATIVA, sin usar NUNCA la caché del árbol de GLPI (`getSonsOf()`/`getAncestorsOf()`): en GLPI 11
     * ambas usan `$GLPI_CACHE` y las columnas `ancestors_cache`/`sons_cache`, que pueden quedar
     * DESACTUALIZADAS tras mover una entidad entre ramas. Una caché stale podría autorizar una referencia
     * cross-branch que ya NO existe (FAIL-OPEN); por eso la autorización se resuelve sólo por la cadena viva.
     *
     * No es SQL directo al core (usa el modelo soportado). FAIL-CLOSED ante ciclo, profundidad excesiva,
     * `entities_id` inválido o entidad inexistente. `visited` evita bucles.
     */
    private static function isEntityApplicable(int $refEntity, bool $refRecursive, int $requestEntity): bool
    {
        // Resolver de padre ACTUAL vía modelo nativo `Entity` (lee la columna `entities_id` viva, NO la
        // caché del árbol). Devuelve null ⇒ entidad inexistente o sin dimensión de árbol ⇒ fail-closed.
        $parentOf = static function (int $id): ?int {
            $ent = new \Entity();
            if (!$ent->getFromDB($id)) {
                return null;
            }
            if (!array_key_exists('entities_id', $ent->fields)) {
                return null;
            }
            return (int) $ent->fields['entities_id'];
        };
        return self::isEntityApplicableInChain($refEntity, $refRecursive, $requestEntity, $parentOf);
    }

    /**
     * Núcleo PURO y AUTORITATIVO de la aplicabilidad de entidad: dado `$parentOf` que devuelve el padre
     * ACTUAL de una entidad (o `null` si no existe / es inconsistente), decide si `$refEntity` (recursivo)
     * es la raíz o un ANCESTRO ACTUAL de `$requestEntity`. La fuente de verdad es `$parentOf` (la cadena
     * viva `entities_id`), JAMÁS una caché de árbol que pueda quedar stale tras mover una entidad. Recorrido
     * fail-closed: `visited` corta ciclos, `ENTITY_TREE_MAX_DEPTH` acota, y `null`/entidad inválida rechaza.
     * Es `public static` sólo para poder verificarlo de forma DETERMINISTA (unit tests) contra cadenas de
     * padres controladas —incluida una "movida" a otra rama— sin depender del árbol real de GLPI.
     *
     * @param callable(int):?int $parentOf
     */
    public static function isEntityApplicableInChain(int $refEntity, bool $refRecursive, int $requestEntity, callable $parentOf): bool
    {
        if ($refEntity === $requestEntity) {
            return true; // misma entidad: siempre aplicable
        }
        if (!$refRecursive) {
            return false; // no recursivo: sólo su propia entidad
        }
        $visited = [];
        $current = $requestEntity;
        for ($depth = 0; $depth < self::ENTITY_TREE_MAX_DEPTH; $depth++) {
            if ($current < 0 || isset($visited[$current])) {
                return false; // entidad inválida o ciclo → fail-closed
            }
            $visited[$current] = true;
            $parent = $parentOf($current);
            if ($parent === null) {
                return false; // entidad inexistente / sin dimensión de árbol → fail-closed
            }
            $parent = (int) $parent;
            if ($parent === $refEntity) {
                return true; // `$refEntity` es ancestro ACTUAL (o la raíz 0) de la solicitud: aplica
            }
            if ($parent < 0 || $parent === $current) {
                return false; // raíz auto-referencial / sin padre válido: refEntity no está en la cadena
            }
            $current = $parent;
        }
        return false; // profundidad máxima sin encontrar `$refEntity` → fail-closed
    }
}
