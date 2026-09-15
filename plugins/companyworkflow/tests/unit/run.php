<?php

/**
 * Tests UNITARIOS puros de companyworkflow (sin bootstrap de GLPI).
 *
 * Ejercitan la lógica que NO depende del core: evaluador de condiciones, cálculo de quórum,
 * resolución de transición y ventana de delegación. Se ejecuta en el job estático de CI:
 *   php plugins/companyworkflow/tests/unit/run.php
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

$svc = dirname(__DIR__, 2) . '/src/Service/';
require $svc . 'ConditionEvaluator.php';
require $svc . 'QuorumCalculator.php';
require $svc . 'TransitionResolver.php';
require $svc . 'DelegationResolver.php';

use GlpiPlugin\Companyworkflow\Service\ConditionEvaluator;
use GlpiPlugin\Companyworkflow\Service\DelegationResolver;
use GlpiPlugin\Companyworkflow\Service\QuorumCalculator;
use GlpiPlugin\Companyworkflow\Service\TransitionResolver;

$fail = 0;
$total = 0;
function ok(string $label, bool $cond): void
{
    global $fail, $total;
    $total++;
    echo ($cond ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m ") . $label . "\n";
    if (!$cond) {
        $fail++;
    }
}

echo "== ConditionEvaluator ==\n";
$ce = new ConditionEvaluator();
ok('condición nula → true', $ce->evaluate(null, []) === true);
ok('condición vacía {} → true', $ce->evaluate('{}', []) === true);
ok('gte cumple (5000>=1000)', $ce->evaluate(['field' => 'amount', 'op' => 'gte', 'value' => 1000], ['amount' => 5000]) === true);
ok('gte no cumple (10>=1000)', $ce->evaluate(['field' => 'amount', 'op' => 'gte', 'value' => 1000], ['amount' => 10]) === false);
ok('lt cumple (10<1000)', $ce->evaluate(['field' => 'amount', 'op' => 'lt', 'value' => 1000], ['amount' => 10]) === true);
ok('eq cumple', $ce->evaluate(['field' => 'cat', 'op' => 'eq', 'value' => 'IT'], ['cat' => 'IT']) === true);
ok('neq cumple', $ce->evaluate(['field' => 'cat', 'op' => 'neq', 'value' => 'IT'], ['cat' => 'HR']) === true);
ok('in cumple', $ce->evaluate(['field' => 'cat', 'op' => 'in', 'value' => ['IT', 'HR']], ['cat' => 'HR']) === true);
ok('nin cumple', $ce->evaluate(['field' => 'cat', 'op' => 'nin', 'value' => ['IT', 'HR']], ['cat' => 'FIN']) === true);
ok('all (AND) cumple', $ce->evaluate(['all' => [
    ['field' => 'amount', 'op' => 'gte', 'value' => 1000],
    ['field' => 'cat', 'op' => 'eq', 'value' => 'IT'],
]], ['amount' => 2000, 'cat' => 'IT']) === true);
ok('all (AND) falla si un hijo falla', $ce->evaluate(['all' => [
    ['field' => 'amount', 'op' => 'gte', 'value' => 1000],
    ['field' => 'cat', 'op' => 'eq', 'value' => 'IT'],
]], ['amount' => 2000, 'cat' => 'HR']) === false);
ok('any (OR) cumple con un hijo', $ce->evaluate(['any' => [
    ['field' => 'amount', 'op' => 'gte', 'value' => 100000],
    ['field' => 'cat', 'op' => 'eq', 'value' => 'IT'],
]], ['amount' => 5, 'cat' => 'IT']) === true);
ok('operador desconocido → false (fail-closed)', $ce->evaluate(['field' => 'x', 'op' => 'regex', 'value' => '.*'], ['x' => 'a']) === false);
ok('JSON ilegible → false (fail-closed)', $ce->evaluate('!!not json!!', []) === false);
ok('campo ausente en contexto → false', $ce->evaluate(['field' => 'missing', 'op' => 'gte', 'value' => 1], []) === false);

echo "== QuorumCalculator ==\n";
$q = new QuorumCalculator();
ok('count: 2/ needed 2 → true', $q->isMet('count', 2, 3, 2) === true);
ok('count: 1/ needed 2 → false', $q->isMet('count', 2, 3, 1) === false);
ok('count: sin aprobadores (total 0) → false', $q->isMet('count', 1, 0, 0) === false);
ok('percent: 50% de 4 con 2 → true', $q->isMet('percent', 50, 4, 2) === true);
ok('percent: 50% de 4 con 1 → false', $q->isMet('percent', 50, 4, 1) === false);
ok('percent: 100% de 3 con 3 → true', $q->isMet('percent', 100, 3, 3) === true);
ok('percent: total 0 → false', $q->isMet('percent', 50, 0, 0) === false);
ok('percent: value 0 → false', $q->isMet('percent', 0, 4, 0) === false);

echo "== TransitionResolver ==\n";
$tr = new TransitionResolver();
$transitions = [
    ['id' => 10, 'from_statedefs_id' => 1, 'to_statedefs_id' => 2, 'action' => 'submit'],
    ['id' => 11, 'from_statedefs_id' => 2, 'to_statedefs_id' => 3, 'action' => 'approve'],
    ['id' => 12, 'from_statedefs_id' => 2, 'to_statedefs_id' => 9, 'action' => 'reject'],
];
ok('resuelve submit desde 1', ($tr->resolve($transitions, 1, 'submit')['id'] ?? 0) === 10);
ok('resuelve approve desde 2', ($tr->resolve($transitions, 2, 'approve')['id'] ?? 0) === 11);
ok('acción inexistente → null', $tr->resolve($transitions, 1, 'approve') === null);
ok('estado inexistente → null', $tr->resolve($transitions, 99, 'submit') === null);
ok('actionsFrom(2) = [approve, reject]', $tr->actionsFrom($transitions, 2) === ['approve', 'reject']);

echo "== DelegationResolver ==\n";
$dr = new DelegationResolver();
$base = [
    ['is_active' => 1, 'users_id_from' => 5, 'users_id_to' => 7, 'workflowdefs_id' => 0, 'entities_id' => 0,
     'date_start' => date('Y-m-d H:i:s', time() - 3600), 'date_end' => date('Y-m-d H:i:s', time() + 3600)],
    ['is_active' => 0, 'users_id_from' => 5, 'users_id_to' => 8, 'workflowdefs_id' => 0, 'entities_id' => 0,
     'date_start' => null, 'date_end' => null],
    ['is_active' => 1, 'users_id_from' => 5, 'users_id_to' => 9, 'workflowdefs_id' => 0, 'entities_id' => 0,
     'date_start' => date('Y-m-d H:i:s', time() + 3600), 'date_end' => date('Y-m-d H:i:s', time() + 7200)], // futura
    ['is_active' => 1, 'users_id_from' => 5, 'users_id_to' => 10, 'workflowdefs_id' => 999, 'entities_id' => 0,
     'date_start' => null, 'date_end' => null], // otra definición
];
$eff = $dr->effectiveDelegates($base, 5, 42, 0);
ok('incluye delegado vigente (7)', in_array(7, $eff, true));
ok('excluye inactivo (8)', !in_array(8, $eff, true));
ok('excluye ventana futura (9)', !in_array(9, $eff, true));
ok('excluye otra definición (10)', !in_array(10, $eff, true));
ok('usuario sin delegaciones → vacío', $dr->effectiveDelegates($base, 999, 42, 0) === []);
$scoped = $dr->effectiveDelegates([
    ['is_active' => 1, 'users_id_from' => 5, 'users_id_to' => 11, 'workflowdefs_id' => 42, 'entities_id' => 3,
     'date_start' => null, 'date_end' => null],
], 5, 42, 3);
ok('alcance def+entidad coincidente → incluido (11)', in_array(11, $scoped, true));

echo "\n" . ($fail > 0
    ? "\033[31mUNIT FAIL: {$fail}/{$total}\033[0m"
    : "\033[32mUNIT OK: {$total}/{$total}\033[0m") . "\n";

exit($fail > 0 ? 1 : 0);
