<?php

/**
 * Resuelve la transición válida desde un estado dada una acción. Lógica PURA (unit-testable).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

final class TransitionResolver
{
    /**
     * @param array<int,array<string,mixed>> $transitions filas de ..._transitions
     * @return array<string,mixed>|null  la transición coincidente o null si no existe
     */
    public function resolve(array $transitions, int $fromStateId, string $action): ?array
    {
        foreach ($transitions as $t) {
            if ((int) ($t['from_statedefs_id'] ?? -1) === $fromStateId
                && (string) ($t['action'] ?? '') === $action) {
                return $t;
            }
        }
        return null;
    }

    /**
     * Acciones disponibles desde un estado (para bandeja/action bar).
     * @param array<int,array<string,mixed>> $transitions
     * @return array<int,string>
     */
    public function actionsFrom(array $transitions, int $fromStateId): array
    {
        $actions = [];
        foreach ($transitions as $t) {
            if ((int) ($t['from_statedefs_id'] ?? -1) === $fromStateId) {
                $actions[] = (string) ($t['action'] ?? '');
            }
        }
        return array_values(array_unique(array_filter($actions)));
    }
}
