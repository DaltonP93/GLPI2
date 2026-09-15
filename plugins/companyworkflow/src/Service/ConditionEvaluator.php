<?php

/**
 * Evaluador de condiciones DECLARATIVO y acotado (sin `eval`).
 *
 * Lógica PURA (no depende del core de GLPI) → cubierta por tests unitarios.
 * Formato de condición (JSON string o array). Nodo hoja:
 *   {"field":"amount","op":"gte","value":1000000}
 * Nodo compuesto:
 *   {"all":[ <nodo>, <nodo> ]}   (AND)   |   {"any":[ <nodo>, ... ]}   (OR)
 * Condición vacía/nula → true (no restringe).
 * Operadores soportados: eq, neq, gt, gte, lt, lte, in, nin.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

final class ConditionEvaluator
{
    /**
     * @param array<string,mixed>|string|null $condition
     * @param array<string,mixed>             $context
     */
    public function evaluate($condition, array $context): bool
    {
        if ($condition === null || $condition === '' || $condition === '{}' || $condition === []) {
            return true;
        }
        if (is_string($condition)) {
            $decoded = json_decode($condition, true);
            if (!is_array($decoded)) {
                // Condición ilegible → fail-closed (no autoriza).
                return false;
            }
            $condition = $decoded;
        }
        return $this->evalNode($condition, $context);
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $ctx */
    private function evalNode(array $node, array $ctx): bool
    {
        if (isset($node['all']) && is_array($node['all'])) {
            foreach ($node['all'] as $child) {
                if (!is_array($child) || !$this->evalNode($child, $ctx)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($node['any']) && is_array($node['any'])) {
            foreach ($node['any'] as $child) {
                if (is_array($child) && $this->evalNode($child, $ctx)) {
                    return true;
                }
            }
            return false;
        }
        // Nodo hoja.
        $field = (string) ($node['field'] ?? '');
        $op    = (string) ($node['op'] ?? '');
        if ($field === '' || $op === '') {
            return false;
        }
        $left  = $ctx[$field] ?? null;
        $right = $node['value'] ?? null;
        return $this->compare($left, $op, $right);
    }

    /** @param mixed $left @param mixed $right */
    private function compare($left, string $op, $right): bool
    {
        switch ($op) {
            case 'eq':  return $left == $right;
            case 'neq': return $left != $right;
            case 'gt':  return is_numeric($left) && is_numeric($right) && $left > $right;
            case 'gte': return is_numeric($left) && is_numeric($right) && $left >= $right;
            case 'lt':  return is_numeric($left) && is_numeric($right) && $left < $right;
            case 'lte': return is_numeric($left) && is_numeric($right) && $left <= $right;
            case 'in':  return is_array($right) && in_array($left, $right, false);
            case 'nin': return is_array($right) && !in_array($left, $right, false);
            default:    return false; // operador desconocido → fail-closed
        }
    }
}
