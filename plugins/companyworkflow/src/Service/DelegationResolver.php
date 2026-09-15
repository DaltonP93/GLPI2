<?php

/**
 * Resuelve delegaciones VIGENTES. Lógica PURA (unit-testable).
 *
 * Dada una lista de delegaciones (filas) y un contexto (usuario origen, definición, entidad y
 * momento), devuelve los usuarios que actúan como delegados válidos AHORA. Una delegación aplica
 * si: is_active, coincide users_id_from, alcance de definición/entidad compatible (0 = cualquiera)
 * y el momento está dentro de [date_start, date_end] (nulos = abierto).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

final class DelegationResolver
{
    /**
     * @param array<int,array<string,mixed>> $delegations filas de ..._delegations
     * @return array<int,int> ids de usuarios delegados (destino) vigentes
     */
    public function effectiveDelegates(
        array $delegations,
        int $fromUserId,
        int $workflowdefsId,
        int $entitiesId,
        ?string $now = null
    ): array {
        $nowTs = $now !== null ? strtotime($now) : time();
        if ($nowTs === false) {
            $nowTs = time();
        }
        $out = [];
        foreach ($delegations as $d) {
            if ((int) ($d['is_active'] ?? 0) !== 1) {
                continue;
            }
            if ((int) ($d['users_id_from'] ?? 0) !== $fromUserId) {
                continue;
            }
            $scopeDef = (int) ($d['workflowdefs_id'] ?? 0);
            if ($scopeDef !== 0 && $scopeDef !== $workflowdefsId) {
                continue;
            }
            $scopeEnt = (int) ($d['entities_id'] ?? 0);
            if ($scopeEnt !== 0 && $scopeEnt !== $entitiesId) {
                continue;
            }
            if (!$this->withinWindow($d, $nowTs)) {
                continue;
            }
            $to = (int) ($d['users_id_to'] ?? 0);
            if ($to > 0) {
                $out[$to] = true;
            }
        }
        return array_map('intval', array_keys($out));
    }

    /** @param array<string,mixed> $d */
    private function withinWindow(array $d, int $nowTs): bool
    {
        $start = $d['date_start'] ?? null;
        $end   = $d['date_end'] ?? null;
        if ($start !== null && $start !== '' && $start !== '0000-00-00 00:00:00') {
            $s = strtotime((string) $start);
            if ($s !== false && $nowTs < $s) {
                return false;
            }
        }
        if ($end !== null && $end !== '' && $end !== '0000-00-00 00:00:00') {
            $e = strtotime((string) $end);
            if ($e !== false && $nowTs > $e) {
                return false;
            }
        }
        return true;
    }
}
