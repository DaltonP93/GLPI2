<?php

/**
 * Tests UNITARIOS puros de companypurchasing (sin bootstrap de GLPI) — P2D-1.
 *
 * Ejercitan la lógica que NO depende del core: dinero EXACTO (sin float; PYG sin decimales),
 * determinismo del snapshot de scope, invariante "comercial no contamina REQUEST_SCOPE", formato de
 * numeración y validación de cantidades. Se ejecuta en el job estático de CI:
 *   php plugins/companypurchasing/tests/unit/run.php
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

$svc = dirname(__DIR__, 2) . '/src/Service/';
require $svc . 'Decimal.php';
require $svc . 'CurrencyPolicy.php';
require $svc . 'Money.php';
require $svc . 'ScopeCatalog.php';
require $svc . 'ScopeSnapshotBuilder.php';
require $svc . 'NumberingService.php';
require $svc . 'QuantityPolicy.php';
// RequestManager sólo se CARGA (no se instancia): permite verificar de forma pura y determinista el núcleo
// autoritativo `isEntityApplicableInChain()` sin bootstrap de GLPI (las dependencias GLPI son sólo `use`).
require $svc . 'RequestManager.php';

use GlpiPlugin\Companypurchasing\Service\CurrencyPolicy;
use GlpiPlugin\Companypurchasing\Service\Money;
use GlpiPlugin\Companypurchasing\Service\NumberingService;
use GlpiPlugin\Companypurchasing\Service\QuantityPolicy;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use GlpiPlugin\Companypurchasing\Service\ScopeCatalog;
use GlpiPlugin\Companypurchasing\Service\ScopeSnapshotBuilder;

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
function throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (\Throwable) {
        return true;
    }
}

echo "== Money — dinero EXACTO (sin float) ==\n";
ok('USD 2 decimales: "1000.50" se conserva', Money::of('1000.50', 'USD')->amount() === '1000.50');
ok('USD suma exacta 1000.50 + 0.50 = 1001.00', Money::of('1000.50', 'USD')->plus(Money::of('0.50', 'USD'))->amount() === '1001.00');
ok('PYG entero "1000" válido', Money::of('1000', 'PYG')->amount() === '1000');
ok('PYG × entero: 1500 × 3 = 4500', Money::of('1500', 'PYG')->timesInt(3)->amount() === '4500');
$big = Money::sum([
    Money::of('999999999999', 'PYG'), // 12 dígitos
    Money::of('1', 'PYG'),
], 'PYG');
ok('PYG suma grande exacta (sin overflow): 999999999999 + 1 = 1000000000000', $big->amount() === '1000000000000');
ok('ofStored reformatea DECIMAL(20,6): "1000.000000" PYG → "1000"', Money::ofStored('1000.000000', 'PYG')->amount() === '1000');
ok('mezclar monedas → excepción', throws(fn() => Money::of('10', 'USD')->plus(Money::of('10', 'PYG'))));

echo "== PYG rechaza fracciones (escala 0) ==\n";
ok('PYG "1000.50" → inválido', throws(fn() => Money::of('1000.50', 'PYG')));
ok('PYG "1000.0" (fracción cero explícita) → inválido', throws(fn() => Money::of('1000.0', 'PYG')));
ok('PYG almacenado "1000.500000" → NO se redondea (falla)', throws(fn() => Money::ofStored('1000.500000', 'PYG')));
ok('moneda mal formada → inválido', throws(fn() => Money::of('10', 'PY')));
ok('float pasado como texto con más escala que la moneda → inválido (USD "1.005")', throws(fn() => Money::of('1.005', 'USD')));

echo "== CurrencyPolicy (escala por moneda) ==\n";
ok('PYG escala 0', CurrencyPolicy::scale('PYG') === 0);
ok('USD escala 2', CurrencyPolicy::scale('USD') === 2);
ok('moneda desconocida → escala por defecto (2)', CurrencyPolicy::scale('XYZ') === CurrencyPolicy::DEFAULT_SCALE);
ok('override respeta monedas no fijas (USD→3)', CurrencyPolicy::scale('USD', ['USD' => 3]) === 3);
ok('PYG es FIJA: el override NO la cambia', CurrencyPolicy::scale('PYG', ['PYG' => 2]) === 0);

echo "== Approval scopes — REQUEST vs COMMERCIAL ==\n";
$reqFields = ScopeCatalog::defaultFields(ScopeCatalog::SCOPE_REQUEST);
$comFields = ScopeCatalog::defaultFields(ScopeCatalog::SCOPE_COMMERCIAL_FINANCIAL);
ok('REQUEST_SCOPE NO contiene claves comerciales', ScopeCatalog::isFreeOfCommercialKeys($reqFields) === true);
ok('COMMERCIAL_FINANCIAL_SCOPE SÍ contiene claves comerciales', ScopeCatalog::isFreeOfCommercialKeys($comFields) === false);
ok('REQUEST_SCOPE incluye líneas y solicitante', in_array('lines', $reqFields, true) && in_array('requester', $reqFields, true));

// Record semántico de ejemplo (todas las claves de `ALLOWED_KEYS` producibles): cada scope SELECCIONA
// su subconjunto. Incluye las claves comerciales (P2D-2) para poder ejercitar COMMERCIAL_FINANCIAL_SCOPE.
$full = [
    'requester' => 7, 'department' => 3, 'category' => 'IT', 'destination' => 'Depósito',
    'reason' => 'motivo', 'observations' => 'obs', 'budget' => 0,
    'currency' => 'PYG', 'total' => '5000', 'suppliers_id_selected' => 99, 'selected_quote' => 12,
    'final_prices' => ['a'], 'discounts' => '0', 'taxes' => '0', 'freight' => '0',
    'lines' => [['description' => 'x', 'category' => 'IT', 'quantity' => '2', 'unit' => 'u', 'is_inventoriable' => 1]],
];
$req1 = ScopeSnapshotBuilder::selectFields($full, $reqFields);
$req2 = ScopeSnapshotBuilder::selectFields($full, $reqFields);
ok('snapshot REQUEST_SCOPE determinista (mismo array)', $req1 === $req2);
ok('snapshot REQUEST_SCOPE determinista (mismo JSON)', json_encode($req1) === json_encode($req2));
$commercialLeak = array_intersect(array_keys($req1), ScopeCatalog::COMMERCIAL_ONLY_KEYS);
ok('campos comerciales NO contaminan el payload REQUEST_SCOPE', $commercialLeak === []);
ok('REQUEST_SCOPE sí incluye las líneas', isset($req1['lines']) && $req1['lines'][0]['description'] === 'x');
$com1 = ScopeSnapshotBuilder::selectFields($full, $comFields);
ok('COMMERCIAL_FINANCIAL_SCOPE incluye proveedor/total', isset($com1['suppliers_id_selected'], $com1['total']));
// FAIL-CLOSED: una clave protegida que el record NO puede producir jamás se ignora en silencio.
ok('selectFields con clave no producible → lanza (fail-closed)', throws(fn() => ScopeSnapshotBuilder::selectFields(['requester' => 1], ['requester', 'suppliers_id_selected'])));
ok('selectFields con typo → lanza (no snapshot parcial)', throws(fn() => ScopeSnapshotBuilder::selectFields($full, ['requester', 'quantitty'])));

echo "== Numeración (formato estable) ==\n";
ok('formato "REQUEST-2026-000007"', NumberingService::formatNumber('request', 2026, 7) === 'REQUEST-2026-000007');
ok('secuencias distintas → números distintos', NumberingService::formatNumber('request', 2026, 7) !== NumberingService::formatNumber('request', 2026, 8));
ok('otro año → distinta cadena (secuencia independiente)', NumberingService::formatNumber('request', 2027, 7) !== NumberingService::formatNumber('request', 2026, 7));

echo "== ScopeSnapshotBuilder::envelope (document_version la declara el dominio) ==\n";
$sem = ['schema' => 'companypurchasing/request/v1', 'subject_type' => 'X', 'subject_id' => 5, 'entity_id' => 3, 'scope' => 'REQUEST_SCOPE', 'scopes_version' => 1, 'payload' => ['a' => 1]];
ok('snapshot semántico NO trae document_version', !array_key_exists('document_version', $sem));
$env = ScopeSnapshotBuilder::envelope($sem, 4);
ok('envelope agrega document_version parametrizada (=4)', ($env['document_version'] ?? null) === 4);
ok('envelope conserva schema/subject/payload', $env['schema'] === 'companypurchasing/request/v1' && (int) $env['subject_id'] === 5 && (($env['payload']['a'] ?? null) === 1));
ok('envelope con document_version 0 → excepción (nunca hardcodeada)', throws(fn() => ScopeSnapshotBuilder::envelope($sem, 0)));
ok('envelope con document_version negativa → excepción', throws(fn() => ScopeSnapshotBuilder::envelope($sem, -1)));

echo "== Cantidades (v1: entero positivo) ==\n";
ok('entero válido "5" → 5', QuantityPolicy::validate('5', true) === 5);
ok('inventariable con decimal "2.5" → inválido', throws(fn() => QuantityPolicy::validate('2.5', true)));
ok('cero → inválido', throws(fn() => QuantityPolicy::validate('0', true)));
ok('negativo → inválido', throws(fn() => QuantityPolicy::validate('-1', false)));
ok('no numérico → inválido', throws(fn() => QuantityPolicy::validate('abc', false)));
ok('vacío → inválido', throws(fn() => QuantityPolicy::validate('', true)));

echo "== Aplicabilidad de entidad AUTORITATIVA (cadena viva, NUNCA caché de árbol) ==\n";
// `$parentOf` = padre ACTUAL por id (null = inexistente). La decisión se toma SÓLO por la cadena viva,
// nunca por `getSonsOf()`/`getAncestorsOf()` (cacheadas y potencialmente stale → fail-open).
$underA   = static fn(int $id): ?int => [30 => 10, 10 => 0][$id] ?? null; // X(30) → A(10) → raíz(0)
$movedToB = static fn(int $id): ?int => [30 => 20, 20 => 0][$id] ?? null; // X(30) → B(20) → raíz(0)  (X MOVIDA)
ok('misma entidad → aplicable', RequestManager::isEntityApplicableInChain(30, true, 30, $underA) === true);
ok('no recursivo en otra entidad → NO aplicable', RequestManager::isEntityApplicableInChain(10, false, 30, $underA) === false);
ok('recursivo del ANCESTRO actual (A) → aplicable', RequestManager::isEntityApplicableInChain(10, true, 30, $underA) === true);
ok('recursivo de otra RAMA (B) → NO aplicable', RequestManager::isEntityApplicableInChain(20, true, 30, $underA) === false);
ok('recursivo en la RAÍZ (0) → aplicable (raíz es ancestro)', RequestManager::isEntityApplicableInChain(0, true, 30, $underA) === true);
// CLAVE — fail-open evitado: X fue MOVIDA de A a B. Una caché de árbol vieja aún diría "X bajo A", pero la
// cadena VIVA dice "X bajo B": un supplier recursivo de A ya NO puede autorizar la referencia cross-branch.
ok('MOVIDA A→B: recursivo de A ya NO autoriza (cadena viva, no caché)', RequestManager::isEntityApplicableInChain(10, true, 30, $movedToB) === false);
ok('MOVIDA A→B: recursivo de B (nuevo ancestro) sí aplica', RequestManager::isEntityApplicableInChain(20, true, 30, $movedToB) === true);
// Fail-closed ante inconsistencias del árbol:
$cycle = static fn(int $id): ?int => [30 => 31, 31 => 30][$id] ?? null; // ciclo 30↔31
ok('ciclo en la cadena → fail-closed (NO aplicable)', RequestManager::isEntityApplicableInChain(10, true, 30, $cycle) === false);
$ascending = static fn(int $id): ?int => $id + 1; // cadena infinita ascendente (nunca llega a refEntity)
ok('profundidad excesiva → fail-closed (NO aplicable)', RequestManager::isEntityApplicableInChain(5, true, 100, $ascending) === false);
$missing = static fn(int $id): ?int => null; // entidad inexistente
ok('entidad inexistente en la cadena → fail-closed (NO aplicable)', RequestManager::isEntityApplicableInChain(10, true, 30, $missing) === false);

echo "\n" . ($fail > 0
    ? "\033[31mUNIT FAIL: {$fail}/{$total}\033[0m"
    : "\033[32mUNIT OK: {$total}/{$total}\033[0m") . "\n";

exit($fail > 0 ? 1 : 0);
