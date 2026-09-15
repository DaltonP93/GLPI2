<?php

/**
 * ¿Puede un USUARIO (no la sesión actual) actuar en una entidad? Multi-entidad estricta.
 *
 * Un usuario puede actuar en la entidad E si tiene un `Profile_User` con:
 *   - entities_id == E (asignación directa), o
 *   - entities_id == un ANCESTRO de E con is_recursive = 1.
 * Se usa para EXCLUIR del conjunto efectivo de aprobadores (y del denominador del quórum) a
 * quienes no pueden actuar en la entidad de la instancia.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

use Entity;

final class EntityAccess
{
    /** @var array<int,array<int,int>> cache: entityId → cadena de ancestros (incluida ella y 0) */
    private array $chainCache = [];

    public function userCanActInEntity(int $usersId, int $entitiesId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($usersId <= 0 || !isset($DB)) {
            return false;
        }
        $chain = $this->entityChain($entitiesId);

        foreach ($DB->request([
            'SELECT' => ['entities_id', 'is_recursive'],
            'FROM'   => 'glpi_profiles_users',
            'WHERE'  => ['users_id' => $usersId],
        ]) as $row) {
            $e = (int) $row['entities_id'];
            $r = (int) $row['is_recursive'];
            if ($e === $entitiesId) {
                return true;
            }
            if ($r === 1 && in_array($e, $chain, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filtra una lista de usuarios dejando sólo los que pueden actuar en la entidad.
     * @param array<int,int> $userIds
     * @return array<int,int>
     */
    public function filterActable(array $userIds, int $entitiesId): array
    {
        $out = [];
        foreach ($userIds as $u) {
            if ($this->userCanActInEntity((int) $u, $entitiesId)) {
                $out[] = (int) $u;
            }
        }
        return array_values(array_unique($out));
    }

    /** Cadena de entidad: [E, padre, ..., 0]. Robusta ante ciclos. @return array<int,int> */
    private function entityChain(int $entitiesId): array
    {
        if (isset($this->chainCache[$entitiesId])) {
            return $this->chainCache[$entitiesId];
        }
        $chain = [];
        $seen = [];
        $cur = $entitiesId;
        $guard = 0;
        while ($guard++ < 200) {
            $chain[] = $cur;
            $seen[$cur] = true;
            if ($cur === 0) {
                break;
            }
            $e = new Entity();
            if (!$e->getFromDB($cur)) {
                break;
            }
            $parent = (int) ($e->fields['entities_id'] ?? 0);
            if ($parent < 0 || isset($seen[$parent])) {
                if (!in_array(0, $chain, true)) {
                    $chain[] = 0;
                }
                break;
            }
            $cur = $parent;
        }
        return $this->chainCache[$entitiesId] = $chain;
    }
}
