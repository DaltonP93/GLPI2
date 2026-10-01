<?php

/**
 * Tests UNITARIOS + de CONTRATO + crash/retry de SI4-2 (ADR-0021), sin GLPI. Incluido desde `run.php` después de
 * `si4.php` (comparte `ok()`, `Si4World`, `si4Throws()` y los contadores). GLPI se emula con `InMemoryGlpiAssets`
 * (búsqueda sin distinguir mayúsculas como la colación real, sin UNIQUE en serial/otherserial, un Infocom por activo);
 * la implementación real (`CoreGlpiAssetGateway`, `DbBridgeStore`, `DbSagaStore`) se prueba en el selftest de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use GlpiPlugin\Companyintegrations\Client\FakeSnipeServer;
use GlpiPlugin\Companyintegrations\Si4\BridgeMatcher;
use GlpiPlugin\Companyintegrations\Si4\GlpiCandidateMatcher;
use GlpiPlugin\Companyintegrations\Si4\GlpiMappingRules;
use GlpiPlugin\Companyintegrations\Si4\InfocomPolicy;
use GlpiPlugin\Companyintegrations\Si4\SagaState;
use GlpiPlugin\Companyintegrations\Si4\Si4Config;
use GlpiPlugin\Companyintegrations\Si4\Si4Worker;
use GlpiPlugin\Companyintegrations\Si4\SimulatedCrash;

/** Estado GLPI + puente de un mundo (para comprobar que NADA se modificó). */
function si4gFp(Si4World $W): string
{
    return hash('sha256', json_encode([$W->glpi->items, $W->glpi->infocoms, $W->bridges->rows, $W->bridges->aliases]));
}

/** Vence el lease de la unidad (outbox + reloj, más allá del RETRY tardío de configuración) para que otro claim la re-tome. */
function si4gExpire(Si4World $W, string $uuid): void
{
    $W->source->expire($uuid);
    $W->now += 4000;
}

/** Cantidad de activos GLPI (Computer por defecto) con el tag determinista de la unidad. */
function si4gAssets(Si4World $W, string $uuid, string $itemtype = 'Computer'): int
{
    return $W->glpi->count($itemtype, null, $W->tag($uuid));
}

function si4gInfocoms(Si4World $W, string $itemtype, int $id): int
{
    return count(array_filter($W->glpi->infocoms, static fn (array $ic): bool => $ic['itemtype'] === $itemtype && (int) $ic['items_id'] === $id));
}

function si4gBridges(Si4World $W, string $uuid): int
{
    return count(array_filter($W->bridges->rows, static fn (array $r): bool => $r['receipt_unit_uuid'] === $uuid));
}

