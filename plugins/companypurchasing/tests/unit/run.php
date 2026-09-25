<?php

/**
 * Tests UNITARIOS puros de companypurchasing (sin bootstrap de GLPI) — P2D-1 + P2D-2 + P2D-3.
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
require $svc . 'ReferenceValidator.php';
require $svc . 'RequestManager.php';
// P2D-2 (lógica PURA; los archivos sólo se CARGAN, sin bootstrap de GLPI).
require $svc . 'QuoteMath.php';
require $svc . 'PurchasingWorkflow.php';
require $svc . 'DocumentVersionAllocator.php';
require $svc . 'ApprovalPolicy.php';
require $svc . 'ApprovalOrchestrator.php'; // sólo el helper PURO planBatch()
// P2D-3 (lógica PURA; los servicios con GLPI sólo se CARGAN para sus helpers estáticos puros).
require $svc . 'CostPolicy.php';
require $svc . 'CostAllocator.php';
require $svc . 'HandoffPayload.php';
require $svc . 'ReceivingService.php'; // sólo uuidV4()
require dirname(__DIR__, 2) . '/src/Api/PurchasingIntegrationApi.php'; // sólo sanitizeError()

use GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi;
use GlpiPlugin\Companypurchasing\Service\ApprovalOrchestrator;
use GlpiPlugin\Companypurchasing\Service\CostAllocator;
use GlpiPlugin\Companypurchasing\Service\CostPolicy;
use GlpiPlugin\Companypurchasing\Service\HandoffPayload;
use GlpiPlugin\Companypurchasing\Service\ReceivingService;
use GlpiPlugin\Companypurchasing\Service\ApprovalPolicy;
use GlpiPlugin\Companypurchasing\Service\CurrencyPolicy;
use GlpiPlugin\Companypurchasing\Service\Decimal;
use GlpiPlugin\Companypurchasing\Service\DocumentVersionAllocator;
use GlpiPlugin\Companypurchasing\Service\PurchasingWorkflow;
use GlpiPlugin\Companypurchasing\Service\QuoteMath;
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


// ============================================================================ P2D-2
echo "== P2D-2 · Decimal/Money: resta y comparación EXACTAS (fail-closed si negativo) ==\n";
ok('cmpStr 10 < 9? no (10 > 9)', Decimal::cmpStr('10', '9') === 1 && Decimal::cmpStr('9', '10') === -1 && Decimal::cmpStr('007', '7') === 0);
ok('subStr 1000000 - 1 = 999999 (préstamos)', Decimal::subStr('1000000', '1') === '999999');
ok('subStr a - a = 0', Decimal::subStr('123456789012345678', '123456789012345678') === '0');
ok('subStr negativo → lanza (fail-closed)', throws(fn () => Decimal::subStr('5', '6')));
ok('Money minus PYG 5100 - 300 = 4800', Money::of('5100', 'PYG')->minus(Money::of('300', 'PYG'))->amount() === '4800');
ok('Money minus USD 10.05 - 0.10 = 9.95', Money::of('10.05', 'USD')->minus(Money::of('0.10', 'USD'))->amount() === '9.95');
ok('Money minus con resultado negativo → lanza', throws(fn () => Money::of('1', 'PYG')->minus(Money::of('2', 'PYG'))));
ok('Money minus entre monedas distintas → lanza', throws(fn () => Money::of('1', 'PYG')->minus(Money::of('1', 'USD'))));
ok('Money compare', Money::of('2', 'PYG')->compare(Money::of('10', 'PYG')) === -1);

echo "== P2D-2 · QuoteMath (términos comerciales exactos, derivados) ==\n";
$qm = QuoteMath::compute([
    ['line_id' => 11, 'quantity' => 2, 'final_unit_price' => '1400'],
    ['line_id' => 12, 'quantity' => 1, 'final_unit_price' => '2300'],
], '300', '550', '100', 'PYG');
ok('subtotal = 2×1400 + 1×2300 = 5100', $qm['subtotal'] === '5100');
ok('total = 5100 + 550 + 100 − 300 = 5450 (string exacto)', $qm['total'] === '5450' && is_string($qm['total']));
ok('line_total derivado por línea + orden conservado', $qm['lines'][0] === ['line_id' => 11, 'quantity' => '2', 'final_unit_price' => '1400', 'line_total' => '2800'] && $qm['lines'][1]['line_id'] === 12);
ok('PYG con decimales → rechazado', throws(fn () => QuoteMath::compute([['line_id' => 1, 'quantity' => 1, 'final_unit_price' => '10.5']], '0', '0', '0', 'PYG')));
ok('descuento > subtotal+impuestos+flete → rechazado (total negativo)', throws(fn () => QuoteMath::compute([['line_id' => 1, 'quantity' => 1, 'final_unit_price' => '100']], '201', '50', '50', 'PYG')));
ok('descuento = subtotal+impuestos+flete → total 0 (no negativo)', QuoteMath::compute([['line_id' => 1, 'quantity' => 1, 'final_unit_price' => '100']], '200', '50', '50', 'PYG')['total'] === '0');
ok('sin líneas → rechazado', throws(fn () => QuoteMath::compute([], '0', '0', '0', 'PYG')));
ok('cantidad < 1 → rechazada', throws(fn () => QuoteMath::compute([['line_id' => 1, 'quantity' => 0, 'final_unit_price' => '1']], '0', '0', '0', 'PYG')));
ok('USD escala 2 exacta: 3 × 19.99 = 59.97', QuoteMath::compute([['line_id' => 1, 'quantity' => 3, 'final_unit_price' => '19.99']], '0', '0', '0', 'USD')['total'] === '59.97');
ok('cobertura completa OK', !throws(fn () => QuoteMath::assertCoverage([11, 12], [12, 11])));
ok('cobertura: falta una línea → rechazado', throws(fn () => QuoteMath::assertCoverage([11, 12], [11])));
ok('cobertura: línea ajena → rechazado', throws(fn () => QuoteMath::assertCoverage([11], [11, 99])));
ok('cobertura: línea repetida → rechazado', throws(fn () => QuoteMath::assertCoverage([11], [11, 11])));

echo "== P2D-2 · DocumentVersionAllocator::payloadHash (reuso sólo si el contenido NO cambió) ==\n";
$semA = ['schema' => 's', 'subject_id' => 7, 'payload' => ['b' => '2', 'a' => '1', 'lines' => [['q' => '1'], ['q' => '2']]]];
$semB = ['payload' => ['lines' => [['q' => '1'], ['q' => '2']], 'a' => '1', 'b' => '2'], 'subject_id' => 7, 'schema' => 's'];
ok('orden de claves de objeto irrelevante (determinista)', DocumentVersionAllocator::payloadHash($semA) === DocumentVersionAllocator::payloadHash($semB));
$semC = $semA;
$semC['payload']['lines'] = [['q' => '2'], ['q' => '1']];
ok('orden de LISTAS relevante (líneas)', DocumentVersionAllocator::payloadHash($semA) !== DocumentVersionAllocator::payloadHash($semC));
$semD = $semA;
$semD['payload']['lines'][0]['q'] = '5';
ok('cambio de cantidad ⇒ hash distinto', DocumentVersionAllocator::payloadHash($semA) !== DocumentVersionAllocator::payloadHash($semD));
ok('document_version NO participa del hash', DocumentVersionAllocator::payloadHash($semA + ['document_version' => 9]) === DocumentVersionAllocator::payloadHash($semA));
ok('float → fail-closed', throws(fn () => DocumentVersionAllocator::payloadHash(['payload' => ['total' => 1.5]])));
ok('hash sha256 hex', preg_match('/^[0-9a-f]{64}$/', DocumentVersionAllocator::payloadHash($semA)) === 1);

echo "== P2D-2 · PurchasingWorkflow::spec (definición desde configuración) ==\n";
$cfg = ['groups' => ['PENDING_AREA_HEAD' => 11, 'PURCHASING' => 12, 'PENDING_FINANCE' => 13], 'quorum' => ['PENDING_AREA_HEAD' => 2], 'sla' => ['PENDING_FINANCE' => 48]];
$spec = PurchasingWorkflow::spec('cp_test', 'X\\Request', $cfg);
$initials = array_filter($spec['states'], static fn ($st) => $st['kind'] === 'initial');
ok('exactamente un estado inicial (DRAFT, editable)', count($initials) === 1 && array_values($initials)[0]['code'] === 'DRAFT' && array_values($initials)[0]['is_editable'] === 1);
$byCode = [];
foreach ($spec['states'] as $st) {
    $byCode[$st['code']] = $st;
}
ok('APPROVED es INTERMEDIO (invalidación post-aprobación posible; P2D-3 continúa)', $byCode['APPROVED']['kind'] === 'intermediate');
ok('REJECTED/CANCELLED finales; RETURNED editable', $byCode['REJECTED']['kind'] === 'final' && $byCode['CANCELLED']['kind'] === 'final' && $byCode['RETURNED']['is_editable'] === 1);
ok('SLA configurable por etapa (48h Gerencia; resto sin SLA)', $byCode['PENDING_FINANCE']['sla_hours'] === 48 && $byCode['PENDING_AREA_HEAD']['sla_hours'] === null);
$approves = array_values(array_filter($spec['transitions'], static fn ($t) => $t['action'] === 'approve'));
ok('3 aprobaciones, todas con RIGHT_ACT + condición evidence_bound + step de GRUPO', count($approves) === 3 && array_reduce($approves, static fn ($c, $t) => $c
    && $t['required_right'] === 2 && $t['condition'] === PurchasingWorkflow::EVIDENCE_CONDITION && $t['steps'][0]['approver_kind'] === 'group', true));
ok('grupos/quórum salen de la CONFIGURACIÓN (jefe: grupo 11, quórum 2)', $approves[0]['from'] === 'PENDING_AREA_HEAD' && $approves[0]['steps'][0]['approver_ref'] === 11 && $approves[0]['steps'][0]['quorum_value'] === 2);
$rejRet = array_filter($spec['transitions'], static fn ($t) => in_array($t['action'], ['reject', 'return'], true));
ok('reject/return exigen comentario y RIGHT_ACT', $rejRet !== [] && array_reduce($rejRet, static fn ($c, $t) => $c && $t['requires_comment'] === 1 && $t['required_right'] === 2, true));
ok('Gerencia devuelve a Compras (re-cotizar)', count(array_filter($spec['transitions'], static fn ($t) => $t['from'] === 'PENDING_FINANCE' && $t['action'] === 'return' && $t['to'] === 'PURCHASING')) === 1);
ok('grupo sin configurar → fail-closed', throws(fn () => PurchasingWorkflow::spec('cp_test', 'X', ['groups' => ['PENDING_AREA_HEAD' => 11, 'PURCHASING' => 12, 'PENDING_FINANCE' => 0]])));
ok('quórum inválido → fail-closed', throws(fn () => PurchasingWorkflow::spec('cp_test', 'X', ['groups' => $cfg['groups'], 'quorum' => ['PURCHASING' => 0]])));
ok('code inválido → fail-closed', throws(fn () => PurchasingWorkflow::spec('x y', 'X', $cfg)));
ok('ningún aprobador hardcodeado (sin config → sin spec)', throws(fn () => PurchasingWorkflow::spec('cp_test', 'X', ['groups' => []])));

echo "== P2D-2 · etapas, reinicio de scopes y mapas configurables ==\n";
ok('stageIndex: circuito 0..3; DRAFT/RETURNED -1; finales -2', PurchasingWorkflow::stageIndex('PENDING_AREA_HEAD') === 0 && PurchasingWorkflow::stageIndex('APPROVED') === 3
    && PurchasingWorkflow::stageIndex('RETURNED') === -1 && PurchasingWorkflow::stageIndex('REJECTED') === -2);
ok('entrar a PURCHASING reinicia COMMERCIAL (checkpoint PURCHASING) pero NO REQUEST', PurchasingWorkflow::resetsScope('PURCHASING', 'PURCHASING') && !PurchasingWorkflow::resetsScope('PURCHASING', 'PENDING_AREA_HEAD'));
ok('entrar a RETURNED reinicia ambos scopes', PurchasingWorkflow::resetsScope('RETURNED', 'PENDING_AREA_HEAD') && PurchasingWorkflow::resetsScope('RETURNED', 'PURCHASING'));
ok('entrar a PENDING_FINANCE no reinicia nada', !PurchasingWorkflow::resetsScope('PENDING_FINANCE', 'PURCHASING') && !PurchasingWorkflow::resetsScope('PENDING_FINANCE', 'PENDING_AREA_HEAD'));
$stageScopes = ['PENDING_AREA_HEAD' => 'REQUEST_SCOPE', 'PURCHASING' => 'COMMERCIAL_FINANCIAL_SCOPE', 'PENDING_FINANCE' => 'COMMERCIAL_FINANCIAL_SCOPE'];
$cps = ['REQUEST_SCOPE' => 'PENDING_AREA_HEAD', 'COMMERCIAL_FINANCIAL_SCOPE' => 'PURCHASING'];
$known = ['REQUEST_SCOPE', 'COMMERCIAL_FINANCIAL_SCOPE'];
ok('mapas por defecto válidos', !throws(fn () => PurchasingWorkflow::validateMaps($stageScopes, $cps, $known)));
ok('checkpoint POSTERIOR a la etapa que lo usa → rechazado', throws(fn () => PurchasingWorkflow::validateMaps($stageScopes, ['REQUEST_SCOPE' => 'PENDING_FINANCE', 'COMMERCIAL_FINANCIAL_SCOPE' => 'PURCHASING'], $known)));
ok('scope desconocido → rechazado', throws(fn () => PurchasingWorkflow::validateMaps(['PENDING_AREA_HEAD' => 'X'] + $stageScopes, $cps, $known)));
ok('etapa no aprobatoria en stage_scopes → rechazado', throws(fn () => PurchasingWorkflow::validateMaps($stageScopes + ['APPROVED' => 'REQUEST_SCOPE'], $cps, $known)));

echo "== P2D-2 · liveDecisions: aprobaciones VIVAS derivadas del LEDGER del motor ==\n";
$ev = static fn (int $id, string $event, string $from, string $to, array $meta = []): array => ['id' => $id, 'event' => $event, 'from_code' => $from, 'to_code' => $to, 'meta_json' => $meta === [] ? '' : json_encode($meta)];
$appr = static fn (int $v): array => ['decision' => 'approved', 'evidence_ref' => ['document_versions_id' => 100 + $v, 'document_version' => $v, 'content_sha256' => str_repeat('a', 64)]];
$base = [
    $ev(1, 'started', '', 'DRAFT'),
    $ev(2, 'transitioned', 'DRAFT', 'PENDING_AREA_HEAD'),
    $ev(3, 'decision_recorded', 'PENDING_AREA_HEAD', 'PENDING_AREA_HEAD', $appr(1)),
    $ev(4, 'transitioned', 'PENDING_AREA_HEAD', 'PURCHASING'),
    $ev(5, 'decision_recorded', 'PURCHASING', 'PURCHASING', $appr(2)),
    $ev(6, 'transitioned', 'PURCHASING', 'PENDING_FINANCE'),
    $ev(7, 'decision_recorded', 'PENDING_FINANCE', 'PENDING_FINANCE', $appr(2)),
    $ev(8, 'transitioned', 'PENDING_FINANCE', 'APPROVED'),
];
$ids = static fn (array $l): array => array_map(static fn ($d) => $d['id'], $l);
ok('REQUEST: la decisión del jefe está viva', $ids(PurchasingWorkflow::liveDecisions($base, 'REQUEST_SCOPE', 'PENDING_AREA_HEAD', $stageScopes)) === [3]);
ok('COMMERCIAL: Compras + Gerencia vivas', $ids(PurchasingWorkflow::liveDecisions($base, 'COMMERCIAL_FINANCIAL_SCOPE', 'PURCHASING', $stageScopes)) === [5, 7]);
$afterComm = array_merge($base, [$ev(9, 'approval_invalidated', 'APPROVED', 'PURCHASING', ['idempotency_key' => 'k1'])]);
ok('invalidación a PURCHASING: COMMERCIAL ya no vivas; el JEFE sigue viva', $ids(PurchasingWorkflow::liveDecisions($afterComm, 'COMMERCIAL_FINANCIAL_SCOPE', 'PURCHASING', $stageScopes)) === []
    && $ids(PurchasingWorkflow::liveDecisions($afterComm, 'REQUEST_SCOPE', 'PENDING_AREA_HEAD', $stageScopes)) === [3]);
$afterReq = array_merge($base, [$ev(9, 'approval_invalidated', 'PURCHASING', 'PENDING_AREA_HEAD', ['idempotency_key' => 'k2'])]);
ok('invalidación al jefe: NINGÚN scope queda vivo', PurchasingWorkflow::liveDecisions($afterReq, 'REQUEST_SCOPE', 'PENDING_AREA_HEAD', $stageScopes) === []
    && PurchasingWorkflow::liveDecisions($afterReq, 'COMMERCIAL_FINANCIAL_SCOPE', 'PURCHASING', $stageScopes) === []);
$returned = array_merge(array_slice($base, 0, 5), [$ev(6, 'transitioned', 'PURCHASING', 'RETURNED')]);
ok('devolución (RETURNED) reinicia todo', PurchasingWorkflow::liveDecisions($returned, 'REQUEST_SCOPE', 'PENDING_AREA_HEAD', $stageScopes) === []);
$partial = [$ev(1, 'started', '', 'DRAFT'), $ev(2, 'transitioned', 'DRAFT', 'PENDING_AREA_HEAD'), $ev(3, 'decision_recorded', 'PENDING_AREA_HEAD', 'PENDING_AREA_HEAD', $appr(1))];
ok('voto parcial de quórum (sin avanzar) también está vivo', $ids(PurchasingWorkflow::liveDecisions($partial, 'REQUEST_SCOPE', 'PENDING_AREA_HEAD', $stageScopes)) === [3]);
$rej = array_merge($partial, [$ev(4, 'decision_recorded', 'PENDING_AREA_HEAD', 'REJECTED', ['decision' => 'rejected'])]);
ok('decisiones no aprobatorias no cuentan', $ids(PurchasingWorkflow::liveDecisions($rej, 'REQUEST_SCOPE', 'PENDING_AREA_HEAD', $stageScopes)) === [3]);
// Motor con actor ÚNICO: la decisión se registra DESPUÉS de la fila `transitioned` que sale del estado.
$singleActor = [
    $ev(1, 'started', '', 'DRAFT'),
    $ev(2, 'transitioned', 'DRAFT', 'PENDING_AREA_HEAD'),
    $ev(3, 'transitioned', 'PENDING_AREA_HEAD', 'PURCHASING'),
    $ev(4, 'decision_recorded', 'PENDING_AREA_HEAD', 'PURCHASING', $appr(1)),
];
ok('actor único: la decisión del jefe (id posterior a la entrada a PURCHASING) sigue VIVA para REQUEST', $ids(PurchasingWorkflow::liveDecisions($singleActor, 'REQUEST_SCOPE', 'PENDING_AREA_HEAD', $stageScopes)) === [4]);
$reopenSame = array_merge(array_slice($base, 0, 5), [
    $ev(6, 'approval_invalidated', 'PURCHASING', 'PURCHASING', ['idempotency_key' => 'k3']),
    $ev(7, 'decision_recorded', 'PURCHASING', 'PURCHASING', $appr(3)),
]);
ok('reapertura a la MISMA etapa: votos previos no vivos, posteriores sí', $ids(PurchasingWorkflow::liveDecisions($reopenSame, 'COMMERCIAL_FINANCIAL_SCOPE', 'PURCHASING', $stageScopes)) === [7]);
ok('orden del ledger irrelevante (se ordena por id)', $ids(PurchasingWorkflow::liveDecisions(array_reverse($base), 'COMMERCIAL_FINANCIAL_SCOPE', 'PURCHASING', $stageScopes)) === [5, 7]);

echo "== P2D-2 · ScopeSnapshotBuilder::commercialRecord ==\n";
$terms = ['quote_id' => 5, 'reference' => 'Q-1', 'suppliers_id' => 9, 'math' => $qm];
$cr = ScopeSnapshotBuilder::commercialRecord($terms, 'PYG');
ok('claves comerciales del vocabulario cerrado', array_diff(array_keys($cr), ScopeCatalog::ALLOWED_KEYS) === [] && $cr['total'] === '5450' && $cr['suppliers_id_selected'] === 9 && $cr['selected_quote']['id'] === 5);
ok('moneda distinta a la de la solicitud → fail-closed', throws(fn () => ScopeSnapshotBuilder::commercialRecord($terms, 'USD')));
ok('términos incompletos → fail-closed', throws(fn () => ScopeSnapshotBuilder::commercialRecord(['quote_id' => 5, 'suppliers_id' => 9, 'math' => ['currency' => 'PYG']], 'PYG')));
ok('COMMERCIAL_FINANCIAL (v1) = REQUEST + claves comerciales producibles', array_diff(ScopeCatalog::defaultFields('COMMERCIAL_FINANCIAL_SCOPE'), array_merge(ScopeCatalog::REQUEST_KEYS, ['currency', 'total'], array_keys($cr))) === []);


// ============================================================================ P2D-2 · pasada de integridad
echo "== P2D-2 · ApprovalPolicy (política pinneada: canónica, hash, validación, prioridad) ==\n";
$polRaw = [
    'stage_scopes'      => ['PURCHASING' => 'COMMERCIAL_FINANCIAL_SCOPE', 'PENDING_AREA_HEAD' => 'REQUEST_SCOPE', 'PENDING_FINANCE' => 'COMMERCIAL_FINANCIAL_SCOPE'],
    'scope_checkpoints' => ['COMMERCIAL_FINANCIAL_SCOPE' => 'PURCHASING', 'REQUEST_SCOPE' => 'PENDING_AREA_HEAD'],
    'pdf_stages'        => ['PENDING_FINANCE', 'PENDING_AREA_HEAD'],
    'quote_states'      => ['PURCHASING', 'PENDING_FINANCE', 'APPROVED'],
    'amend_states'      => ['PURCHASING', 'PENDING_FINANCE', 'APPROVED'],
];
$polA = ApprovalPolicy::fromArray($polRaw);
$polRaw2 = $polRaw;
$polRaw2['pdf_stages'] = ['PENDING_AREA_HEAD', 'PENDING_FINANCE', 'PENDING_FINANCE']; // otro orden + duplicado
ok('mismo contenido (orden/duplicados irrelevantes) ⇒ MISMO hash (misma versión)', ApprovalPolicy::hash($polA->toArray()) === ApprovalPolicy::hash(ApprovalPolicy::fromArray($polRaw2)->toArray()));
$polRaw3 = $polRaw;
$polRaw3['pdf_stages'] = [];
ok('contenido distinto ⇒ hash distinto (versión nueva)', ApprovalPolicy::hash($polA->toArray()) !== ApprovalPolicy::hash(ApprovalPolicy::fromArray($polRaw3)->toArray()));
ok('prioridad: REQUEST_SCOPE antes que COMMERCIAL', array_keys($polA->orderedCheckpoints()) === ['REQUEST_SCOPE', 'COMMERCIAL_FINANCIAL_SCOPE']);
ok('estado desconocido en quote_states → fail-closed', throws(fn () => ApprovalPolicy::fromArray(['quote_states' => ['NOPE']] + $polRaw)));
ok('pdf_stages fuera de etapas de aprobación → fail-closed', throws(fn () => ApprovalPolicy::fromArray(['pdf_stages' => ['APPROVED']] + $polRaw)));
ok('checkpoint posterior a su etapa → fail-closed', throws(fn () => ApprovalPolicy::fromArray(['scope_checkpoints' => ['REQUEST_SCOPE' => 'PENDING_FINANCE', 'COMMERCIAL_FINANCIAL_SCOPE' => 'PURCHASING']] + $polRaw)));
ok('clave faltante → fail-closed', throws(fn () => ApprovalPolicy::fromArray(array_diff_key($polRaw, ['amend_states' => 1]))));

echo "== P2D-2 · evidenceRefMatches (las TRES claves + scope + contenido, exactas) ==\n";
$lrow = ['scope_key' => 'REQUEST_SCOPE', 'document_version' => 3, 'document_versions_id' => 41, 'content_sha256' => str_repeat('a', 64), 'payload_sha256' => str_repeat('b', 64)];
$goodRef = ['document_versions_id' => 41, 'document_version' => 3, 'content_sha256' => str_repeat('a', 64)];
ok('ref exacta ⇒ íntegra', PurchasingWorkflow::evidenceRefMatches($goodRef, $lrow, 'REQUEST_SCOPE', str_repeat('b', 64)));
ok('content_sha256 alterado ⇒ NO', !PurchasingWorkflow::evidenceRefMatches(['content_sha256' => str_repeat('f', 64)] + $goodRef, $lrow, 'REQUEST_SCOPE', str_repeat('b', 64)));
ok('document_versions_id ajeno ⇒ NO', !PurchasingWorkflow::evidenceRefMatches(['document_versions_id' => 42] + $goodRef, $lrow, 'REQUEST_SCOPE', str_repeat('b', 64)));
ok('document_version distinto ⇒ NO', !PurchasingWorkflow::evidenceRefMatches(['document_version' => 4] + $goodRef, $lrow, 'REQUEST_SCOPE', str_repeat('b', 64)));
ok('ref incompleta (falta una clave) ⇒ NO', !PurchasingWorkflow::evidenceRefMatches(array_diff_key($goodRef, ['content_sha256' => 1]), $lrow, 'REQUEST_SCOPE', str_repeat('b', 64)));
ok('tipos inválidos ("41" string) ⇒ NO', !PurchasingWorkflow::evidenceRefMatches(['document_versions_id' => '41'] + $goodRef, $lrow, 'REQUEST_SCOPE', str_repeat('b', 64)));
ok('fila de OTRO scope ⇒ NO', !PurchasingWorkflow::evidenceRefMatches($goodRef, $lrow, 'COMMERCIAL_FINANCIAL_SCOPE', str_repeat('b', 64)));
ok('contenido actual distinto ⇒ NO', !PurchasingWorkflow::evidenceRefMatches($goodRef, $lrow, 'REQUEST_SCOPE', str_repeat('c', 64)));
ok('sin fila en el ledger propio ⇒ NO', !PurchasingWorkflow::evidenceRefMatches($goodRef, null, 'REQUEST_SCOPE', str_repeat('b', 64)));
ok('ref no-array ⇒ NO', !PurchasingWorkflow::evidenceRefMatches(null, $lrow, 'REQUEST_SCOPE', str_repeat('b', 64)));

echo "== P2D-2 · planBatch (reconciliación por lotes con cursor + wrap-around) ==\n";
ok('lote completo tras el cursor ⇒ avanza al último', ApprovalOrchestrator::planBatch([11, 12], [], 10) === ['ids' => [11, 12], 'cursor' => 12, 'wrapped' => false]);
ok('fin de la lista ⇒ wrap-around desde el inicio', ApprovalOrchestrator::planBatch([19], [3, 5], 18) === ['ids' => [19, 3, 5], 'cursor' => 5, 'wrapped' => true]);
ok('nada que revisar ⇒ cursor 0', ApprovalOrchestrator::planBatch([], [], 7) === ['ids' => [], 'cursor' => 0, 'wrapped' => false]);
// Simulación: 7 ids, límite 3 ⇒ en ≤ 3 corridas se revisan TODOS (sin starvation del mayor).
$all = [2, 4, 6, 8, 10, 12, 14];
$cur = 0;
$visited = [];
for ($run = 0; $run < 3; $run++) {
    $after = array_slice(array_values(array_filter($all, fn ($i) => $i > $cur)), 0, 3);
    $start = count($after) < 3 ? array_slice(array_values(array_filter($all, fn ($i) => $i <= $cur)), 0, 3 - count($after)) : [];
    $plan = ApprovalOrchestrator::planBatch($after, $start, $cur);
    $visited = array_merge($visited, $plan['ids']);
    $cur = $plan['cursor'];
}
ok('7 solicitudes, límite 3: en 3 corridas se revisan TODAS (incluida la de mayor id)', array_diff($all, $visited) === []);

echo "== P2D-3 · definición: fase de compra/recepción (misma máquina de estados) ==\n";
$spec3 = PurchasingWorkflow::spec('cp_test', 'X\\Request', $cfg);
$codes = array_column($spec3['states'], 'kind', 'code');
ok('estados IN_PURCHASE / PARTIALLY_RECEIVED / RECEIVED son INTERMEDIOS (RECEIVED no cierra la instancia)',
    ($codes['IN_PURCHASE'] ?? '') === 'intermediate' && ($codes['PARTIALLY_RECEIVED'] ?? '') === 'intermediate' && ($codes['RECEIVED'] ?? '') === 'intermediate');
$tr = static fn (string $from, string $action): array => array_values(array_filter($spec3['transitions'], static fn ($t) => $t['from'] === $from && $t['action'] === $action));
$sp = $tr('APPROVED', 'start_purchase');
ok('APPROVED —start_purchase→ IN_PURCHASE con condición purchase_bound (no forzable por HTTP)',
    count($sp) === 1 && $sp[0]['to'] === 'IN_PURCHASE' && $sp[0]['condition'] === PurchasingWorkflow::PURCHASE_CONDITION && !isset($sp[0]['steps']));
$rp = $tr('IN_PURCHASE', 'receive_partial');
$rc1 = $tr('IN_PURCHASE', 'receive_complete');
$rc2 = $tr('PARTIALLY_RECEIVED', 'receive_complete');
ok('receive_partial / receive_complete (×2) con condición receipt_bound',
    count($rp) === 1 && $rp[0]['to'] === 'PARTIALLY_RECEIVED' && count($rc1) === 1 && $rc1[0]['to'] === 'RECEIVED' && count($rc2) === 1 && $rc2[0]['to'] === 'RECEIVED'
    && $rp[0]['condition'] === PurchasingWorkflow::RECEIPT_CONDITION && $rc2[0]['condition'] === PurchasingWorkflow::RECEIPT_CONDITION);
ok('las aprobaciones siguen siendo 3 (la fase de compra no agrega etapas de aprobación)', count(array_filter($spec3['transitions'], static fn ($t) => $t['action'] === 'approve')) === 3);
ok('stageIndex: IN_PURCHASE 4, PARTIALLY_RECEIVED 5, RECEIVED 6', PurchasingWorkflow::stageIndex('IN_PURCHASE') === 4
    && PurchasingWorkflow::stageIndex('PARTIALLY_RECEIVED') === 5 && PurchasingWorkflow::stageIndex('RECEIVED') === 6);
ok('entrar a la fase de compra NO reinicia aprobaciones (ningún checkpoint)', !PurchasingWorkflow::resetsScope('IN_PURCHASE', 'PENDING_AREA_HEAD')
    && !PurchasingWorkflow::resetsScope('PARTIALLY_RECEIVED', 'PURCHASING') && !PurchasingWorkflow::resetsScope('RECEIVED', 'PURCHASING'));
$rows3 = [
    ['id' => 1, 'event' => 'started', 'to_code' => 'DRAFT'],
    ['id' => 2, 'event' => 'transitioned', 'from_code' => 'DRAFT', 'to_code' => 'PENDING_AREA_HEAD'],
    ['id' => 3, 'event' => 'transitioned', 'from_code' => 'PENDING_AREA_HEAD', 'to_code' => 'PURCHASING'],
    ['id' => 4, 'event' => 'decision_recorded', 'from_code' => 'PENDING_AREA_HEAD', 'meta' => ['decision' => 'approved']],
    ['id' => 5, 'event' => 'transitioned', 'from_code' => 'APPROVED', 'to_code' => 'IN_PURCHASE'],
    ['id' => 6, 'event' => 'transitioned', 'from_code' => 'IN_PURCHASE', 'to_code' => 'RECEIVED'],
];
ok('aprobaciones siguen VIVAS tras IN_PURCHASE/RECEIVED (la integridad post-compra no es vacua)',
    count(PurchasingWorkflow::liveDecisions($rows3, 'REQUEST_SCOPE', 'PENDING_AREA_HEAD', ['PENDING_AREA_HEAD' => 'REQUEST_SCOPE'])) === 1);

echo "== P2D-3 · receivingTarget / syncPath (contadores físicos → estado del motor) ==\n";
ok('0 recibido ⇒ IN_PURCHASE', PurchasingWorkflow::receivingTarget([['ordered_qty' => 10, 'received_qty' => 0], ['ordered_qty' => 3, 'received_qty' => 0]]) === 'IN_PURCHASE');
ok('0 < recibido < ordenado ⇒ PARTIALLY_RECEIVED', PurchasingWorkflow::receivingTarget([['ordered_qty' => 10, 'received_qty' => 4]]) === 'PARTIALLY_RECEIVED');
ok('una línea completa y otra no ⇒ PARTIALLY_RECEIVED', PurchasingWorkflow::receivingTarget([['ordered_qty' => 10, 'received_qty' => 10], ['ordered_qty' => 3, 'received_qty' => 0]]) === 'PARTIALLY_RECEIVED');
ok('todas completas ⇒ RECEIVED', PurchasingWorkflow::receivingTarget([['ordered_qty' => 10, 'received_qty' => 10], ['ordered_qty' => 3, 'received_qty' => 3]]) === 'RECEIVED');
ok('contadores imposibles ⇒ fail-closed (sin líneas / ordenado 0 / recibido > ordenado / negativo)',
    throws(fn () => PurchasingWorkflow::receivingTarget([])) && throws(fn () => PurchasingWorkflow::receivingTarget([['ordered_qty' => 0, 'received_qty' => 0]]))
    && throws(fn () => PurchasingWorkflow::receivingTarget([['ordered_qty' => 2, 'received_qty' => 3]])) && throws(fn () => PurchasingWorkflow::receivingTarget([['ordered_qty' => 2, 'received_qty' => -1]])));
ok('syncPath: convergido ⇒ []', PurchasingWorkflow::syncPath('PARTIALLY_RECEIVED', 'PARTIALLY_RECEIVED') === []);
ok('syncPath: IN_PURCHASE → PARTIALLY_RECEIVED / RECEIVED', PurchasingWorkflow::syncPath('IN_PURCHASE', 'PARTIALLY_RECEIVED') === ['receive_partial']
    && PurchasingWorkflow::syncPath('IN_PURCHASE', 'RECEIVED') === ['receive_complete'] && PurchasingWorkflow::syncPath('PARTIALLY_RECEIVED', 'RECEIVED') === ['receive_complete']);
ok('syncPath: APPROVED (compra iniciada, motor sin mover) ⇒ start_purchase primero', PurchasingWorkflow::syncPath('APPROVED', 'IN_PURCHASE') === ['start_purchase']
    && PurchasingWorkflow::syncPath('APPROVED', 'PARTIALLY_RECEIVED') === ['start_purchase', 'receive_partial']);
ok('syncPath: motor ADELANTADO o fuera de la fase ⇒ null (anomalía; jamás retrocede)', PurchasingWorkflow::syncPath('RECEIVED', 'PARTIALLY_RECEIVED') === null
    && PurchasingWorkflow::syncPath('PARTIALLY_RECEIVED', 'IN_PURCHASE') === null && PurchasingWorkflow::syncPath('PURCHASING', 'IN_PURCHASE') === null
    && PurchasingWorkflow::syncPath('IN_PURCHASE', 'APPROVED') === null);

echo "== P2D-3 · política de aprobación: congelamiento en la fase de compra ==\n";
$pol3 = $polRaw;
ok('quote_states con IN_PURCHASE ⇒ fail-closed', throws(fn () => ApprovalPolicy::fromArray(['quote_states' => ['PURCHASING', 'IN_PURCHASE']] + $pol3)));
ok('amend_states con RECEIVED ⇒ fail-closed', throws(fn () => ApprovalPolicy::fromArray(['amend_states' => ['RECEIVED']] + $pol3)));
ok('política P2D-2 existente sigue válida', !throws(fn () => ApprovalPolicy::fromArray(['quote_states' => ['PURCHASING', 'PENDING_FINANCE', 'APPROVED'], 'amend_states' => ['PURCHASING', 'PENDING_FINANCE', 'APPROVED']] + $pol3)));

echo "== P2D-3 · Decimal: producto y división exactos de enteros grandes ==\n";
ok('mulStr 99999999999999999999² exacto', Decimal::mulStr('99999999999999999999', '99999999999999999999') === '9999999999999999999800000000000000000001');
ok('divModStr exacto', Decimal::divModStr('123456789012345678901234567890', '97') === ['1272750402189130710322005854', '52']);
ok('divModStr por cero / entrada no numérica ⇒ fail-closed', throws(fn () => Decimal::divModStr('10', '0')) && throws(fn () => Decimal::mulStr('1.5', '2')) && throws(fn () => Decimal::divModStr('-1', '2')));
$okRand = true;
mt_srand(20260925);
for ($i = 0; $i < 400; $i++) {
    $a = mt_rand(0, 2000000000);
    $b = mt_rand(1, 2000000000);
    [$q, $r] = Decimal::divModStr((string) ($a * $b + $a % $b), (string) $b);
    $okRand = $okRand && Decimal::mulStr((string) $a, (string) $b) === (string) ($a * $b)
        && $q === (string) intdiv($a * $b + $a % $b, $b) && $r === (string) (($a * $b + $a % $b) % $b);
}
ok('400 casos aleatorios: mulStr/divModStr == aritmética entera nativa', $okRand);

echo "== P2D-3 · CostPolicy (pinneable, hash determinista) ==\n";
$cpAll = CostPolicy::fromArray(['include_discounts' => 1, 'include_taxes' => '1', 'include_freight' => true]);
$cpNone = CostPolicy::fromArray(['include_discounts' => '0', 'include_taxes' => '0', 'include_freight' => '0']);
ok('canónica (claves ordenadas) + hash estable', $cpAll->canonical() === '{"allocation":"line_value_largest_remainder","include_discounts":1,"include_freight":1,"include_taxes":1,"schema":1,"unit_split":"floor_remainder_to_first_units"}'
    && $cpAll->hash() === hash('sha256', $cpAll->canonical()) && $cpAll->hash() !== $cpNone->hash());
ok('mismo contenido ⇒ mismo hash (orden de entrada irrelevante)', CostPolicy::fromArray(['include_freight' => 1, 'include_taxes' => 1, 'include_discounts' => 1])->hash() === $cpAll->hash());
ok('flag inválido / faltante / método no soportado ⇒ fail-closed', throws(fn () => CostPolicy::fromArray(['include_discounts' => 'yes', 'include_taxes' => 0, 'include_freight' => 0]))
    && throws(fn () => CostPolicy::fromArray(['include_taxes' => 0, 'include_freight' => 0]))
    && throws(fn () => CostPolicy::fromArray(['include_discounts' => 0, 'include_taxes' => 0, 'include_freight' => 0, 'allocation' => 'total_div_qty'])));

echo "== P2D-3 · CostAllocator: costo atribuible EXACTO por línea y por unidad ==\n";
$lines3 = [['line_id' => 1, 'quantity' => '10', 'final_unit_price' => '1400'], ['line_id' => 2, 'quantity' => '3', 'final_unit_price' => '2300']];
$al = CostAllocator::allocate($lines3, '300', '550', '100', 'PYG', $cpAll);
ok('prorrateo por valor de línea (mayor resto): impuestos 368/182, flete 67/33, descuentos 201/99',
    [$al[0]['taxes'], $al[1]['taxes'], $al[0]['freight'], $al[1]['freight'], $al[0]['discounts'], $al[1]['discounts']] === ['368', '182', '67', '33', '201', '99']);
ok('costo de línea 14234 / 7016 y Σ = total de la cotización (21250)', $al[0]['line_cost'] === '14234' && $al[1]['line_cost'] === '7016' && (int) $al[0]['line_cost'] + (int) $al[1]['line_cost'] === 20900 + 550 + 100 - 300);
$u1 = array_map(static fn ($k) => CostAllocator::unitCost('14234', 10, $k, 'PYG'), range(1, 10));
ok('unidades: 1424 ×4 + 1423 ×6 (resto a las primeras; nunca total ÷ cantidad)', $u1 === array_merge(array_fill(0, 4, '1424'), array_fill(0, 6, '1423')));
ok('unidades línea 2: 2339, 2339, 2338', array_map(static fn ($k) => CostAllocator::unitCost('7016', 3, $k, 'PYG'), [1, 2, 3]) === ['2339', '2339', '2338']);
$none = CostAllocator::allocate($lines3, '300', '550', '100', 'PYG', $cpNone);
ok('política por DEFECTO (sin ajustes): costo = final_unit_price × cantidad (sin prorrateo)', $none[0]['line_cost'] === '14000' && $none[1]['line_cost'] === '6900' && $none[0]['taxes'] === '0');
ok('USD escala 2: 11.00 entre 3 ⇒ 3.67, 3.67, 3.66', array_map(static fn ($k) => CostAllocator::unitCost('11.00', 3, $k, 'USD'), [1, 2, 3]) === ['3.67', '3.67', '3.66']);
ok('descuento mayor que el valor de la línea ⇒ fail-closed', throws(fn () => CostAllocator::allocate([['line_id' => 1, 'quantity' => '1', 'final_unit_price' => '10']], '50', '0', '0', 'PYG', $cpAll)));
ok('líneas de valor 0 ⇒ pesos = cantidad (sin división por cero)', CostAllocator::allocate([['line_id' => 1, 'quantity' => '1', 'final_unit_price' => '0'], ['line_id' => 2, 'quantity' => '3', 'final_unit_price' => '0']], '0', '0', '4', 'PYG', $cpAll)[1]['freight'] === '3');
ok('empate de restos ⇒ gana la línea que aparece antes (determinista)', CostAllocator::largestRemainder('1', [5 => '7', 9 => '7']) === [5 => '1', 9 => '0']);
ok('ordinal fuera de rango / cantidad inválida / PYG con decimales ⇒ fail-closed', throws(fn () => CostAllocator::unitCost('100', 3, 4, 'PYG'))
    && throws(fn () => CostAllocator::unitCost('100', 0, 1, 'PYG')) && throws(fn () => CostAllocator::allocate([['line_id' => 1, 'quantity' => '2.5', 'final_unit_price' => '1']], '0', '0', '0', 'PYG', $cpAll))
    && throws(fn () => CostAllocator::allocate([['line_id' => 1, 'quantity' => '1', 'final_unit_price' => '1.5']], '0', '0', '0', 'PYG', $cpAll)));
$propOk = true;
mt_srand(3);
for ($i = 0; $i < 150; $i++) {
    $n = mt_rand(1, 5);
    $ls = [];
    $sum = 0;
    for ($j = 0; $j < $n; $j++) {
        $qty = mt_rand(1, 40);
        $price = mt_rand(0, 900000);
        $ls[] = ['line_id' => $j + 1, 'quantity' => (string) $qty, 'final_unit_price' => (string) $price];
        $sum += $qty * $price;
    }
    $tx = mt_rand(0, 99999);
    $fr = mt_rand(0, 9999);
    $ds = mt_rand(0, min($sum, 99999));
    $res = CostAllocator::allocate($ls, (string) $ds, (string) $tx, (string) $fr, 'PYG', $cpAll);
    $tot = 0;
    foreach ($res as $k => $r) {
        $units = 0;
        for ($o = 1; $o <= (int) $ls[$k]['quantity']; $o++) {
            $units += (int) CostAllocator::unitCost($r['line_cost'], (int) $ls[$k]['quantity'], $o, 'PYG');
        }
        $propOk = $propOk && $units === (int) $r['line_cost'];
        $tot += (int) $r['line_cost'];
    }
    $propOk = $propOk && $tot === $sum + $tx + $fr - $ds;
}
ok('150 compras aleatorias: Σ unidades = costo de línea y Σ líneas = subtotal + impuestos + flete − descuentos (exacto)', $propOk);

echo "== P2D-3 · HandoffPayload (versionado, inmutable, hash) ==\n";
$hp = [
    'receipt_unit_uuid' => '3f2b8c1e-9a4d-4e6f-8b2a-1c3d5e7f9a0b', 'request_id' => 12, 'request_number' => 'REQUEST-2026-0007',
    'item_id' => 55, 'entity_id' => 0, 'serial' => 'SN-1', 'description' => 'Notebook', 'category' => 'HW', 'supplier_id' => 9,
    'currency' => 'PYG', 'unit_cost' => '1424', 'received_at' => '2026-09-25T10:00:00-03:00', 'correlation_id' => 'corr-1',
];
$pl = HandoffPayload::build($hp);
$keysPl = array_keys($pl);
$keysExp = HandoffPayload::KEYS;
sort($keysExp);
ok('schema v1 con las claves EXACTAS (ordenadas) y costo como string', $keysPl === $keysExp && $pl['schema_version'] === 1 && $pl['unit_cost'] === '1424');
$json = HandoffPayload::canonical($pl);
ok('canónico + hash; verify() lo reconoce', HandoffPayload::hash($pl) === hash('sha256', $json) && HandoffPayload::verify($json, hash('sha256', $json)) === $pl);
$tampered = str_replace('"unit_cost":"1424"', '"unit_cost":"1"', $json);
ok('payload alterado ⇒ verify() null (hash original)', HandoffPayload::verify($tampered, hash('sha256', $json)) === null);
$pretty = json_encode($pl, JSON_PRETTY_PRINT);
ok('JSON NO canónico (aunque su hash coincida) ⇒ null', HandoffPayload::verify($pretty, hash('sha256', $pretty)) === null);
ok('float / clave extra / UUID inválido / serial vacío / PYG con decimales ⇒ fail-closed',
    throws(fn () => HandoffPayload::build(['unit_cost' => 1424.0] + $hp)) && throws(fn () => HandoffPayload::validate(['x' => 1] + $pl))
    && throws(fn () => HandoffPayload::build(['receipt_unit_uuid' => 'not-a-uuid'] + $hp)) && throws(fn () => HandoffPayload::build(['serial' => ''] + $hp))
    && throws(fn () => HandoffPayload::build(['unit_cost' => '1.5'] + $hp)));
ok('serial ausente permitido (null)', HandoffPayload::build(['serial' => null] + $hp)['serial'] === null);

echo "== P2D-3 · identidad canónica: UUID v4 (CSPRNG) ==\n";
$uu = [];
for ($i = 0; $i < 3000; $i++) {
    $uu[] = ReceivingService::uuidV4();
}
ok('3000 UUID v4 RFC 4122 válidos y todos distintos', count(array_unique($uu)) === 3000
    && array_reduce($uu, static fn (bool $c, string $u): bool => $c && preg_match(HandoffPayload::UUID_PATTERN, $u) === 1, true));

echo "== P2D-3 · last_error sin secretos ==\n";
$san = PurchasingIntegrationApi::sanitizeError("fail password=hunter2 token: abc Authorization: Bearer eyJ.x.y https://u:pw@h/x\x01" . str_repeat('z', 400));
ok('redacta password/token/Bearer/credenciales en URL, quita controles y acota a 250',
    !str_contains($san, 'hunter2') && !str_contains($san, ' abc') && !str_contains($san, 'eyJ') && !str_contains($san, 'u:pw') && !str_contains($san, "\x01") && mb_strlen($san) <= 250);

echo "== P2D-3 · límites: sin Snipe-IT / HTTP / activos / Infocom / companyqr / float en el código nuevo ==\n";
$scan = [
    dirname(__DIR__, 2) . '/src/Api/PurchasingIntegrationApi.php',
    $svc . 'ReceivingService.php', $svc . 'ReceivingSync.php', $svc . 'CostAllocator.php', $svc . 'CostPolicy.php',
    $svc . 'CostPolicyStore.php', $svc . 'HandoffPayload.php',
];
$forbidden = ['curl_', 'guzzle', 'fsockopen', 'stream_socket_client', 'http://', 'https://', 'snipe', 'infocom', 'computer', 'companyqr', 'companyintegrations', '(float)', 'floatval', 'round(', 'bcdiv', ' / '];
$hits = [];
foreach ($scan as $f) {
    $code = '';
    foreach (token_get_all((string) file_get_contents($f)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    foreach ($forbidden as $needle) {
        if (str_contains(strtolower($code), $needle)) {
            $hits[] = basename($f) . ':' . $needle;
        }
    }
}
ok('código (sin comentarios) libre de clientes HTTP/Snipe/activos/Infocom/companyqr/float/división' . ($hits !== [] ? ' — ' . implode(', ', $hits) : ''), $hits === []);

echo "\n" . ($fail > 0
    ? "\033[31mUNIT FAIL: {$fail}/{$total}\033[0m"
    : "\033[32mUNIT OK: {$total}/{$total}\033[0m") . "\n";

exit($fail > 0 ? 1 : 0);
