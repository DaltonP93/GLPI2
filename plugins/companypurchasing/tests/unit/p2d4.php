<?php

/**
 * Tests UNITARIOS puros de P2D-4 (entrega, cierre, UI, notificaciones, métricas). Incluido por `run.php`
 * (mismas funciones `ok()` / `throws()`; sin bootstrap de GLPI).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use GlpiPlugin\Companypurchasing\Service\ConfigForm;
use GlpiPlugin\Companypurchasing\Service\DeliveryException;
use GlpiPlugin\Companypurchasing\Service\DeliveryRules;
use GlpiPlugin\Companypurchasing\Service\MetricsMath;
use GlpiPlugin\Companypurchasing\Service\NotificationRules;
use GlpiPlugin\Companypurchasing\Service\NotificationSeeder;
use GlpiPlugin\Companypurchasing\Service\PurchasingWorkflow;
use GlpiPlugin\Companypurchasing\Service\SafeError;

$root = dirname(__DIR__, 2);

echo "== P2D-4 · definición: RECEIVED → DELIVERED → CLOSED (bound), sin PARTIALLY_DELIVERED ==\n";
$spec = PurchasingWorkflow::spec('cpur_test', 'X', ['groups' => ['PENDING_AREA_HEAD' => 1, 'PURCHASING' => 2, 'PENDING_FINANCE' => 3]]);
$kinds = [];
foreach ($spec['states'] as $st) {
    $kinds[$st['code']] = $st['kind'];
}
ok('RECEIVED y DELIVERED intermedios, CLOSED final; no existe PARTIALLY_DELIVERED',
    ($kinds['RECEIVED'] ?? '') === 'intermediate' && ($kinds['DELIVERED'] ?? '') === 'intermediate' && ($kinds['CLOSED'] ?? '') === 'final'
    && !isset($kinds['PARTIALLY_DELIVERED']));
$tr = [];
foreach ($spec['transitions'] as $t) {
    $tr[$t['from'] . '>' . $t['action']] = $t;
}
ok('deliver_complete RECEIVED→DELIVERED con condición delivery_bound=1, sin paso de aprobación ni derecho del motor',
    ($tr['RECEIVED>deliver_complete']['to'] ?? '') === 'DELIVERED' && ($tr['RECEIVED>deliver_complete']['condition'] ?? null) === ['field' => 'delivery_bound', 'op' => 'eq', 'value' => 1]
    && !isset($tr['RECEIVED>deliver_complete']['steps']) && !isset($tr['RECEIVED>deliver_complete']['required_right']));
ok('close DELIVERED→CLOSED con condición close_bound=1 (bound)',
    ($tr['DELIVERED>close']['to'] ?? '') === 'CLOSED' && ($tr['DELIVERED>close']['condition'] ?? null) === ['field' => 'close_bound', 'op' => 'eq', 'value' => 1]);
ok('no hay atajos: ni RECEIVED→close ni PARTIALLY_RECEIVED→deliver_complete',
    !isset($tr['RECEIVED>close']) && !isset($tr['PARTIALLY_RECEIVED>deliver_complete']) && !isset($tr['IN_PURCHASE>deliver_complete']));
ok('entrar a DELIVERED/CLOSED NO reinicia aprobaciones (cerrar no invalida evidencia)',
    !PurchasingWorkflow::resetsScope('DELIVERED', 'PENDING_AREA_HEAD') && !PurchasingWorkflow::resetsScope('CLOSED', 'PURCHASING'));
ok('política: quote/amend no admiten estados de entrega/cierre (contenido congelado)',
    in_array('DELIVERED', PurchasingWorkflow::PURCHASE_STATES, true) && in_array('CLOSED', PurchasingWorkflow::PURCHASE_STATES, true));

echo "== P2D-4 · physicalTarget / syncPath (hechos físicos → estado del motor) ==\n";
$full = [['ordered_qty' => 10, 'received_qty' => 10], ['ordered_qty' => 3, 'received_qty' => 3]];
ok('nada entregado ⇒ objetivo de recepción (RECEIVED / PARTIALLY_RECEIVED)', PurchasingWorkflow::physicalTarget($full, 13, 0) === 'RECEIVED'
    && PurchasingWorkflow::physicalTarget([['ordered_qty' => 10, 'received_qty' => 4]], 4, 0) === 'PARTIALLY_RECEIVED');
ok('entrega PARCIAL (4 de 13) ⇒ sigue RECEIVED (no PARTIALLY_DELIVERED)', PurchasingWorkflow::physicalTarget($full, 13, 4) === 'RECEIVED');
ok('TODAS entregadas (13 de 13) ⇒ DELIVERED', PurchasingWorkflow::physicalTarget($full, 13, 13) === 'DELIVERED');
ok('fail-closed: entregadas > total, entrega sin recepción completa, unidades ≠ recibido',
    throws(fn () => PurchasingWorkflow::physicalTarget($full, 13, 14))
    && throws(fn () => PurchasingWorkflow::physicalTarget([['ordered_qty' => 10, 'received_qty' => 4]], 4, 1))
    && throws(fn () => PurchasingWorkflow::physicalTarget($full, 12, 3)));
ok('syncPath: RECEIVED → DELIVERED = [deliver_complete]', PurchasingWorkflow::syncPath('RECEIVED', 'DELIVERED') === ['deliver_complete']);
ok('syncPath: recepción final aún sin sincronizar ⇒ [receive_complete, deliver_complete]',
    PurchasingWorkflow::syncPath('PARTIALLY_RECEIVED', 'DELIVERED') === ['receive_complete', 'deliver_complete']
    && PurchasingWorkflow::syncPath('IN_PURCHASE', 'DELIVERED') === ['receive_complete', 'deliver_complete']);
ok('syncPath: CLOSED con todo entregado ⇒ convergido; CLOSED nunca es objetivo; jamás retrocede',
    PurchasingWorkflow::syncPath('CLOSED', 'DELIVERED') === [] && PurchasingWorkflow::syncPath('DELIVERED', 'CLOSED') === null
    && PurchasingWorkflow::syncPath('DELIVERED', 'RECEIVED') === null && PurchasingWorkflow::syncPath('CLOSED', 'RECEIVED') === null);
ok('etapas (fase de negocio) derivadas del código del motor', PurchasingWorkflow::phaseOf('RECEIVED') === 'reception'
    && PurchasingWorkflow::phaseOf('DELIVERED') === 'delivery' && PurchasingWorkflow::phaseOf('CLOSED') === 'finished'
    && PurchasingWorkflow::phaseOf('PENDING_FINANCE') === 'approval' && PurchasingWorkflow::phaseOf('IN_PURCHASE') === 'purchase');

echo "== P2D-4 · DeliveryRules (identidad, idempotencia, gate de inventario, cierre) ==\n";
$u1 = '3f8e2a1c-4b5d-4e6f-8a7b-9c0d1e2f3a4b';
$u2 = '0a1b2c3d-4e5f-4a6b-9c8d-7e6f5a4b3c2d';
ok('uuids normalizados: minúsculas, orden estable', DeliveryRules::normalizeUuids([strtoupper($u1), $u2], 10) === [$u2, $u1]);
ok('uuids: vacío, repetido, inválido o > máximo ⇒ rechazado', throws(fn () => DeliveryRules::normalizeUuids([], 10))
    && throws(fn () => DeliveryRules::normalizeUuids([$u1, $u1], 10)) && throws(fn () => DeliveryRules::normalizeUuids(['serial-123'], 10))
    && throws(fn () => DeliveryRules::normalizeUuids([$u1, $u2], 1)) && throws(fn () => DeliveryRules::normalizeUuids('x', 10)));
$h = DeliveryRules::inputHash(7, [$u1, $u2], 5, 'n');
ok('inputHash independiente del orden; cambia con destinatario/notas/unidades/solicitud',
    $h === DeliveryRules::inputHash(7, [$u2, $u1], 5, 'n') && $h !== DeliveryRules::inputHash(7, [$u1, $u2], 6, 'n')
    && $h !== DeliveryRules::inputHash(7, [$u1, $u2], 5, 'm') && $h !== DeliveryRules::inputHash(7, [$u1], 5, 'n')
    && $h !== DeliveryRules::inputHash(8, [$u1, $u2], 5, 'n'));
ok('gate: no inventariable ⇒ entregable SIN outbox', DeliveryRules::gateStatus(false, null) === 'deliverable');
ok('gate: inventariable DONE ⇒ entregable', DeliveryRules::gateStatus(true, 'DONE') === 'deliverable');
ok('gate: PENDING/RETRY/sin fila ⇒ inventario pendiente; LEASED ⇒ integración en curso; ERROR/desconocido ⇒ error',
    DeliveryRules::gateStatus(true, 'PENDING') === 'inventory_pending' && DeliveryRules::gateStatus(true, 'RETRY') === 'inventory_pending'
    && DeliveryRules::gateStatus(true, null) === 'inventory_pending' && DeliveryRules::gateStatus(true, 'LEASED') === 'integration_in_progress'
    && DeliveryRules::gateStatus(true, 'ERROR') === 'integration_error' && DeliveryRules::gateStatus(true, 'WEIRD') === 'integration_error');
$lines = [['ordered_qty' => 2, 'received_qty' => 2]];
$doneUnits = [['physical_state' => 'DELIVERED', 'is_inventoriable' => 1, 'outbox_status' => 'DONE'], ['physical_state' => 'DELIVERED', 'is_inventoriable' => 0, 'outbox_status' => null]];
ok('cierre listo: DELIVERED + todo recibido/entregado + inventario DONE + integridad limpia ⇒ sin bloqueos',
    DeliveryRules::closeBlockers($lines, $doneUnits, 'DELIVERED', true, true) === []);
ok('cierre PREMATURO bloqueado: RECEIVED con una unidad sin entregar',
    DeliveryRules::closeBlockers($lines, [['physical_state' => 'RECEIVED', 'is_inventoriable' => 0, 'outbox_status' => null]] + $doneUnits, 'RECEIVED', true, true)
        === ['state_not_delivered', 'units_not_delivered']);
ok('cierre bloqueado por recepción pendiente, inventario no DONE, integridad sucia, versión anterior o sin unidades',
    in_array('receipt_pending', DeliveryRules::closeBlockers([['ordered_qty' => 2, 'received_qty' => 1]], $doneUnits, 'DELIVERED', true, true), true)
    && in_array('inventory_not_done', DeliveryRules::closeBlockers($lines, [['physical_state' => 'DELIVERED', 'is_inventoriable' => 1, 'outbox_status' => 'ERROR']], 'DELIVERED', true, true), true)
    && in_array('integrity_not_clean', DeliveryRules::closeBlockers($lines, $doneUnits, 'DELIVERED', true, false), true)
    && DeliveryRules::closeBlockers($lines, $doneUnits, 'DELIVERED', false, true) === ['legacy_definition']
    && in_array('no_units', DeliveryRules::closeBlockers($lines, [], 'DELIVERED', true, true), true));
ok('literales de DeliveryRules = UUID_PATTERN de HandoffPayload (identidad canónica compartida)', preg_match(\GlpiPlugin\Companypurchasing\Service\HandoffPayload::UUID_PATTERN, $u1) === 1);
$de = new DeliveryException(DeliveryException::UNIT_NOT_DELIVERABLE, 'x', ['units' => ['3f8e2a1c']]);
ok('DeliveryException tipada (kind + detalles no sensibles)', $de instanceof \RuntimeException && $de->kind === 'unit_not_deliverable' && $de->details['units'] === ['3f8e2a1c']);

echo "== P2D-4 · notificaciones NATIVAS: hechos del ledger → eventos ==\n";
ok('submit ⇒ enviada + pendiente de aprobación', NotificationRules::eventsFor('transitioned', 'DRAFT', 'PENDING_AREA_HEAD', 'submit') === ['request_submitted', 'approval_pending']);
ok('approve a la etapa siguiente ⇒ pendiente de aprobación', NotificationRules::eventsFor('transitioned', 'PENDING_AREA_HEAD', 'PURCHASING', 'approve') === ['approval_pending']);
ok('approve final ⇒ aprobada; reject ⇒ rechazada; return ⇒ devuelta',
    NotificationRules::eventsFor('transitioned', 'PENDING_FINANCE', 'APPROVED', 'approve') === ['request_approved']
    && NotificationRules::eventsFor('transitioned', 'PURCHASING', 'REJECTED', 'reject') === ['request_rejected']
    && NotificationRules::eventsFor('transitioned', 'PENDING_AREA_HEAD', 'RETURNED', 'return') === ['request_returned']
    && NotificationRules::eventsFor('transitioned', 'PENDING_FINANCE', 'PURCHASING', 'return') === ['request_returned', 'approval_pending']);
ok('compra / recepción completa (+ lista para entrega) / entregada / cerrada',
    NotificationRules::eventsFor('transitioned', 'APPROVED', 'IN_PURCHASE', 'start_purchase') === ['purchase_started']
    && NotificationRules::eventsFor('transitioned', 'PARTIALLY_RECEIVED', 'RECEIVED', 'receive_complete') === ['receipt_completed', 'ready_for_delivery']
    && NotificationRules::eventsFor('transitioned', 'RECEIVED', 'DELIVERED', 'deliver_complete') === ['request_delivered']
    && NotificationRules::eventsFor('transitioned', 'DELIVERED', 'CLOSED', 'close') === ['request_closed']);
ok('sin notificación: recepción parcial, votos, quórum, invalidaciones, cancelación',
    NotificationRules::eventsFor('transitioned', 'IN_PURCHASE', 'PARTIALLY_RECEIVED', 'receive_partial') === []
    && NotificationRules::eventsFor('decision_recorded', 'PURCHASING', 'PURCHASING', 'approve') === []
    && NotificationRules::eventsFor('approval_invalidated', 'APPROVED', 'PURCHASING', '') === []
    && NotificationRules::eventsFor('transitioned', 'DRAFT', 'CANCELLED', 'cancel') === []);
ok('10 eventos mínimos con destinos por defecto (nunca personas: solicitante/aprobadores/destinatario)',
    count(NotificationRules::EVENTS) === 10 && array_keys(NotificationRules::DEFAULT_TARGETS) === NotificationRules::EVENTS
    && NotificationRules::DEFAULT_TARGETS['approval_pending'] === [NotificationRules::TARGET_APPROVERS]
    && in_array(NotificationRules::TARGET_RECIPIENT, NotificationRules::DEFAULT_TARGETS['request_delivered'], true));
ok('idempotencia durable por (hecho del ledger, evento)', NotificationRules::idempotencyKey(42, 'request_closed') === 'notify:42:request_closed');
ok('plantilla SIN textos de negocio hardcodeados: sólo etiquetas ##…##',
    trim((string) preg_replace('/##[a-z.]+##|[:\s]/', '', NotificationSeeder::TEMPLATE_TEXT . NotificationSeeder::TEMPLATE_SUBJECT)) === '');

echo "== P2D-4 · métricas: dinero exacto por moneda, sin float, duración por etapa ==\n";
$acc = [];
MetricsMath::addStored($acc, 'PYG', '1500.000000', 3);
MetricsMath::addStored($acc, 'PYG', '2000.000000', 2);
MetricsMath::addStored($acc, 'USD', '10.500000');
MetricsMath::addStored($acc, 'USD', '0.250000', 2);
$fmt = MetricsMath::format($acc, ['USD' => 2]);
ok('totales SEPARADOS por moneda (jamás se suman PYG + USD); PYG escala 0', $fmt === ['PYG' => '8500', 'USD' => '11.00']);
$big = [];
MetricsMath::addStored($big, 'PYG', '99999999999999.000000', 3);
ok('importes enormes exactos (sin overflow ni float)', MetricsMath::format($big)['PYG'] === '299999999999997');
$q = [];
MetricsMath::addStored($q, 'PYG', '1000.000000', 2);
MetricsMath::subStored($q, 'PYG', '300.000000');
ok('descuento exacto; resultado negativo ⇒ fail-closed', MetricsMath::format($q)['PYG'] === '1700' && throws(function (): void {
    $n = [];
    MetricsMath::addStored($n, 'PYG', '10.000000');
    MetricsMath::subStored($n, 'PYG', '11.000000');
}));
ok('importe/moneda inválidos ⇒ rechazado', throws(function (): void {
    $x = [];
    MetricsMath::addStored($x, 'PYG', '1e3');
}) && throws(function (): void {
    $x = [];
    MetricsMath::addStored($x, 'pyg!', '1');
}));
$hist = [
    ['instances_id' => 1, 'id' => 1, 'event' => 'started', 'to_code' => 'DRAFT', 'date' => '2026-10-01 08:00:00'],
    ['instances_id' => 1, 'id' => 2, 'event' => 'transitioned', 'to_code' => 'PENDING_AREA_HEAD', 'date' => '2026-10-01 10:00:00'],
    ['instances_id' => 1, 'id' => 3, 'event' => 'decision_recorded', 'to_code' => 'PENDING_AREA_HEAD', 'date' => '2026-10-01 11:00:00'],
    ['instances_id' => 1, 'id' => 4, 'event' => 'transitioned', 'to_code' => 'PURCHASING', 'date' => '2026-10-01 14:00:00'],
    ['instances_id' => 2, 'id' => 5, 'event' => 'started', 'to_code' => 'DRAFT', 'date' => '2026-10-01 09:00:00'],
    ['instances_id' => 2, 'id' => 6, 'event' => 'transitioned', 'to_code' => 'PENDING_AREA_HEAD', 'date' => '2026-10-01 09:00:00'],
];
$d = MetricsMath::stageDurations($hist, (int) strtotime('2026-10-01 16:00:00'));
ok('duración por etapa: entradas sucesivas por instancia; etapa abierta hasta "ahora"; votos no son entradas',
    $d['DRAFT'] === ['count' => 2, 'total' => 7200, 'max' => 7200] && $d['PENDING_AREA_HEAD'] === ['count' => 2, 'total' => 14400 + 25200, 'max' => 25200]
    && $d['PURCHASING'] === ['count' => 1, 'total' => 7200, 'max' => 7200]);
ok('horas enteras sin float (⌊promedio⌋)', MetricsMath::hours(['count' => 2, 'total' => 39600, 'max' => 25200]) === ['count' => 2, 'avg_hours' => 5, 'max_hours' => 7]
    && MetricsMath::hours(['count' => 0, 'total' => 0, 'max' => 0]) === ['count' => 0, 'avg_hours' => 0, 'max_hours' => 0]);
ok('presentación por idioma sin alterar el valor exacto', MetricsMath::display('1234567', 'es_ES') === '1.234.567'
    && MetricsMath::display('1234567.50', 'en_GB') === '1,234,567.50' && MetricsMath::display('999', 'es_ES') === '999'
    && MetricsMath::display('1234567.50', 'es_PY') === '1.234.567,50');

echo "== P2D-4 · configuración (UI) y errores seguros ==\n";
$cfg = ConfigForm::validate(['default_currency' => 'usd', 'quorum_area_head' => '2', 'sla_hours_finance' => '', 'approver_group_finance' => '7', 'cost_include_taxes' => '1']);
ok('config válida normalizada; checkbox ausente ⇒ "0"', $cfg['default_currency'] === 'USD' && $cfg['quorum_area_head'] === '2'
    && $cfg['sla_hours_finance'] === '' && $cfg['approver_group_finance'] === '7' && $cfg['cost_include_taxes'] === '1' && $cfg['cost_include_freight'] === '0');
ok('config inválida ⇒ rechazada (quórum 0, SLA negativo, moneda, grupo)', throws(fn () => ConfigForm::validate(['quorum_finance' => '0']))
    && throws(fn () => ConfigForm::validate(['sla_hours_area_head' => '-1'])) && throws(fn () => ConfigForm::validate(['default_currency' => 'PY']))
    && throws(fn () => ConfigForm::validate(['approver_group_area_head' => '1 OR 1=1'])));
ok('error técnico (SQL) ⇒ mensaje genérico; rechazo controlado ⇒ mensaje funcional',
    SafeError::userMessage(new \RuntimeException("MySQL query error: Duplicate entry 'x' for key 'unicity' in SQL query \"INSERT ...\"")) === 'The action could not be completed'
    && SafeError::userMessage(new DeliveryException(DeliveryException::INVENTORY_GATE, 'technical detail')) === 'One or more inventoriable units are not registered in inventory yet'
    && SafeError::userMessage(new \Error('boom')) === 'The action could not be completed');
ok('error de dominio: se muestra saneado (sin tokens)', !str_contains(SafeError::userMessage(new \RuntimeException('falló token=abc123 en la operación')), 'abc123'));

echo "== P2D-4 · seguridad web (guardas estáticas sobre controladores y plantillas) ==\n";
$read = static function (string $file): string {
    $code = '';
    foreach (token_get_all((string) file_get_contents($file)) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    return $code;
};
$actions = $read($root . '/src/Controller/ActionController.php');
$pages = $read($root . '/src/Controller/PageController.php');
preg_match_all('/#\[Route\(([^)]*)\)\]/', $actions, $ar);
preg_match_all('/#\[Route\(([^)]*)\)\]/', $pages, $pr);
ok('TODA ruta de ActionController es POST-only (ninguna mutación por GET)', count($ar[1]) >= 17
    && array_filter($ar[1], static fn (string $r): bool => !str_contains($r, "methods: ['POST']")) === []);
ok('TODA ruta de PageController es GET-only', count($pr[1]) === 7 && array_filter($pr[1], static fn (string $r): bool => !str_contains($r, "methods: ['GET']")) === []);
// Guarda 405: GLPI 11.0.8 responde 500 a un GET sobre una ruta POST-only de plugin (MethodNotAllowedException no
// traducida). MethodGuardController cubre EXACTAMENTE las rutas de ActionController (GET/HEAD ⇒ 405) sin tapar páginas.
$guard = $read($root . '/src/Controller/MethodGuardController.php');
$postOnly = preg_match("/POST_ONLY_PATHS = (.*?);/s", $actions, $pm) === 1
    ? implode('', array_map(static fn (string $lit): string => strtr($lit, ['\\\\' => '\\', "\\'" => "'"]), (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $pm[1], $lm) ? $lm[1] : [])))
    : '';
$pathOf = static fn (string $route): string => preg_match("/^'([^']+)'/", trim($route), $m) === 1
    ? ltrim((string) preg_replace('/\{\w+\}/', '7', $m[1]), '/') : '';
$matchesGuard = static fn (string $path): bool => $postOnly !== '' && preg_match('#^(?:' . $postOnly . ')$#', $path) === 1;
ok('🔒 guarda 405: cubre TODAS las rutas POST de ActionController y NINGUNA página GET',
    $postOnly !== '' && count($ar[1]) >= 17
    && array_filter($ar[1], static fn (string $r): bool => !$matchesGuard($pathOf($r))) === []
    && array_filter($pr[1], static fn (string $r): bool => $matchesGuard($pathOf($r))) === []
    && !$matchesGuard('request/7/edit') && !$matchesGuard('request/new') && !$matchesGuard('request/7') && !$matchesGuard('config'));
ok('🔒 guarda 405: sólo GET/HEAD, autenticada, usa POST_ONLY_PATHS y no hace nada más que responder 405',
    str_contains($guard, "methods: ['GET', 'HEAD']") && str_contains($guard, 'ActionController::POST_ONLY_PATHS')
    && str_contains($guard, '#[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]')
    && str_contains($guard, "throw new MethodNotAllowedHttpException(['POST'])")
    && preg_match('/new \\?GlpiPlugin|Service\\|doQuery|->request\(|Session::/', $guard) !== 1);
ok('toda acción exige sesión autenticada (SecurityStrategy) y responde con PRG (redirect)',
    substr_count($actions, '#[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]') === count($ar[1])
    && substr_count($pages, '#[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]') === count($pr[1])
    && preg_match_all('/return \$this->act\(/', $actions) === count($ar[1]));
ok('PageController no invoca operaciones que mutan',
    preg_match('/->(createDraft|updateDraft|addLine|updateLine|removeLine|submit|decide|createQuote|updateQuote|selectQuote|attachQuoteDocument|startPurchase|receive|deliver|closeRequest|publishDefinition)\(/', $pages) !== 1);
ok('los controladores no ejecutan SQL ni consultan tablas de otros plugins',
    preg_match('/doQuery|->request\(|->insert\(|->update\(|glpi_plugin_companyintegrations|glpi_plugin_companyworkflow/', $actions . $pages) !== 1);
$tpl = '';
foreach (glob($root . '/templates/{,parts/}*.twig', GLOB_BRACE) ?: [] as $f) {
    $tpl .= (string) file_get_contents($f);
}
preg_match_all('/<form\b[^>]*>/i', $tpl, $forms);
$posts = array_filter($forms[0], static fn (string $f): bool => stripos($f, 'method="post"') !== false);
ok('cada <form method="post"> de las plantillas incluye el CSRF NATIVO',
    count($posts) >= 15 && preg_match_all('/<form\b[^>]*method="post"[^>]*>.*?<\/form>/is', $tpl, $pf) === count($posts)
    && array_filter($pf[0], static fn (string $f): bool => !str_contains($f, "parts/csrf.html.twig")) === []);
ok('ninguna plantilla muta por GET: los formularios GET sólo filtran (requests/metrics)',
    count(array_filter($forms[0], static fn (string $f): bool => stripos($f, 'method="get"') !== false)) === 2);
ok('salida escapada: |raw sólo para dropdowns NATIVOS generados por GLPI',
    preg_match_all('/\|raw/', $tpl) === preg_match_all('/(dropdowns\.\w+|g\.dropdown|recipient_dropdown|supplier_dropdown)\|raw/', $tpl));
ok('las plantillas no exponen tokens/lease/hashes internos', preg_match('/lease_token|payload_sha256|input_sha256|content_sha256|qr_token|\.token\b/', $tpl) !== 1);
ok('🔒 [UI-NO-STATE-HARDCODE] los botones de workflow salen de can.* (motor), nunca de comparar el estado',
    preg_match('/if\s+[^%]*(h\.state|domain_state|state_label|\.state\s*==)/', $tpl) !== 1
    && str_contains($tpl, 'r.can.decide') && str_contains($tpl, 'r.can.deliver') && str_contains($tpl, 'r.can.close'));
$builder = $read($root . '/src/Service/RequestDetailBuilder.php');
ok('🔒 [UI-NO-STATE-HARDCODE] can.* se deriva de availableActions/actionsForCurrentUser del motor',
    str_contains($builder, '$this->wf->availableActions($inst)') && str_contains($builder, '$this->wf->actionsForCurrentUser($inst)')
    && preg_match('/can\[[^\]]*\]\s*=>[^,]*domain_state/', $builder) !== 1);
// Cada botón de WORKFLOW (decidir, compra, recepción, entrega, cierre) se calcula SÓLO con acciones del motor
// ($available / $mine) ∩ derecho: su expresión no puede comparar el estado ni usar constantes de estado S_*.
$canBlock = preg_match('/\$can = \[(.*?)\n\s*\];/s', $builder, $cm) === 1 ? $cm[1] : '';
$entries = [];
foreach (preg_split("/\n\s*'(?=\w+'\s*=>)/", "\n" . $canBlock) ?: [] as $chunk) {
    if (preg_match("/^(\w+)'\s*=>(.*)$/s", trim($chunk), $em) === 1) {
        $entries[$em[1]] = $em[2];
    }
}
$wfButtons = ['decide' => '$mine', 'start_purchase' => '$available', 'receive' => '$available', 'deliver' => '$available', 'close' => '$available'];
$wfOk = $canBlock !== '';
foreach ($wfButtons as $key => $source) {
    $expr = $entries[$key] ?? '';
    $wfOk = $wfOk && $expr !== '' && str_contains($expr, $source)
        && preg_match('/\$state\b|domain_state|PurchasingWorkflow::S_|\bstateCode\(|[\'"](RECEIVED|DELIVERED|IN_PURCHASE|APPROVED|PARTIALLY_RECEIVED|CLOSED)[\'"]/', $expr) !== 1;
}
ok('🔒 [UI-NO-STATE-HARDCODE] botones de workflow (decide/start_purchase/receive/deliver/close) = acción del motor ∩ derecho, sin comparar estados',
    $wfOk);
$inbox = $read($root . '/src/Service/InboxService.php');
ok('🔒 bandeja de aprobaciones derivada del motor (pendingDecisionsForCurrentUser), sin estados de aprobación hardcodeados',
    str_contains($inbox, 'pendingDecisionsForCurrentUser') && preg_match('/PENDING_FINANCE|PENDING_AREA_HEAD|S_PURCHASING\b/', $inbox) !== 1);

echo "== P2D-4 · i18n ES/EN: toda cadena nueva tiene traducción ==\n";
$ids = [];
foreach (array_merge(glob($root . '/src/{,*/}*.php', GLOB_BRACE) ?: [], glob($root . '/templates/{,parts/}*.twig', GLOB_BRACE) ?: []) as $f) {
    preg_match_all('/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*[\'"]companypurchasing[\'"]\s*\)/', (string) file_get_contents($f), $m);
    foreach ($m[2] as $s) {
        $ids[str_replace("\\'", "'", $s)] = true;
    }
}
$missing = [];
foreach (['es_ES', 'en_GB'] as $lang) {
    $po = (string) file_get_contents($root . '/locales/' . $lang . '.po');
    foreach (array_keys($ids) as $s) {
        if (!str_contains($po, 'msgid "' . addcslashes($s, '"\\') . '"')) {
            $missing[] = $lang . ':' . $s;
        }
    }
}
ok('cadenas __(…, companypurchasing) presentes en es_ES.po y en_GB.po (' . count($ids) . ')' . ($missing !== [] ? ' — faltan: ' . implode(' | ', array_slice($missing, 0, 5)) : ''),
    count($ids) > 250 && $missing === []);