// =====================================================================================================================
echo "== SI4-2 · GlpiCandidateMatcher (resolver-o-crear fail-closed) ==\n";
$T = 'GP2-3F9A1C2B7D4E4F608A1B0C2D3E4F5A6B';
$row = static fn (int $id, int $e, ?string $serial, ?string $other, int $del = 0): array => ['id' => $id, 'entities_id' => $e, 'serial' => $serial, 'otherserial' => $other, 'is_deleted' => $del, 'is_dynamic' => 1];
ok('sin candidatos ⇒ crear', GlpiCandidateMatcher::classify([], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::NONE);
$c = GlpiCandidateMatcher::classify([$row(5, 1, 'SN1', $T)], 1, $T, 'SN1');
ok('1 por tag, entidad y serial correctos ⇒ vincular sin reclamar', $c['kind'] === GlpiCandidateMatcher::ONE && $c['id'] === 5 && !$c['claim']);
$c = GlpiCandidateMatcher::classify([$row(6, 1, 'SN1', null)], 1, $T, 'SN1');
ok('1 por serial (GLPI Agent) con número de inventario vacío ⇒ vincular y RECLAMAR', $c['kind'] === GlpiCandidateMatcher::ONE && $c['id'] === 6 && $c['claim']);
ok('2 por serial ⇒ AMBIGUOUS', GlpiCandidateMatcher::classify([$row(6, 1, 'SN1', null), $row(7, 1, 'SN1', null)], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::AMBIGUOUS);
ok('1 por tag + otro por serial ⇒ AMBIGUOUS', GlpiCandidateMatcher::classify([$row(5, 1, 'SN1', $T), $row(7, 1, 'SN1', null)], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::AMBIGUOUS);
ok('candidato en otra entidad ⇒ ENTITY_MISMATCH (no se adopta)', GlpiCandidateMatcher::classify([$row(6, 2, 'SN1', null)], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::ENTITY_MISMATCH);
ok('serial distinto ⇒ SERIAL_CONFLICT', GlpiCandidateMatcher::classify([$row(5, 1, 'OTRO', $T)], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::SERIAL_CONFLICT);
ok('serial con otras mayúsculas (la BD las iguala) ⇒ SERIAL_CONFLICT', GlpiCandidateMatcher::classify([$row(6, 1, 'sn1', null)], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::SERIAL_CONFLICT);
ok('tag propio pero el activo perdió el serial ⇒ SERIAL_CONFLICT', GlpiCandidateMatcher::classify([$row(5, 1, null, $T)], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::SERIAL_CONFLICT);
ok('número de inventario humano distinto ⇒ OTHERSERIAL_CONFLICT (nunca se pisa)', GlpiCandidateMatcher::classify([$row(6, 1, 'SN1', 'INV-0042')], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::OTHERSERIAL_CONFLICT);
ok('tag con otras mayúsculas ⇒ OTHERSERIAL_CONFLICT', GlpiCandidateMatcher::classify([$row(5, 1, 'SN1', strtolower($T))], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::OTHERSERIAL_CONFLICT);
ok('candidato en la papelera ⇒ DELETED', GlpiCandidateMatcher::classify([$row(6, 1, 'SN1', null, 1)], 1, $T, 'SN1')['kind'] === GlpiCandidateMatcher::DELETED);
ok('unidad sin serial + activo propio por tag (con serial del agente) ⇒ vincular', GlpiCandidateMatcher::classify([$row(5, 1, 'AGENT', $T)], 1, $T, null)['kind'] === GlpiCandidateMatcher::ONE);

echo "== SI4-2 · InfocomPolicy (costo EXACTO, sin redondeo ni conversión) ==\n";
ok('PYG escala 0 ⇒ decimal(20,4) exacto', InfocomPolicy::toGlpiValue('1500000') === '1500000.0000');
ok('USD escala 2 / 3 ⇒ se completa con ceros', InfocomPolicy::toGlpiValue('11.00') === '11.0000' && InfocomPolicy::toGlpiValue('3.667') === '3.6670');
ok('escala 6 con ceros sobrantes ⇒ exacto', InfocomPolicy::toGlpiValue('1.234500') === '1.2345');
ok('🔒 escala 5 con dígito significativo ⇒ NO representable (jamás se redondea)', InfocomPolicy::toGlpiValue('1.23456') === null);
ok('más de 16 dígitos enteros ⇒ NO representable', InfocomPolicy::toGlpiValue('12345678901234567') === null && InfocomPolicy::toGlpiValue('1234567890123456') !== null);
ok('texto no decimal ⇒ null', InfocomPolicy::toGlpiValue('1,5') === null && InfocomPolicy::toGlpiValue('-3') === null && InfocomPolicy::toGlpiValue('') === null);
$pay = ['currency' => 'PYG', 'unit_cost' => '2339', 'supplier_id' => 3, 'received_at' => '2026-09-29T16:07:00-03:00', 'request_number' => 'SC-1'];
$p = InfocomPolicy::plan($pay, 'PYG');
ok('plan: valor, proveedor, solicitud y fecha LOCAL de recepción', $p['ok'] && $p['fields'] === ['value' => '2339.0000', 'suppliers_id' => 3, 'order_number' => 'SC-1', 'delivery_date' => '2026-09-29']);
ok('plan: moneda distinta de la del Infocom ⇒ infocom_currency', InfocomPolicy::plan(['currency' => 'USD', 'unit_cost' => '11.00'] + $pay, 'PYG')['class'] === 'infocom_currency');
ok('plan: costo no representable ⇒ infocom_scale', InfocomPolicy::plan(['currency' => 'USD', 'unit_cost' => '1.234567'] + $pay, 'USD')['class'] === 'infocom_scale');
ok('plan: received_at inválido ⇒ infocom_date', InfocomPolicy::plan(['received_at' => '29/09/2026'] + $pay, 'PYG')['class'] === 'infocom_date'
    && InfocomPolicy::plan(['received_at' => '2026-02-30T10:00:00-03:00'] + $pay, 'PYG')['class'] === 'infocom_date');
ok('fecha "Y-m-d H:i:s" también aceptada', InfocomPolicy::localDate('2026-09-29 23:59:59') === '2026-09-29');
$want = $p['fields'];
ok('reconcile: sin Infocom ⇒ completar todo', InfocomPolicy::reconcile(null, $want) === ['fill' => $want, 'conflicts' => []]);
ok('reconcile: Infocom vacío (auto-creado) ⇒ completar', InfocomPolicy::reconcile(['value' => '0.0000', 'suppliers_id' => 0, 'order_number' => null, 'delivery_date' => null], $want)['fill'] === $want);
ok('reconcile: igual (GLPI devuelve 4 decimales) ⇒ nada', InfocomPolicy::reconcile(['value' => '2339.0000', 'suppliers_id' => '3', 'order_number' => 'SC-1', 'delivery_date' => '2026-09-29'], $want) === ['fill' => [], 'conflicts' => []]);
ok('🔒 reconcile: valor/proveedor distintos ⇒ conflicto (no se pisan)', InfocomPolicy::reconcile(['value' => '999.0000', 'suppliers_id' => 9, 'order_number' => 'SC-1', 'delivery_date' => '2026-09-29'], $want)['conflicts'] === ['value', 'suppliers_id']);

echo "== SI4-2 · BridgeMatcher (asset_bridge 1:1, nunca reasignar) ==\n";
$wantB = ['receipt_unit_uuid' => 'u-1', 'snipe_asset_id' => 10, 'snipe_asset_tag' => 'T-1', 'glpi_itemtype' => 'Computer', 'glpi_items_id' => 5, 'glpi_entity_id' => 1];
$br = static fn (array $o = []): array => $o + ['id' => 1] + $wantB;
ok('sin filas ⇒ insertar', BridgeMatcher::classify([], $wantB)['kind'] === BridgeMatcher::NONE);
ok('fila idéntica de la misma unidad ⇒ EXACT (idempotente)', BridgeMatcher::classify([$br()], $wantB)['kind'] === BridgeMatcher::EXACT);
ok('fila SI-1 idéntica sin uuid ⇒ ADOPT', BridgeMatcher::classify([$br(['receipt_unit_uuid' => null])], $wantB)['kind'] === BridgeMatcher::ADOPT);
ok('mismo Snipe, otro activo GLPI ⇒ CONFLICT', BridgeMatcher::classify([$br(['glpi_items_id' => 6])], $wantB)['kind'] === BridgeMatcher::CONFLICT);
ok('fila idéntica de OTRA unidad ⇒ CONFLICT', BridgeMatcher::classify([$br(['receipt_unit_uuid' => 'u-2'])], $wantB)['kind'] === BridgeMatcher::CONFLICT);
ok('identidades en dos puentes ⇒ CONFLICT', BridgeMatcher::classify([$br(), $br(['id' => 2])], $wantB)['kind'] === BridgeMatcher::CONFLICT);
ok('otra entidad ⇒ CONFLICT', BridgeMatcher::classify([$br(['glpi_entity_id' => 2])], $wantB)['kind'] === BridgeMatcher::CONFLICT);

echo "== SI4-2 · GlpiMappingRules / Si4Config ==\n";
ok('sin mapeo aprobado ⇒ bloqueado', !GlpiMappingRules::decide('NB', [])['ok']);
ok('mapeo ambiguo ⇒ bloqueado', !GlpiMappingRules::decide('NB', [['glpi_itemtype' => 'Computer', 'glpi_model_id' => 0], ['glpi_itemtype' => 'Monitor', 'glpi_model_id' => 0]])['ok']);
ok('itemtype mal formado ⇒ bloqueado', !GlpiMappingRules::decide('NB', [['glpi_itemtype' => 'Computer; DROP', 'glpi_model_id' => 0]])['ok']);
ok('mapeo completo ⇒ id de la fila + itemtype + modelo del mapeo (sin literales)', GlpiMappingRules::decide('NB', [['id' => 9, 'glpi_itemtype' => 'Computer', 'glpi_model_id' => 41]])
    === ['ok' => true, 'mapping_id' => 9, 'itemtype' => 'Computer', 'model_id' => 41, 'reason' => '']);
ok('fila de mapeo sin id ⇒ bloqueado (no se puede pinnear)', !GlpiMappingRules::decide('NB', [['glpi_itemtype' => 'Computer', 'glpi_model_id' => 41]])['ok']);
$pinRow = ['glpi_mapping_id' => 9, 'glpi_itemtype' => 'Computer', 'glpi_model_id' => 41, 'glpi_mapping_hash' => GlpiMappingRules::pinHash(9, 'NB', 'Computer', 41)];
ok('saga sin pin ⇒ pinned() null (primer uso)', GlpiMappingRules::pinned(['glpi_mapping_hash' => null], 'NB') === null);
ok('pin íntegro ⇒ destino pinneado', GlpiMappingRules::pinned($pinRow, 'NB') === ['ok' => true, 'mapping_id' => 9, 'itemtype' => 'Computer', 'model_id' => 41, 'reason' => '']);
ok('🔒 pin alterado (modelo) o de otra categoría ⇒ inconsistente', !GlpiMappingRules::pinned(['glpi_model_id' => 43] + $pinRow, 'NB')['ok']
    && !GlpiMappingRules::pinned($pinRow, 'MON')['ok'] && !GlpiMappingRules::pinned(['glpi_itemtype' => 'Monitor'] + $pinRow, 'NB')['ok']);
ok('🔒 el pin se escribe completo: parcial ⇒ excepción; sin columnas del pin ⇒ no es pin', si4Throws(fn () => GlpiMappingRules::isPinWrite(['glpi_model_id' => 41]))
    && si4Throws(fn () => GlpiMappingRules::isPinWrite(['glpi_mapping_hash' => ''] + $pinRow)) && GlpiMappingRules::isPinWrite($pinRow) && !GlpiMappingRules::isPinWrite(['state' => 'X']));
$w0 = new Si4World();
ok('moneda del Infocom inválida ⇒ error de configuración', Si4Config::fromArray(['si4_glpi_infocom_currency' => 'guarani'] + $w0->cfg)->errors(5000, 2) !== []);
ok('moneda del Infocom por defecto PYG', Si4Config::fromArray($w0->cfg)->infocomCurrency === 'PYG');
ok('el lease mínimo cubre también las etapas GLPI', Si4Config::minLeaseSeconds(5000, 2) >= Si4Config::GLPI_STAGES_SEC + 60);

// =====================================================================================================================
echo "== SI4-2 · E2E en memoria: Snipe ⇒ activo GLPI ⇒ Infocom ⇒ asset_bridge (BRIDGED, sin ack) ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-E2E-1']);
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('una pasada lleva la unidad a BRIDGED', $m['bridged'] === 1 && $s['state'] === SagaState::BRIDGED && $m['snipe_created'] === 1 && $m['glpi_created'] === 1);
$gid = (int) $s['glpi_items_id'];
$pc = $W->glpi->items['Computer'][$gid] ?? [];
ok('activo GLPI nativo: itemtype/modelo del MAPEO, entidad de la unidad, serial y número de inventario = tag', $s['glpi_itemtype'] === 'Computer'
    && ($pc['model_id'] ?? 0) === 41 && ($pc['entities_id'] ?? 0) === 1 && ($pc['serial'] ?? '') === 'SN-E2E-1' && ($pc['otherserial'] ?? '') === $W->tag($u) && $W->glpi->addCalls === 1);
ok('nombre = descripción de la línea; nada más copiado de Snipe', ($pc['name'] ?? '') === 'Notebook 14"');
$ic = $W->glpi->getInfocom('Computer', $gid) ?? [];
ok('Infocom: costo EXACTO de la unidad, proveedor, solicitud y fecha de entrega', ($ic['value'] ?? '') === '2339.0000' && (int) ($ic['suppliers_id'] ?? 0) === 3
    && ($ic['order_number'] ?? '') === 'SC-2026-0011' && ($ic['delivery_date'] ?? '') === '2026-09-29' && array_key_exists('buy_date', $ic) && $ic['buy_date'] === null);
$b = $W->bridges->rows[(int) $s['asset_bridge_id']] ?? [];
ok('asset_bridge 1:1: uuid, Snipe id/tag, activo GLPI y entidad', ($b['receipt_unit_uuid'] ?? '') === $u && (int) ($b['snipe_asset_id'] ?? 0) === (int) $s['snipe_asset_id']
    && ($b['snipe_asset_tag'] ?? '') === $W->tag($u) && ($b['glpi_itemtype'] ?? '') === 'Computer' && (int) ($b['glpi_items_id'] ?? 0) === $gid && (int) ($b['glpi_entity_id'] ?? 0) === 1
    && ($W->bridges->aliases[strtoupper($W->tag($u))] ?? 0) === (int) $s['asset_bridge_id']);
ok('saga: outcomes created/created y sin resume_state', $s['glpi_outcome'] === 'created' && $s['infocom_outcome'] === 'created' && $s['resume_state'] === null);
ok('🔒 NO acknowledgeProcessed: el outbox sigue LEASED', $W->source->acks === [] && $W->source->row($u)['status'] === 'LEASED' && $W->source->retries === [] && $W->source->errors === []);

echo "== SI4-2 · Mismo receipt_unit_uuid N veces ⇒ un activo Snipe, un activo GLPI, un Infocom, un puente ==\n";
$fp = si4gFp($W);
$reqBefore = count($W->snipe->requests);
for ($i = 0; $i < 4; $i++) {
    si4gExpire($W, $u);
    $mi = $W->worker2()->run();
}
ok('re-claims de BRIDGED ⇒ estacionada sin escribir', $mi['parked'] === 1 && si4gFp($W) === $fp && $W->glpi->addCalls === 1 && $W->glpi->infocomAddCalls === 1);
ok('re-claims de BRIDGED no llaman a Snipe (sólo el preflight)', count($W->snipe->requests) - $reqBefore === 4 && $W->posts() === 1);
ok('una sola saga, un puente', count($W->sagas->rows) === 1 && si4gBridges($W, $u) === 1);

echo "== SI4-2 · Reanudar desde SNIPE_CREATED (SI4-1 ⇒ SI4-2) relee Snipe antes de escribir en GLPI ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-RES-1']);
$W->worker()->run(); // modo SI4-1: estaciona en SNIPE_CREATED
ok('SI4-1 dejó la unidad en SNIPE_CREATED y ningún activo GLPI', $W->sagas->get($u)['state'] === SagaState::SNIPE_CREATED && $W->glpi->items === []);
si4gExpire($W, $u);
$gets = count(array_filter($W->snipe->requests, static fn (array $r): bool => $r['method'] === 'GET' && str_contains($r['url'], '/bytag/')));
$m = $W->worker2()->run();
$gets2 = count(array_filter($W->snipe->requests, static fn (array $r): bool => $r['method'] === 'GET' && str_contains($r['url'], '/bytag/')));
ok('relee el activo de Snipe (1 GET bytag) y llega a BRIDGED sin POST', $m['bridged'] === 1 && $gets2 === $gets + 1 && $W->posts() === 1 && si4gAssets($W, $u) === 1);

$W = new Si4World();
$u = $W->unit(['serial' => 'SN-DIV-1']);
$W->worker()->run();
$sid = (int) $W->sagas->get($u)['snipe_asset_id'];
$W->snipe->assets[$sid]['model_id'] = 32; // alguien cambió el modelo en Snipe
si4gExpire($W, $u);
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('🔒 Snipe divergente ⇒ MANUAL_REVIEW sin tocar GLPI ni corregir Snipe', $m['manual_review'] === 1 && $s['state'] === SagaState::MANUAL_REVIEW
    && $s['last_error_class'] === 'snipe_diverged' && $W->glpi->items === [] && $W->snipe->assets[$sid]['model_id'] === 32 && $W->source->row($u)['status'] === 'ERROR');

$W = new Si4World();
$u = $W->unit(['serial' => 'SN-DOWN-1']);
$W->worker()->run();
si4gExpire($W, $u);
for ($i = 0; $i < 3; $i++) {
    $W->snipe->failNext('GET', '/api/v1/hardware/bytag/', FakeSnipeServer::S500_BEFORE);
}
$m = $W->worker2()->run();
ok('Snipe no disponible al reanudar ⇒ RETRY sin escribir en GLPI', $m['retry'] === 1 && $W->glpi->items === [] && $W->source->row($u)['status'] === 'RETRY');

echo "== SI4-2 · GLPI Agent: dedup (vincular / ambiguo / otra entidad) ==\n";
$W = new Si4World();
$agent = $W->glpi->seed('Computer', 1, 'SN-AGENT-1', null, true);
$u = $W->unit(['serial' => 'SN-AGENT-1']);
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('agente con el mismo serial ⇒ encuentra exactamente 1 y lo VINCULA', $m['bridged'] === 1 && (int) $s['glpi_items_id'] === $agent && $s['glpi_outcome'] === 'linked' && $m['glpi_linked'] === 1);
ok('🔒 NO crea otro Computer', $W->glpi->addCalls === 0 && $W->glpi->count('Computer', 'SN-AGENT-1') === 1);
ok('reclama el número de inventario (vacío) = tag; GLPI lo bloquea frente al agente', $W->glpi->items['Computer'][$agent]['otherserial'] === $W->tag($u)
    && in_array('otherserial', $W->glpi->items['Computer'][$agent]['locked'] ?? [], true));
ok('Infocom y puente sobre el activo del agente', si4gInfocoms($W, 'Computer', $agent) === 1 && (int) $W->bridges->rows[(int) $s['asset_bridge_id']]['glpi_items_id'] === $agent);

$W = new Si4World();
$a1 = $W->glpi->seed('Computer', 1, 'SN-DUP-1');
$a2 = $W->glpi->seed('Computer', 1, 'SN-DUP-1');
$fp = si4gFp($W);
$u = $W->unit(['serial' => 'SN-DUP-1']);
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('🔒 2 Computers con el mismo serial ⇒ AMBIGUOUS / MANUAL_REVIEW', $m['manual_review'] === 1 && $s['state'] === SagaState::MANUAL_REVIEW && $s['last_error_class'] === 'glpi_ambiguous');
ok('🔒 ninguno se modifica y no se crea un tercero', si4gFp($W) === $fp && $W->glpi->addCalls === 0 && $W->source->row($u)['status'] === 'ERROR');

$W = new Si4World();
$other = $W->glpi->seed('Computer', 2, 'SN-ENT-1');
$fp = si4gFp($W);
$u = $W->unit(['serial' => 'SN-ENT-1']);
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('🔒 mismo serial en OTRA entidad ⇒ no se adopta (MANUAL_REVIEW entity_mismatch)', $m['manual_review'] === 1 && $s['last_error_class'] === 'glpi_entity_mismatch'
    && si4gFp($W) === $fp && $W->glpi->addCalls === 0 && $s['glpi_items_id'] === null);

$W = new Si4World();
$W->glpi->seed('Computer', 1, 'SN-BIOS-1', 'BIOS-TAG-99');
$fp = si4gFp($W);
$u = $W->unit(['serial' => 'SN-BIOS-1']);
$W->worker2()->run();
ok('número de inventario humano/BIOS distinto ⇒ MANUAL_REVIEW, sin pisarlo', $W->sagas->get($u)['last_error_class'] === 'glpi_otherserial_conflict' && si4gFp($W) === $fp);

$W = new Si4World();
$W->glpi->seed('Computer', 1, 'SN-TRASH-1', null, true, true);
$u = $W->unit(['serial' => 'SN-TRASH-1']);
$W->worker2()->run();
ok('candidato en la papelera ⇒ MANUAL_REVIEW (deleted)', $W->sagas->get($u)['last_error_class'] === 'glpi_deleted' && $W->glpi->addCalls === 0);

$W = new Si4World();
$W->glpi->seed('Computer', 1, 'SN-NOSERIAL');
$u = $W->unit(['serial' => null]);
$m = $W->worker2()->run();
ok('unidad SIN serial: no hay evidencia para vincular ⇒ crea (consecuencia documentada)', $m['bridged'] === 1 && $W->glpi->addCalls === 1 && $W->sagas->get($u)['glpi_outcome'] === 'created');

echo "== SI4-2 · Mapeo / itemtype / modelo ⇒ BLOCKED_CONFIG (reanuda sin repetir Snipe) ==\n";
$W = new Si4World();
unset($W->glpiMap->byCategory['NB']);
$u = $W->unit(['serial' => 'SN-MAP-1']);
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('mapeo GLPI ausente ⇒ BLOCKED_CONFIG con resume_state = SNIPE_CREATED', $m['blocked_config'] === 1 && $s['state'] === SagaState::BLOCKED_CONFIG
    && $s['resume_state'] === SagaState::SNIPE_CREATED && $s['last_error_class'] === 'glpi_mapping');
ok('RETRY tardío (config) y ningún activo GLPI', $W->source->row($u)['status'] === 'RETRY' && $W->glpi->items === [] && $W->posts() === 1);
$W->glpiMap->byCategory['NB'] = ['glpi_itemtype' => 'Computer', 'glpi_model_id' => 41];
si4gExpire($W, $u);
$m = $W->worker2()->run();
ok('configurado el mapeo, el retry reanuda y llega a BRIDGED sin un segundo POST a Snipe', $m['bridged'] === 1 && $W->posts() === 1 && si4gAssets($W, $u) === 1);

$W = new Si4World();
$W->glpiMap->byCategory['NB'] = ['glpi_itemtype' => 'Software', 'glpi_model_id' => 0];
$u = $W->unit();
$W->worker2()->run();
ok('itemtype no soportado ⇒ BLOCKED_CONFIG', $W->sagas->get($u)['state'] === SagaState::BLOCKED_CONFIG && $W->glpi->items === []);
$W = new Si4World();
$W->glpiMap->byCategory['NB'] = ['glpi_itemtype' => 'Computer', 'glpi_model_id' => 999];
$u = $W->unit();
$W->worker2()->run();
ok('modelo GLPI inexistente ⇒ BLOCKED_CONFIG', $W->sagas->get($u)['state'] === SagaState::BLOCKED_CONFIG && str_contains((string) $W->sagas->get($u)['last_error'], 'modelo') && $W->glpi->items === []);
$W = new Si4World();
$u = $W->unit(['category' => 'MON']);
$m = $W->worker2()->run();
ok('otra categoría ⇒ otro itemtype del mapeo (Monitor, sin modelo)', $m['bridged'] === 1 && $W->sagas->get($u)['glpi_itemtype'] === 'Monitor' && si4gAssets($W, $u, 'Monitor') === 1);

echo "== SI4-2 · ACL del usuario técnico ==\n";
$W = new Si4World();
$W->glpi->denied['Computer:create'] = true;
$u = $W->unit();
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('sin CREATE de Computer ⇒ BLOCKED_CONFIG (acl), sin alta', $m['blocked_config'] === 1 && $s['last_error_class'] === 'acl' && $W->glpi->addCalls === 0);
unset($W->glpi->denied['Computer:create']);
si4gExpire($W, $u);
ok('otorgado el derecho ⇒ BRIDGED', $W->worker2()->run()['bridged'] === 1);
$W = new Si4World();
$W->glpi->entityAccess = [1 => false, 2 => true];
$u = $W->unit();
$W->worker2()->run();
ok('sin acceso a la entidad de la unidad ⇒ BLOCKED_CONFIG (acl)', $W->sagas->get($u)['last_error_class'] === 'acl' && $W->glpi->items === []);
$W = new Si4World();
$W->glpi->denied['infocom:create'] = true;
$u = $W->unit(['serial' => 'SN-ICACL']);
$W->worker2()->run();
$s = $W->sagas->get($u);
ok('sin derecho de Infocom ⇒ BLOCKED_CONFIG con resume_state = GLPI_RESOLVED_OR_CREATED (activo ya creado)', $s['state'] === SagaState::BLOCKED_CONFIG
    && $s['resume_state'] === SagaState::GLPI_RESOLVED_OR_CREATED && si4gAssets($W, $u) === 1 && $W->glpi->infocoms === []);
unset($W->glpi->denied['infocom:create']);
si4gExpire($W, $u);
$m = $W->worker2()->run();
ok('otorgado ⇒ reanuda en Infocom sin un segundo activo', $m['bridged'] === 1 && $W->glpi->addCalls === 1 && si4gAssets($W, $u) === 1);
$W = new Si4World();
$W->glpi->seed('Computer', 1, 'SN-UPD-1');
$W->glpi->denied['Computer:update'] = true;
$u = $W->unit(['serial' => 'SN-UPD-1']);
$W->worker2()->run();
ok('vincular exige UPDATE para reclamar el número de inventario ⇒ BLOCKED_CONFIG (acl)', $W->sagas->get($u)['last_error_class'] === 'acl' && $W->glpi->inventoryNumberWrites === 0);

echo "== SI4-2 · Infocom: moneda / escala / proveedor / Infocom existente ==\n";
$W = new Si4World();
$u = $W->unit(['currency' => 'USD', 'currency_scale' => 2, 'unit_cost' => '11.00']);
$m = $W->worker2()->run();
ok('🔒 moneda distinta de la del Infocom ⇒ MANUAL_REVIEW ANTES de crear el activo GLPI', $m['manual_review'] === 1 && $W->sagas->get($u)['last_error_class'] === 'infocom_currency' && $W->glpi->items === []);
$W = new Si4World();
$u = $W->unit(['currency' => 'USD', 'currency_scale' => 6, 'unit_cost' => '1.234567']);
$W->worker2(null, ['si4_glpi_infocom_currency' => 'USD'])->run();
ok('🔒 escala no representable ⇒ MANUAL_REVIEW (infocom_scale), sin redondear ni crear', $W->sagas->get($u)['last_error_class'] === 'infocom_scale' && $W->glpi->items === []);
$W = new Si4World();
$u = $W->unit(['currency' => 'USD', 'currency_scale' => 3, 'unit_cost' => '3.667']);
$W->worker2(null, ['si4_glpi_infocom_currency' => 'USD'])->run();
$s = $W->sagas->get($u);
ok('USD escala 3 con Infocom en USD ⇒ 3.6670 exacto', $s['state'] === SagaState::BRIDGED && ($W->glpi->getInfocom('Computer', (int) $s['glpi_items_id'])['value'] ?? '') === '3.6670');
$W = new Si4World();
$W->glpi->suppliers[3]['deleted'] = true; // en la papelera
$u = $W->unit();
$W->worker2()->run();
ok('proveedor en la papelera ⇒ MANUAL_REVIEW sin crear el activo', $W->sagas->get($u)['last_error_class'] === 'infocom_supplier' && $W->glpi->items === []);
$W = new Si4World();
$agent = $W->glpi->seed('Computer', 1, 'SN-IC-EMPTY');
$W->glpi->infocoms[900] = ['id' => 900, 'itemtype' => 'Computer', 'items_id' => $agent, 'value' => '0.0000', 'suppliers_id' => 0, 'order_number' => null, 'delivery_date' => null, 'buy_date' => null];
$u = $W->unit(['serial' => 'SN-IC-EMPTY']);
$W->worker2()->run();
$s = $W->sagas->get($u);
ok('Infocom vacío preexistente (auto_create_infocoms) ⇒ se COMPLETA, sin un segundo Infocom', $s['state'] === SagaState::BRIDGED && $s['infocom_outcome'] === 'updated'
    && si4gInfocoms($W, 'Computer', $agent) === 1 && $W->glpi->infocoms[900]['value'] === '2339.0000' && $W->glpi->infocomAddCalls === 0);
$W = new Si4World();
$agent = $W->glpi->seed('Computer', 1, 'SN-IC-OTHER');
$W->glpi->infocoms[901] = ['id' => 901, 'itemtype' => 'Computer', 'items_id' => $agent, 'value' => '5000.0000', 'suppliers_id' => 8, 'order_number' => null, 'delivery_date' => null, 'buy_date' => null];
$u = $W->unit(['serial' => 'SN-IC-OTHER']);
$W->worker2()->run();
ok('🔒 Infocom existente con otro costo/proveedor ⇒ MANUAL_REVIEW, no se pisa', $W->sagas->get($u)['last_error_class'] === 'infocom_conflict'
    && $W->glpi->infocoms[901]['value'] === '5000.0000' && (int) $W->glpi->infocoms[901]['suppliers_id'] === 8);

echo "== SI4-2 · asset_bridge: adoptar SI-1 / conflicto ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-BR-1']);
$W->worker()->run();
$s = $W->sagas->get($u);
$agent = $W->glpi->seed('Computer', 1, 'SN-BR-1', $W->tag($u));
$si1 = $W->bridges->seedSi1((int) $s['snipe_asset_id'], $W->tag($u), 'Computer', $agent, 1);
si4gExpire($W, $u);
$W->worker2()->run();
$s = $W->sagas->get($u);
ok('puente SI-1 idéntico (reconciliación) ⇒ ADOPTADO: completa el uuid, no duplica', $s['state'] === SagaState::BRIDGED && (int) $s['asset_bridge_id'] === $si1
    && count($W->bridges->rows) === 1 && $W->bridges->rows[$si1]['receipt_unit_uuid'] === $u);
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-BR-2']);
$W->worker()->run();
$s = $W->sagas->get($u);
$W->bridges->seedSi1((int) $s['snipe_asset_id'], $W->tag($u), 'Computer', 777, 1);
si4gExpire($W, $u);
$W->worker2()->run();
$s = $W->sagas->get($u);
ok('🔒 puente existente con el mismo Snipe y OTRO activo GLPI ⇒ MANUAL_REVIEW (no se reasigna)', $s['state'] === SagaState::MANUAL_REVIEW && $s['last_error_class'] === 'bridge_conflict'
    && count($W->bridges->rows) === 1 && (int) $W->bridges->rows[array_key_first($W->bridges->rows)]['glpi_items_id'] === 777);

echo "== SI4-2 · Crash points (§9): el retry converge sin duplicar nada ==\n";
$crashAt = static fn (string $point): callable => static function (string $p) use ($point): void {
    if ($p === $point) {
        throw new SimulatedCrash('muere en ' . $point);
    }
};
$points = ['before_glpi_search', 'before_glpi_create', 'after_glpi_create', 'after_glpi_recorded_unverified', 'after_glpi_recorded', 'after_infocom_write',
    'after_infocom_ready', 'after_bridge_write', 'after_bridged'];
foreach ($points as $pt) {
    $W = new Si4World();
    $u = $W->unit(['serial' => 'SN-CRASH-' . $pt]);
    $crashed = si4Throws(fn () => $W->worker2($crashAt($pt))->run());
    si4gExpire($W, $u);
    $m = $W->worker2()->run();
    si4gExpire($W, $u);
    $W->worker2()->run(); // otro retry más: sigue idempotente
    $s = $W->sagas->get($u);
    $gid = (int) $s['glpi_items_id'];
    ok("crash «{$pt}» ⇒ retry BRIDGED con 1 activo Snipe, 1 GLPI, 1 Infocom, 1 puente", $crashed && $s['state'] === SagaState::BRIDGED
        && $W->assetsFor($u) === 1 && $W->posts() === 1 && si4gAssets($W, $u) === 1 && $W->glpi->count('Computer', 'SN-CRASH-' . $pt) === 1
        && $W->glpi->addCalls <= 1 && si4gInfocoms($W, 'Computer', $gid) === 1 && count($W->bridges->rows) === 1 && si4gBridges($W, $u) === 1);
}
$W = new Si4World();
$W->glpi->seed('Computer', 1, 'SN-CRASH-FOUND');
$u = $W->unit(['serial' => 'SN-CRASH-FOUND']);
$crashed = si4Throws(fn () => $W->worker2($crashAt('after_glpi_found'))->run());
si4gExpire($W, $u);
$m = $W->worker2()->run();
ok('crash «after_glpi_found» (agente encontrado, saga sin guardar) ⇒ retry vincula el MISMO, sin alta', $crashed && $m['bridged'] === 1 && $W->glpi->addCalls === 0
    && $W->glpi->count('Computer', 'SN-CRASH-FOUND') === 1 && $W->sagas->get($u)['glpi_outcome'] === 'linked');
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-CRASH-SNIPE']);
$crashed = si4Throws(fn () => $W->worker2($crashAt('after_remote_create'))->run());
si4gExpire($W, $u);
$W->worker2()->run();
ok('crash tras el POST a Snipe (SI4-1) + SI4-2 en el retry ⇒ 1 activo Snipe, 1 GLPI', $crashed && $W->sagas->get($u)['state'] === SagaState::BRIDGED
    && $W->assetsFor($u) === 1 && $W->posts() === 1 && si4gAssets($W, $u) === 1);

echo "== SI4-2 · Verificación posterior al alta / alta rechazada / activo propio desaparecido ==\n";
$W = new Si4World();
$W->glpi->addRule = static function (array $row): array {
    $row['entities_id'] = 2; // una regla de negocio mueve el activo de entidad
    return $row;
};
$u = $W->unit(['serial' => 'SN-RULE-1']);
$W->worker2()->run();
ok('regla que altera la entidad ⇒ verificación posterior ⇒ MANUAL_REVIEW, un solo add()', $W->sagas->get($u)['last_error_class'] === 'glpi_post_verify' && $W->glpi->addCalls === 1);
$W = new Si4World();
$W->glpi->refuseAdd = true;
$u = $W->unit();
$W->worker2()->run();
ok('alta rechazada (unicidad/hook) ⇒ MANUAL_REVIEW', $W->sagas->get($u)['last_error_class'] === 'glpi_add_refused');
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-GONE-1']);
si4Throws(fn () => $W->worker2($crashAt('after_glpi_recorded'))->run());
$gid = (int) $W->sagas->get($u)['glpi_items_id'];
$W->glpi->items['Computer'][$gid]['is_deleted'] = 1; // alguien lo mandó a la papelera
si4gExpire($W, $u);
$W->worker2()->run();
ok('🔒 el activo propio ya registrado quedó en la papelera ⇒ MANUAL_REVIEW, sin segundo add()', $W->sagas->get($u)['state'] === SagaState::MANUAL_REVIEW && $W->glpi->addCalls === 1);
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-MISS-1']);
$W->worker2(function (string $p, string $uu) use ($W): void {
    if ($p === 'after_glpi_create') {
        // entre el alta y el registro: el activo se "pierde" (número de inventario y serial cambiados) y el proceso muere
        foreach ($W->glpi->items['Computer'] as $id => $r) {
            $W->glpi->items['Computer'][$id]['otherserial'] = 'X';
            $W->glpi->items['Computer'][$id]['serial'] = 'Y';
        }
    }
})->run();
ok('🔒 activo recién creado que ya no aparece por su búsqueda ⇒ MANUAL_REVIEW (verificación posterior), sin segundo add()', $W->sagas->get($u)['state'] === SagaState::MANUAL_REVIEW && $W->glpi->addCalls === 1);

$W = new Si4World();
$u = $W->unit(['serial' => 'SN-VANISH-1']);
si4Throws(fn () => $W->worker2($crashAt('after_glpi_recorded_unverified'))->run());
$gid = (int) $W->sagas->get($u)['glpi_items_id'];
$W->glpi->items['Computer'][$gid]['otherserial'] = 'RENOMBRADO';
$W->glpi->items['Computer'][$gid]['serial'] = 'OTRO';
si4gExpire($W, $u);
$W->worker2()->run();
ok('🔒 la saga registró su activo y la búsqueda ya no lo encuentra ⇒ MANUAL_REVIEW (glpi_created_missing), NUNCA un segundo add()',
    $W->sagas->get($u)['last_error_class'] === 'glpi_created_missing' && $W->glpi->addCalls === 1);

echo "== SI4-2 · Fencing: lease vencido ⇒ el worker viejo no escribe en GLPI; mapeo cambiado ⇒ conserva el itemtype ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-FENCE-1']);
$W->worker2(function (string $p) use ($W): void {
    if ($p === 'before_glpi_search') {
        $W->now += 5000; // el lease vence en medio de la etapa GLPI
    }
})->run();
ok('🔒 lease vencido antes del alta ⇒ no se crea nada en GLPI', $W->glpi->addCalls === 0 && $W->glpi->items === [] && $W->sagas->get($u)['state'] === SagaState::SNIPE_CREATED);
si4gExpire($W, $u);
ok('el nuevo dueño completa la saga', $W->worker2()->run()['bridged'] === 1 && si4gAssets($W, $u) === 1);
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-MAPCHG-1']);
si4Throws(fn () => $W->worker2($crashAt('after_glpi_create'))->run());
$W->glpiMap->byCategory['NB'] = ['glpi_itemtype' => 'Monitor', 'glpi_model_id' => 0]; // el mapeo cambia después del alta
si4gExpire($W, $u);
$W->worker2()->run();
ok('🔒 el itemtype registrado en la saga gana: reanuda con Computer, sin crear un Monitor', $W->sagas->get($u)['glpi_itemtype'] === 'Computer'
    && ($W->glpi->items['Monitor'] ?? []) === [] && si4gAssets($W, $u) === 1 && $W->sagas->get($u)['state'] === SagaState::BRIDGED);

echo "== SI4-2 · Dos workers ⇒ una saga, un activo GLPI ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-2W-1']);
si4Throws(fn () => $W->worker2($crashAt('after_glpi_create'))->run()); // A crea y muere
si4gExpire($W, $u);
$mB = $W->worker2(null, ['si4_worker_id' => 'w-B'])->run();
$s = $W->sagas->get($u);
ok('B re-toma, encuentra el activo de A por su número de inventario y termina: 1 activo GLPI', $mB['bridged'] === 1 && si4gAssets($W, $u) === 1
    && $W->glpi->addCalls === 1 && $s['glpi_outcome'] === 'created' && (int) $s['lease_epoch'] === 2);

echo "== SI4-2 · Multi-entidad ==\n";
$W = new Si4World();
$u1 = $W->unit(['serial' => 'SN-ME-1', 'entity_id' => 1]);
$u2 = $W->unit(['serial' => 'SN-ME-2', 'entity_id' => 2, 'supplier_id' => 4]);
$m = $W->worker2()->run();
$s1 = $W->sagas->get($u1);
$s2 = $W->sagas->get($u2);
ok('cada unidad en SU entidad (activo, puente) y con su compañía Snipe', $m['bridged'] === 2
    && $W->glpi->items['Computer'][(int) $s1['glpi_items_id']]['entities_id'] === 1 && $W->glpi->items['Computer'][(int) $s2['glpi_items_id']]['entities_id'] === 2
    && (int) $W->bridges->rows[(int) $s2['asset_bridge_id']]['glpi_entity_id'] === 2 && (int) $s1['snipe_company_id'] === 7 && (int) $s2['snipe_company_id'] === 8);

echo "== SI4-2 · Mapeo PINNEADO por saga: el retry nunca relee el mapeo vivo ==\n";
$W = new Si4World();
$W->glpi->models['Computer'][43] = true;
$W->glpi->denied['Computer:create'] = true; // el primer intento se bloquea ANTES del alta
$u = $W->unit(['serial' => 'SN-PIN-1']);
$W->worker2()->run();
$s = $W->sagas->get($u);
$mapId = (int) $W->glpiMap->resolve(['category' => 'NB'])['mapping_id'];
ok('primer uso: NB ⇒ Computer + modelo 41 PINNEADO en la saga (mapeo, itemtype, modelo, huella)', $s['state'] === SagaState::BLOCKED_CONFIG
    && (int) $s['glpi_mapping_id'] === $mapId && $s['glpi_itemtype'] === 'Computer' && (int) $s['glpi_model_id'] === 41
    && $s['glpi_mapping_hash'] === GlpiMappingRules::pinHash($mapId, 'NB', 'Computer', 41) && $W->glpi->items === []);
ok('el pin queda en la bitácora de la saga', count(array_filter($W->sagas->log, static fn (array $l): bool => $l['uuid'] === $u && $l['event'] === 'glpi_mapping_pinned')) === 1);
$W->glpiMap->byCategory['NB'] = ['glpi_itemtype' => 'Computer', 'glpi_model_id' => 43]; // el administrador cambia la MISMA fila
unset($W->glpi->denied['Computer:create']);
si4gExpire($W, $u);
$W->worker2()->run();
$s = $W->sagas->get($u);
ok('🔒 retry de la MISMA saga ⇒ sigue usando el modelo 41 pinneado', $s['state'] === SagaState::BRIDGED && ($W->glpi->items['Computer'][(int) $s['glpi_items_id']]['model_id'] ?? 0) === 41
    && (int) $s['glpi_model_id'] === 41);
$u2 = $W->unit(['serial' => 'SN-PIN-2']);
$W->worker2()->run();
$s2 = $W->sagas->get($u2);
ok('unidad NUEVA ⇒ usa el modelo 43 del mapeo vigente', $s2['state'] === SagaState::BRIDGED && ($W->glpi->items['Computer'][(int) $s2['glpi_items_id']]['model_id'] ?? 0) === 43
    && (int) $s2['glpi_model_id'] === 43);
ok('🔒 el pin es inmutable: re-pinnear la misma saga ⇒ false, nada escrito', !$W->sagas->transition($u2, (string) $s2['lease_token_sha256'], $s2['state'],
    ['glpi_mapping_id' => 1, 'glpi_itemtype' => 'Monitor', 'glpi_model_id' => 0, 'glpi_mapping_hash' => str_repeat('a', 64)], 'x') && $W->sagas->get($u2)['glpi_itemtype'] === 'Computer');

$W = new Si4World();
$u = $W->unit(['serial' => 'SN-PIN-MAPGONE']);
si4Throws(fn () => $W->worker2($crashAt('before_glpi_search'))->run());
unset($W->glpiMap->byCategory['NB']); // el mapeo se retira después del pin
si4gExpire($W, $u);
$W->worker2()->run();
ok('🔒 mapeo retirado después del pin ⇒ la saga termina con su destino pinneado (no se bloquea ni cambia)', $W->sagas->get($u)['state'] === SagaState::BRIDGED && si4gAssets($W, $u) === 1);

$W = new Si4World();
$W->glpi->models['Computer'][43] = true;
$u = $W->unit(['serial' => 'SN-PIN-DEL']);
si4Throws(fn () => $W->worker2($crashAt('before_glpi_search'))->run());
unset($W->glpi->models['Computer'][41]); // el modelo pinneado se elimina
$W->glpiMap->byCategory['NB'] = ['glpi_itemtype' => 'Computer', 'glpi_model_id' => 43];
si4gExpire($W, $u);
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('🔒 modelo pinneado eliminado ⇒ MANUAL_REVIEW (glpi_pin_invalid), sin alta y sin cambiar de modelo', $m['manual_review'] === 1 && $s['state'] === SagaState::MANUAL_REVIEW
    && $s['last_error_class'] === 'glpi_pin_invalid' && $W->glpi->items === [] && (int) $s['glpi_model_id'] === 41);

$W = new Si4World();
$W->glpi->models['Computer'][43] = true;
$u = $W->unit(['serial' => 'SN-PIN-TAMPER']);
si4Throws(fn () => $W->worker2($crashAt('before_glpi_search'))->run());
$W->sagas->rows[$u]['glpi_model_id'] = 43; // alguien altera el pin fuera de la saga
si4gExpire($W, $u);
$W->worker2()->run();
ok('🔒 pin alterado (huella distinta) ⇒ MANUAL_REVIEW (glpi_pin_corrupt), sin alta', $W->sagas->get($u)['last_error_class'] === 'glpi_pin_corrupt' && $W->glpi->items === []);

$W = new Si4World();
$u = $W->unit(['serial' => 'SN-PIN-LEGACY']);
$W->worker()->run(); // SI4-1
$W->sagas->rows[$u]['glpi_itemtype'] = 'Computer'; // estado imposible: itemtype sin pin
si4gExpire($W, $u);
$W->worker2()->run();
ok('🔒 saga con itemtype pero sin pin ⇒ MANUAL_REVIEW (glpi_pin_missing), nunca re-resolver en silencio', $W->sagas->get($u)['last_error_class'] === 'glpi_pin_missing' && $W->glpi->items === []);

echo "== SI4-2 · Reclamo del GLPI Agent: post-verificación del conjunto completo ==\n";
$W = new Si4World();
$agent = $W->glpi->seed('Computer', 1, 'SN-RACE-1', null, true);
$u = $W->unit(['serial' => 'SN-RACE-1']);
$raced = 0;
$m = $W->worker2(function (string $p) use ($W, &$raced): void {
    if ($p === 'after_glpi_claim') {
        $raced = $W->glpi->seed('Computer', 1, 'SN-RACE-1', null, true); // justo después del reclamo aparece otro con el mismo serial
    }
})->run();
$s = $W->sagas->get($u);
ok('🔒 carrera: otro Computer con el mismo serial tras el reclamo ⇒ AMBIGUOUS ⇒ MANUAL_REVIEW (glpi_claim_verify)', $raced > 0 && $m['manual_review'] === 1
    && $s['state'] === SagaState::MANUAL_REVIEW && $s['last_error_class'] === 'glpi_claim_verify' && str_contains((string) $s['last_error'], 'ambiguous'));
ok('🔒 el vínculo NO se consolida: sin glpi_items_id, sin Infocom, sin puente; outbox ERROR', $s['glpi_items_id'] === null && $W->glpi->infocoms === []
    && $W->bridges->rows === [] && $W->source->row($u)['status'] === 'ERROR' && $W->glpi->addCalls === 0);
$W = new Si4World();
$agent = $W->glpi->seed('Computer', 1, 'SN-RACE-2', null, true);
$u = $W->unit(['serial' => 'SN-RACE-2']);
$W->worker2(function (string $p) use ($W, $u): void {
    if ($p === 'after_glpi_claim') {
        $W->glpi->seed('Computer', 1, null, $W->tag($u), false); // aparece otro con el MISMO número de inventario
    }
})->run();
$s = $W->sagas->get($u);
ok('🔒 carrera: otro activo con el mismo tag tras el reclamo ⇒ MANUAL_REVIEW, sin Infocom ni puente', $s['last_error_class'] === 'glpi_claim_verify'
    && $s['glpi_items_id'] === null && $W->glpi->infocoms === [] && $W->bridges->rows === []);
$W = new Si4World();
$agent = $W->glpi->seed('Computer', 1, 'SN-CLAIM-CRASH', null, true);
$u = $W->unit(['serial' => 'SN-CLAIM-CRASH']);
$crashed = si4Throws(fn () => $W->worker2($crashAt('after_glpi_claim'))->run());
si4gExpire($W, $u);
$m = $W->worker2()->run();
ok('crash «after_glpi_claim» (reclamado, sin post-verificar) ⇒ retry lo encuentra por su tag y vincula el MISMO', $crashed && $m['bridged'] === 1
    && (int) $W->sagas->get($u)['glpi_items_id'] === $agent && $W->glpi->addCalls === 0 && $W->sagas->get($u)['glpi_outcome'] === 'linked');

echo "== SI4-2 · Proveedor del Infocom aplicable a la entidad de la unidad ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-SUPMOVE-1']);
$W->worker()->run(); // SI4-1: con el proveedor válido (entidad 1) al comprar/recibir
$W->glpi->suppliers[3]['entity'] = 2; // antes de SI4-2 el proveedor se mueve a otra rama
si4gExpire($W, $u);
$m = $W->worker2()->run();
$s = $W->sagas->get($u);
ok('🔒 proveedor movido a otra rama antes de SI4-2 ⇒ MANUAL_REVIEW (infocom_supplier), sin activo, Infocom ni puente', $m['manual_review'] === 1
    && $s['last_error_class'] === 'infocom_supplier' && $W->glpi->items === [] && $W->glpi->infocoms === [] && $W->bridges->rows === []);
$W = new Si4World();
$W->glpi->denied['infocom:create'] = true;
$u = $W->unit(['serial' => 'SN-SUPMOVE-2']);
$W->worker2()->run(); // activo creado; Infocom bloqueado por ACL
$W->glpi->suppliers[3]['entity'] = 2;
unset($W->glpi->denied['infocom:create']);
si4gExpire($W, $u);
$W->worker2()->run();
$s = $W->sagas->get($u);
ok('🔒 proveedor movido entre el activo y el Infocom ⇒ MANUAL_REVIEW, sin Infocom ni puente', $s['last_error_class'] === 'infocom_supplier'
    && si4gAssets($W, $u) === 1 && $W->glpi->infocoms === [] && $W->bridges->rows === []);
$W = new Si4World();
$W->glpi->suppliers[3] = ['deleted' => false, 'entity' => 0, 'recursive' => true];
$u = $W->unit(['serial' => 'SN-SUPANC-1']);
ok('proveedor RECURSIVO de un ancestro (raíz) ⇒ permitido', $W->worker2()->run()['bridged'] === 1
    && (int) ($W->glpi->getInfocom('Computer', (int) $W->sagas->get($u)['glpi_items_id'])['suppliers_id'] ?? 0) === 3);
$W = new Si4World();
$W->glpi->entityParent = [0 => -1, 5 => 0, 1 => 5, 2 => 0]; // raíz ⇒ 5 ⇒ 1 (entidad de la unidad)
$W->glpi->suppliers[3] = ['deleted' => false, 'entity' => 5, 'recursive' => true];
$u = $W->unit(['serial' => 'SN-SUPANC-2']);
ok('proveedor recursivo de un ancestro INTERMEDIO ⇒ permitido', $W->worker2()->run()['bridged'] === 1);
$W = new Si4World();
$W->glpi->entityParent = [0 => -1, 5 => 0, 1 => 5, 2 => 0];
$W->glpi->suppliers[3] = ['deleted' => false, 'entity' => 5, 'recursive' => true];
$u = $W->unit(['serial' => 'SN-SUPANC-3']);
$W->worker()->run();
$W->glpi->entityParent[1] = 0; // la entidad de la unidad se mueve fuera de la rama del proveedor (cadena VIVA)
si4gExpire($W, $u);
$W->worker2()->run();
ok('🔒 la entidad de la unidad sale de la rama del proveedor ⇒ MANUAL_REVIEW (se usa la cadena viva, no una caché)', $W->sagas->get($u)['last_error_class'] === 'infocom_supplier' && $W->glpi->items === []);
$W = new Si4World();
$W->glpi->suppliers[3] = ['deleted' => false, 'entity' => 0, 'recursive' => false];
$u = $W->unit(['serial' => 'SN-SUPANC-4']);
$W->worker2()->run();
ok('🔒 proveedor de un ancestro NO recursivo ⇒ MANUAL_REVIEW', $W->sagas->get($u)['last_error_class'] === 'infocom_supplier' && $W->glpi->items === []);
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-SUPSIB-1', 'supplier_id' => 4]);
$W->worker2()->run();
ok('🔒 proveedor de una rama hermana ⇒ MANUAL_REVIEW', $W->sagas->get($u)['last_error_class'] === 'infocom_supplier' && $W->glpi->items === []);

echo "== SI4-2 · Token nunca en logs / saga / puente; nunca ack ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-SEC-1']);
$W->worker2()->run();
$dump = json_encode($W->sagas->rows) . json_encode($W->bridges->rows) . json_encode($W->glpi->items) . json_encode($W->glpi->infocoms) . implode("\n", $W->logs);
ok('🔒 el token de Snipe no aparece en saga, puente, GLPI ni logs', !str_contains($dump, SI4_TOKEN));
$acks = 0;
foreach (Si4World::$all as $world) {
    $acks += count($world->source->acks);
}
ok('🔒 ninguna de las ' . count(Si4World::$all) . ' corridas en modo SI4-1/SI4-2 (sin etapa QR) llamó acknowledgeProcessed()', $acks === 0 && count(Si4World::$all) >= 60);
$src = '';
$glpiStage = '';
foreach (glob(dirname(__DIR__, 2) . '/src/Si4/*.php') ?: [] as $f) {
    $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($f));
    $src .= $code;
    if (basename($f) === 'Si4GlpiStage.php') {
        $glpiStage .= $code;
    }
}
// SI4-3 (ADR-0022) agrega el ack (sólo en `Si4Worker::continueQr`) y companyqr (sólo por su API pública): ver si4q.php.
ok('🔒 la etapa GLPI (SI4-2) no llama acknowledgeProcessed() ni toca companyqr', !preg_match('/acknowledgeProcessed/', $glpiStage) && stripos($glpiStage, 'companyqr') === false);
ok('🔒 sin SQL contra tablas del core de GLPI (glpi_computers, glpi_infocoms, …) en SI-4', preg_match('/glpi_(computers|monitors|printers|networkequipments|peripherals|phones|infocoms|suppliers)\b/', $src) !== 1);
