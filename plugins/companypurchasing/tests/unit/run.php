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

use GlpiPlugin\Companypurchasing\Service\CurrencyPolicy;
use GlpiPlugin\Companypurchasing\Service\Money;
use GlpiPlugin\Companypurchasing\Service\NumberingService;
use GlpiPlugin\Companypurchasing\Service\QuantityPolicy;
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

// Record semántico de ejemplo (como lo produciría el builder) con campos comerciales presentes.
$full = [
    'requester' => 7, 'department' => 3, 'category' => 'IT', 'destination' => 'Depósito',
    'reason' => 'motivo', 'observations' => 'obs', 'budget' => 0,
    'currency' => 'PYG', 'total' => '5000', 'suppliers_id_selected' => 99, 'final_prices' => ['a'],
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

echo "\n" . ($fail > 0
    ? "\033[31mUNIT FAIL: {$fail}/{$total}\033[0m"
    : "\033[32mUNIT OK: {$total}/{$total}\033[0m") . "\n";

exit($fail > 0 ? 1 : 0);
