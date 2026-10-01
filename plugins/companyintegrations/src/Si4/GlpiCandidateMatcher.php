<?php

/**
 * ¿Hay en GLPI un activo que YA es esta unidad? Resolver-o-crear FAIL-CLOSED (SI4-2, ADR-0021 §3–§4). PURO.
 *
 * Entrada: la UNIÓN de candidatos del itemtype configurado encontrados por `otherserial` = tag determinista y por
 * `serial` (si la unidad lo trae), de TODAS las entidades, sin plantillas y con la papelera incluida. La BD compara
 * sin distinguir mayúsculas (colación): aquí las comparaciones son EXACTAS, así que una diferencia de mayúsculas
 * nunca se adopta.
 *
 *   NONE                  ningún candidato ⇒ crear
 *   ONE                   exactamente uno, en la entidad de la unidad, serial coincidente (o unidad sin serial) y
 *                         `otherserial` = tag (`claim` = false) o vacío (`claim` = true: vincular y reclamar el tag)
 *   AMBIGUOUS             más de un candidato ⇒ ninguno se modifica
 *   ENTITY_MISMATCH       el candidato está en otra entidad ⇒ no se adopta (ni se crea otro)
 *   SERIAL_CONFLICT       serial distinto del recibido
 *   OTHERSERIAL_CONFLICT  número de inventario no vacío y distinto del tag (nunca se pisa uno humano)
 *   DELETED               candidato en la papelera
 *
 * Todo lo que no es NONE/ONE ⇒ MANUAL_REVIEW. El modelo NO es criterio de identidad (el agente asigna el suyo).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class GlpiCandidateMatcher
{
    public const NONE                 = 'none';
    public const ONE                  = 'one';
    public const AMBIGUOUS            = 'ambiguous';
    public const ENTITY_MISMATCH      = 'entity_mismatch';
    public const SERIAL_CONFLICT      = 'serial_conflict';
    public const OTHERSERIAL_CONFLICT = 'otherserial_conflict';
    public const DELETED              = 'deleted';

    /**
     * @param array<int,array<string,mixed>> $rows candidatos (id, entities_id, serial, otherserial, is_deleted), sin duplicados
     * @return array{kind:string, id:int, claim:bool, detail:string}
     */
    public static function classify(array $rows, int $entityId, string $tag, ?string $serial): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id > 0) {
                $byId[$id] = $r;
            }
        }
        if ($byId === []) {
            return self::out(self::NONE, 0, false, '');
        }
        foreach ($byId as $id => $r) {
            if ((int) ($r['is_deleted'] ?? 0) === 1) {
                return self::out(self::DELETED, $id, false, 'candidato #' . $id . ' en la papelera');
            }
        }
        if (count($byId) > 1) {
            return self::out(self::AMBIGUOUS, 0, false, count($byId) . ' candidatos (' . implode(',', array_keys($byId)) . ')');
        }
        $id = (int) array_key_first($byId);
        $r  = $byId[$id];
        if ((int) ($r['entities_id'] ?? -1) !== $entityId) {
            return self::out(self::ENTITY_MISMATCH, $id, false, 'candidato #' . $id . ' en otra entidad');
        }
        $remoteSerial = self::str($r['serial'] ?? null);
        if ($serial !== null && $remoteSerial !== $serial) {
            return self::out(self::SERIAL_CONFLICT, $id, false, 'serial del candidato #' . $id . ' distinto del recibido');
        }
        $other = self::str($r['otherserial'] ?? null);
        if ($other === $tag) {
            return self::out(self::ONE, $id, false, '');
        }
        if ($other === '' && $serial !== null && $remoteSerial === $serial) {
            // Encontrado SÓLO por serial (p. ej. el GLPI Agent): se vincula y se reclama el número de inventario.
            return self::out(self::ONE, $id, true, '');
        }
        return self::out(self::OTHERSERIAL_CONFLICT, $id, false, 'número de inventario del candidato #' . $id . ' distinto del tag');
    }

    private static function str(mixed $v): string
    {
        return is_string($v) || is_int($v) ? (string) $v : '';
    }

    /** @return array{kind:string, id:int, claim:bool, detail:string} */
    private static function out(string $kind, int $id, bool $claim, string $detail): array
    {
        return ['kind' => $kind, 'id' => $id, 'claim' => $claim, 'detail' => $detail];
    }
}
