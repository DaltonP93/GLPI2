<?php

/**
 * Validación PURA de una especificación de definición de workflow (sin GLPI, unit-testable).
 *
 * Se ejecuta ANTES de cualquier escritura → una spec inválida se rechaza sin escrituras parciales.
 * Reglas: code no vacío; exactamente un estado inicial; códigos de estado únicos; kinds válidos;
 * toda transición referencia estados existentes; action no vacía; quorum válido; approver kinds
 * válidos.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

final class DefinitionSpecValidator
{
    private const VALID_KINDS = ['initial', 'intermediate', 'final'];
    private const VALID_QUORUM = ['count', 'percent'];
    private const VALID_APPROVER = ['group', 'profile', 'entity_manager', 'user'];

    /**
     * @param array<string,mixed> $spec
     * @return array<int,string> lista de errores (vacía = válida)
     */
    public function validate(array $spec): array
    {
        $errors = [];

        $code = (string) ($spec['code'] ?? '');
        if (trim($code) === '') {
            $errors[] = 'code requerido y no vacío';
        } elseif (strlen($code) > 100) {
            $errors[] = 'code excede 100 caracteres';
        }

        $states = $spec['states'] ?? null;
        if (!is_array($states) || $states === []) {
            $errors[] = 'states requerido y no vacío';
            $states = [];
        }

        $stateCodes = [];
        $initialCount = 0;
        foreach ($states as $i => $st) {
            if (!is_array($st)) {
                $errors[] = "state #{$i} inválido (no es objeto)";
                continue;
            }
            $sc = (string) ($st['code'] ?? '');
            if (trim($sc) === '') {
                $errors[] = "state #{$i} sin code";
            } elseif (strlen($sc) > 100) {
                $errors[] = "state '{$sc}' excede 100 caracteres";
            } else {
                if (isset($stateCodes[$sc])) {
                    $errors[] = "state code duplicado: '{$sc}'";
                }
                $stateCodes[$sc] = true;
            }
            $kind = (string) ($st['kind'] ?? 'intermediate');
            if (!in_array($kind, self::VALID_KINDS, true)) {
                $errors[] = "state '{$sc}' kind inválido: '{$kind}'";
            }
            if ($kind === 'initial') {
                $initialCount++;
            }
        }
        if ($states !== [] && $initialCount !== 1) {
            $errors[] = "debe haber exactamente un estado inicial (hay {$initialCount})";
        }

        $transitions = $spec['transitions'] ?? [];
        if (!is_array($transitions)) {
            $errors[] = 'transitions debe ser una lista';
            $transitions = [];
        }
        foreach ($transitions as $i => $tr) {
            if (!is_array($tr)) {
                $errors[] = "transition #{$i} inválida (no es objeto)";
                continue;
            }
            $from = (string) ($tr['from'] ?? '');
            $to   = (string) ($tr['to'] ?? '');
            $action = (string) ($tr['action'] ?? '');
            if (trim($action) === '') {
                $errors[] = "transition #{$i} sin action";
            }
            if ($from === '' || !isset($stateCodes[$from])) {
                $errors[] = "transition #{$i} 'from' inexistente: '{$from}'";
            }
            if ($to === '' || !isset($stateCodes[$to])) {
                $errors[] = "transition #{$i} 'to' inexistente: '{$to}'";
            }
            foreach (($tr['steps'] ?? []) as $j => $stp) {
                if (!is_array($stp)) {
                    $errors[] = "transition #{$i} step #{$j} inválido";
                    continue;
                }
                $qt = (string) ($stp['quorum_type'] ?? 'count');
                if (!in_array($qt, self::VALID_QUORUM, true)) {
                    $errors[] = "transition #{$i} step #{$j} quorum_type inválido: '{$qt}'";
                }
                $qv = (int) ($stp['quorum_value'] ?? 0);
                if ($qv <= 0) {
                    $errors[] = "transition #{$i} step #{$j} quorum_value debe ser > 0";
                }
                if ($qt === 'percent' && $qv > 100) {
                    $errors[] = "transition #{$i} step #{$j} quorum_value percent > 100";
                }
                $ak = (string) ($stp['approver_kind'] ?? 'group');
                if (!in_array($ak, self::VALID_APPROVER, true)) {
                    $errors[] = "transition #{$i} step #{$j} approver_kind inválido: '{$ak}'";
                }
                $ref = (int) ($stp['approver_ref'] ?? 0);
                if (in_array($ak, ['group', 'profile', 'user'], true) && $ref <= 0) {
                    $errors[] = "transition #{$i} step #{$j} approver_ref requerido para kind '{$ak}'";
                }
            }
        }

        return $errors;
    }
}
