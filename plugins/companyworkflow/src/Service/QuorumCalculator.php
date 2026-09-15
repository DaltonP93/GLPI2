<?php

/**
 * Cálculo de quórum de una etapa de aprobación. Lógica PURA (unit-testable).
 *
 * - count:   se cumple si approvals >= value.
 * - percent: se cumple si (approvals / totalApprovers) * 100 >= value  (totalApprovers>0).
 * Fail-closed: sin aprobadores resueltos (totalApprovers = 0) NUNCA se cumple.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

final class QuorumCalculator
{
    public function isMet(string $type, int $value, int $totalApprovers, int $approvals): bool
    {
        if ($approvals < 0) {
            $approvals = 0;
        }
        if ($type === 'percent') {
            if ($totalApprovers <= 0 || $value <= 0) {
                return false;
            }
            return ($approvals * 100) >= ($value * $totalApprovers);
        }
        // 'count' por defecto.
        $needed = max(1, $value);
        if ($totalApprovers <= 0) {
            return false;
        }
        return $approvals >= $needed;
    }
}
