<?php

/**
 * Tests UNITARIOS + de CONTRATO + crash/retry de SI4-3 (ADR-0022), sin GLPI. Incluido desde `run.php` después de
 * `si4g.php` (comparte `ok()`, `Si4World`, `si4Throws()`, `si4gExpire()` y los contadores). companyqr se emula con
 * `InMemoryQrGateway` (API pública: get-or-create por activo, ACL, PDF sólo de códigos ACTIVOS, token sólo adentro);
 * la implementación real (`CoreQrGateway` ⇒ `CompanyQrApi`, `DbSagaStore::complete`) se prueba en el selftest de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use GlpiPlugin\Companyintegrations\Si4\QrCodeRules;
use GlpiPlugin\Companyintegrations\Si4\SagaState;
use GlpiPlugin\Companyintegrations\Si4\SagaStore;
use GlpiPlugin\Companyintegrations\Si4\Si4Finalizer;
use GlpiPlugin\Companyintegrations\Si4\Si4QrStage;
use GlpiPlugin\Companyintegrations\Si4\SimulatedCrash;

/** Crash simulado en un punto exacto. */
function si4qCrashAt(string $point): callable
{
    return static function (string $p) use ($point): void {
        if ($p === $point) {
            throw new SimulatedCrash('muere en ' . $point);
        }
    };
}

/** Corre el worker SI4-3 con un crash en `$point` (true si el proceso "murió"). */
function si4qRunCrash(Si4World $W, string $point): bool
{
    try {
        $W->worker3(si4qCrashAt($point))->run();
    } catch (SimulatedCrash) {
        return true;
    }
    return false;
}

/** Cantidades que deben quedar en 1 por unidad: activo Snipe, activo GLPI, Infocom, puente y código QR. */
function si4qOnes(Si4World $W, string $u): bool
{
    $s = $W->sagas->get($u) ?? [];
    $id = (int) ($s['glpi_items_id'] ?? 0);
    return $W->assetsFor($u) === 1 && si4gAssets($W, $u) === 1 && si4gInfocoms($W, 'Computer', $id) === 1 && si4gBridges($W, $u) === 1
        && $W->qr->countFor('Computer', $id) === 1;
}

/** Acks APLICADOS para la unidad (DONE exactamente una vez). */
function si4qDone(Si4World $W, string $u): int
{
    return count(array_filter($W->source->acksApplied, static fn (array $a): bool => $a['uuid'] === $u));
}

/** Todo lo persistido o registrado (saga, bitácora, puente, outbox, logs) — para buscar el token del QR. */
function si4qDump(Si4World $W): string
{
    $outbox = [];
    foreach (array_keys($W->sagas->rows) as $u) {
        $outbox[] = $W->source->row((string) $u);
    }
    return json_encode($W->sagas->rows) . json_encode($W->sagas->log) . json_encode($W->bridges->rows) . json_encode($outbox) . implode("\n", $W->logs);
}

/** Una unidad llevada a BRIDGED en modo SI4-2 (sin etapa QR), con su lease vencido. */
function si4qBridged(Si4World $W, array $over = []): string
{
    $u = $W->unit($over + ['serial' => 'SN-Q-' . bin2hex(random_bytes(3))]);
    $W->worker2()->run();
    si4gExpire($W, $u);
    return $u;
}

// =====================================================================================================================
echo "== SI4-3 · QrCodeRules (código ACTIVO del mismo activo, entidad y número de inventario; nunca el token) ==\n";
$meta = ['code_id' => 7, 'public_code' => 'GP2-X', 'status' => 'active', 'itemtype' => 'Computer', 'items_id' => 5, 'entities_id' => 1];
ok('código ACTIVO, mismo activo, entidad y public_code ⇒ OK', QrCodeRules::check($meta, 'Computer', 5, 1, 'GP2-X', 0)['ok'] && QrCodeRules::check($meta + ['outcome' => 'created'], 'Computer', 5, 1, 'GP2-X', 7)['ok']);
ok('sin código ⇒ qr_code_missing', QrCodeRules::check(null, 'Computer', 5, 1, 'GP2-X', 0)['class'] === 'qr_code_missing');
$leak = QrCodeRules::check($meta + ['token' => 'abcdefghijklmnopqrstuvwxyz234567'], 'Computer', 5, 1, 'GP2-X', 0);
ok('🔒 metadatos con el token ⇒ fail-closed (qr_meta_unexpected) y el motivo NO contiene el valor', $leak['class'] === 'qr_meta_unexpected'
    && !str_contains($leak['reason'], 'abcdefghijklmnopqrstuvwxyz234567'));
ok('código distinto del registrado en la saga ⇒ qr_code_mismatch', QrCodeRules::check($meta, 'Computer', 5, 1, 'GP2-X', 8)['class'] === 'qr_code_mismatch');
ok('🔒 código de otro activo ⇒ qr_code_other_asset', QrCodeRules::check(['items_id' => 6] + $meta, 'Computer', 5, 1, 'GP2-X', 0)['class'] === 'qr_code_other_asset'
    && QrCodeRules::check(['itemtype' => 'Monitor'] + $meta, 'Computer', 5, 1, 'GP2-X', 0)['class'] === 'qr_code_other_asset');
ok('🔒 REVOKED / SUSPENDED / otro ⇒ revisión manual (nunca se rota ni reactiva)', QrCodeRules::check(['status' => 'revoked'] + $meta, 'Computer', 5, 1, 'GP2-X', 0)['class'] === 'qr_code_revoked'
    && QrCodeRules::check(['status' => 'suspended'] + $meta, 'Computer', 5, 1, 'GP2-X', 0)['class'] === 'qr_code_suspended'
    && QrCodeRules::check(['status' => 'weird'] + $meta, 'Computer', 5, 1, 'GP2-X', 0)['class'] === 'qr_code_inactive');
ok('🔒 código de otra entidad ⇒ qr_code_entity', QrCodeRules::check(['entities_id' => 2] + $meta, 'Computer', 5, 1, 'GP2-X', 0)['class'] === 'qr_code_entity');
ok('🔒 public_code ≠ número de inventario de la unidad ⇒ qr_public_code', QrCodeRules::check(['public_code' => 'PC-000001'] + $meta, 'Computer', 5, 1, 'GP2-X', 0)['class'] === 'qr_public_code');
ok('isPdf: cabecera %PDF- + %%EOF + tamaño; vacío/basura/truncado ⇒ no', QrCodeRules::isPdf("%PDF-1.7\n" . str_repeat('x', 80) . "\n%%EOF\n")
    && !QrCodeRules::isPdf('') && !QrCodeRules::isPdf('<html>' . str_repeat('x', 90) . '%%EOF') && !QrCodeRules::isPdf("%PDF-1.7\n" . str_repeat('x', 90)));
ok('estados nuevos registrados; COMPLETED fuera de las reanudables; BRIDGED reanudable', in_array(SagaState::QR_READY, SagaState::ALL, true)
    && in_array(SagaState::COMPLETED, SagaState::ALL, true) && in_array(SagaState::BRIDGED, SagaState::RESUMABLE, true)
    && !in_array(SagaState::COMPLETED, SagaState::RESUMABLE, true) && !in_array(SagaState::QR_READY, SagaState::RESUMABLE, true));

// =====================================================================================================================
echo "== SI4-3 · E2E en memoria: BRIDGED ⇒ QR_READY ⇒ ack ⇒ COMPLETED ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-Q-E2E']);
$m = $W->worker3()->run();
$s = $W->sagas->get($u);
$gid = (int) $s['glpi_items_id'];
$code = $W->qr->find('Computer', $gid) ?? [];
ok('una pasada: Snipe ⇒ GLPI ⇒ Infocom ⇒ puente ⇒ QR ⇒ ack ⇒ COMPLETED', $m['completed'] === 1 && $m['claimed'] === 1 && $s['state'] === SagaState::COMPLETED
    && $m['snipe_created'] === 1 && $m['glpi_created'] === 1 && $m['qr_created'] === 1 && $m['aborted'] === null);
ok('código companyqr ACTIVO del activo, public_code = número de inventario = tag de Snipe', ($code['status'] ?? '') === 'active'
    && ($code['public_code'] ?? '') === $W->tag($u) && ($W->glpi->items['Computer'][$gid]['otherserial'] ?? '') === $W->tag($u) && ($code['entities_id'] ?? 0) === 1);
ok('saga: qr_code_id, qr_public_code, qr_outcome, label_ready_at y completed_at', (int) $s['qr_code_id'] === (int) ($code['code_id'] ?? -1)
    && $s['qr_public_code'] === $W->tag($u) && $s['qr_outcome'] === SagaState::OUTCOME_CREATED && $s['label_ready_at'] !== null && $s['completed_at'] !== null);
ok('etiqueta renderizada con el renderer de companyqr (validada, no guardada)', $W->qr->renderCalls === 1);
ok('outbox DONE exactamente una vez, con el lease vigente', $W->source->row($u)['status'] === 'DONE' && si4qDone($W, $u) === 1 && count($W->source->acks) === 1);
$events = array_column(array_filter($W->sagas->log, static fn (array $e): bool => $e['uuid'] === $u), 'event');
$iBridged = array_search('bridged', $events, true);
$iLinked = array_search('qr_code_linked', $events, true);
$iReady = array_search('qr_ready', $events, true);
$iDone = array_search('completed', $events, true);
ok('bitácora en orden: bridged → qr_code_linked → qr_ready → completed', $iBridged !== false && $iLinked > $iBridged && $iReady > $iLinked && $iDone > $iReady);
ok('🔒 el token del QR NO está en la saga, la bitácora, el puente ni los logs', !str_contains(si4qDump($W), $code['token'] ?? 'x'));
ok('🔒 la saga no tiene columna para el token ni para el PDF', preg_grep('/token|pdf|label_(blob|data|pdf)/i', array_diff(array_keys($s), ['lease_token_sha256'])) === []);
$m2 = $W->worker3()->run();
ok('re-ejecutar: nada que reclamar ni confirmar (outbox DONE)', $m2['claimed'] === 0 && $m2['finalized'] === 0 && count($W->source->acks) === 1 && $W->qr->createCalls === 1);
ok('🔒 COMPLETED sólo por complete(): una transición común lo rechaza', si4Throws(fn () => $W->sagas->transition($u, (string) $s['lease_token_sha256'],
    SagaState::COMPLETED, ['state' => SagaState::COMPLETED], 'x')));
ok('🔒 label_ready_at sólo con el reloj del store (SagaStore::NOW)', SagaStore::NOW === '@db-now' && is_int($s['label_ready_at']));

// =====================================================================================================================
echo "== SI4-3 · Upgrade: saga BRIDGED de SI4-2 ⇒ worker SI4-3 ⇒ COMPLETED ==\n";
$W = new Si4World();
$u = si4qBridged($W);
ok('modo SI4-2: BRIDGED, sin código QR, sin ack', $W->sagas->get($u)['state'] === SagaState::BRIDGED && $W->qr->codes === [] && $W->source->acks === []);
$posts = $W->posts();
$adds = $W->glpi->addCalls;
$reqs = count($W->snipe->requests);
$m = $W->worker3()->run();
ok('SI4-3 reanuda desde BRIDGED hasta COMPLETED sin volver a Snipe ni a la etapa GLPI', $m['completed'] === 1 && $W->sagas->get($u)['state'] === SagaState::COMPLETED
    && $W->posts() === $posts && $W->glpi->addCalls === $adds && si4qOnes($W, $u) && si4qDone($W, $u) === 1);
ok('🔒 desde BRIDGED no se lee Snipe (sólo el preflight de la corrida): el puente es la fuente de verdad', array_filter(array_slice($W->snipe->requests, $reqs),
    static fn (array $r): bool => !str_contains((string) $r['url'], '/statuslabels/')) === []);

// =====================================================================================================================
echo "== SI4-3 · Código existente (get-or-create idempotente; nunca rotar ni reactivar) ==\n";
$W = new Si4World();
$u = si4qBridged($W);
$gid = (int) $W->sagas->get($u)['glpi_items_id'];
$human = $W->qr->create('Computer', $gid); // un humano ya había generado el código del activo (public_code = otherserial)
$W->worker3()->run();
$s = $W->sagas->get($u);
ok('código ACTIVO preexistente ⇒ se REUTILIZA (qr_outcome existing), sin crear otro', $s['state'] === SagaState::COMPLETED && (int) $s['qr_code_id'] === $human['code_id']
    && $s['qr_outcome'] === SagaState::OUTCOME_EXISTING && $W->qr->countFor('Computer', $gid) === 1 && $W->qr->createCalls === 1);
foreach (['revoked' => 'qr_code_revoked', 'suspended' => 'qr_code_suspended'] as $status => $class) {
    $W = new Si4World();
    $u = si4qBridged($W);
    $gid = (int) $W->sagas->get($u)['glpi_items_id'];
    $c = $W->qr->create('Computer', $gid);
    $W->qr->codes[$c['code_id']]['status'] = $status;
    $m = $W->worker3()->run();
    $s = $W->sagas->get($u);
    $after = $W->qr->codes[$c['code_id']];
    ok("🔒 código {$status} ⇒ MANUAL_REVIEW sin ack; NO se rota ni se reactiva", $m['manual_review'] === 1 && $s['state'] === SagaState::MANUAL_REVIEW
        && $s['last_error_class'] === $class && $after['status'] === $status && $after['token'] === $c['token'] && $W->source->acksApplied === []
        && $W->source->row($u)['status'] === 'ERROR' && $W->qr->countFor('Computer', $gid) === 1 && $s['qr_code_id'] === null);
}
$W = new Si4World();
$u = si4qBridged($W);
$gid = (int) $W->sagas->get($u)['glpi_items_id'];
$W->qr->create('Computer', $gid, null, 'PC-000777'); // código generado antes con otro código visible
$W->worker3()->run();
ok('🔒 public_code ≠ número de inventario ⇒ MANUAL_REVIEW sin ack', $W->sagas->get($u)['last_error_class'] === 'qr_public_code' && $W->source->acksApplied === []);
$W = new Si4World();
$u = si4qBridged($W);
$gid = (int) $W->sagas->get($u)['glpi_items_id'];
$c = $W->qr->create('Computer', $gid);
$W->qr->codes[$c['code_id']]['entities_id'] = 2;
$W->worker3()->run();
ok('🔒 código de otra entidad ⇒ MANUAL_REVIEW sin ack', $W->sagas->get($u)['last_error_class'] === 'qr_code_entity' && $W->source->acksApplied === []);

// =====================================================================================================================
echo "== SI4-3 · Crash points (§8): siempre 1 activo Snipe/GLPI, 1 Infocom, 1 puente, 1 código y DONE una sola vez ==\n";
foreach (['before_qr', 'after_qr_code', 'after_qr_persisted', 'after_label_render', 'after_qr_ready', 'before_ack', 'after_ack', 'after_completed'] as $pt) {
    $W = new Si4World();
    $u = $W->unit(['serial' => 'SN-QC-' . $pt]);
    $crashed = si4qRunCrash($W, $pt);
    $s1 = $W->sagas->get($u) ?? [];
    $acked = in_array($pt, ['after_ack', 'after_completed'], true);
    $firstCode = $W->qr->find('Computer', (int) ($s1['glpi_items_id'] ?? 0));
    $beforeOk = $crashed && ($acked ? $W->source->row($u)['status'] === 'DONE' : ($W->source->row($u)['status'] === 'LEASED' && $W->source->acksApplied === []))
        && ($pt === 'after_completed' ? $s1['state'] === SagaState::COMPLETED : $s1['state'] !== SagaState::COMPLETED);
    si4gExpire($W, $u);
    $m = $W->worker3()->run();
    $s = $W->sagas->get($u) ?? [];
    $sameCode = $firstCode === null || (int) $s['qr_code_id'] === $firstCode['code_id'];
    $finalizedOk = $pt !== 'after_ack' || ($m['finalized'] === 1 && $m['claimed'] === 0);
    ok("«{$pt}» ⇒ " . ($acked ? 'outbox ya DONE; ' : 'sin DONE hasta el ack; ') . 'el siguiente worker converge a COMPLETED con todo en 1',
        $beforeOk && $s['state'] === SagaState::COMPLETED && $W->source->row($u)['status'] === 'DONE' && si4qDone($W, $u) === 1
        && si4qOnes($W, $u) && $sameCode && $finalizedOk && $W->qr->createCalls === 1);
}
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-QC-same']);
si4qRunCrash($W, 'after_qr_code');
$s = $W->sagas->get($u);
$first = $W->qr->find('Computer', (int) $s['glpi_items_id']);
ok('🔒 crash tras crear el código y ANTES de registrarlo: la saga no lo tiene, pero existe en companyqr', $s['qr_code_id'] === null && $first !== null);
si4gExpire($W, $u);
$W->worker3()->run();
$s = $W->sagas->get($u);
ok('🔒 el retry encuentra el MISMO código (get-or-create por activo): nunca dos', (int) $s['qr_code_id'] === $first['code_id'] && $W->qr->createCalls === 1
    && $s['qr_outcome'] === SagaState::OUTCOME_EXISTING);

// =====================================================================================================================
echo "== SI4-3 · Dos workers ⇒ un solo código ==\n";
$W = new Si4World();
$u = si4qBridged($W);
$gid = (int) $W->sagas->get($u)['glpi_items_id'];
$raced = null;
$W->qr->beforeCreate = function (string $t, int $id) use ($W, &$raced): void {
    $raced = $W->qr->create($t, $id); // otro proceso crea el código del MISMO activo entre "buscar" y "crear"
    $W->qr->beforeCreate = null;
};
$W->worker3()->run();
ok('🔒 carrera get-or-create ⇒ se reutiliza el código del otro proceso (UNIQUE item), COMPLETED', $raced !== null
    && (int) $W->sagas->get($u)['qr_code_id'] === $raced['code_id'] && $W->qr->countFor('Computer', $gid) === 1 && $W->sagas->get($u)['state'] === SagaState::COMPLETED);
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-Q-ZOMBIE']);
si4qRunCrash($W, 'after_qr_code');
$zombie = (string) $W->sagas->get($u)['lease_token_sha256'];
si4gExpire($W, $u);
$W->worker3()->run();
$fpSaga = json_encode($W->sagas->get($u));
$stale = (new Si4QrStage($W->sagas, $W->bridges, $W->glpi, $W->qr))->advance($u, $zombie);
ok('🔒 el worker viejo (lease re-tomado) no escribe ni crea otro código', $stale['kind'] === Si4QrStage::O_LEASE_LOST
    && json_encode($W->sagas->get($u)) === $fpSaga && $W->qr->createCalls === 1);
ok('🔒 tras la toma: 1 código, COMPLETED, DONE una vez', $W->qr->createCalls === 1 && $W->sagas->get($u)['state'] === SagaState::COMPLETED && si4qDone($W, $u) === 1);
$W = new Si4World();
$u = si4qBridged($W);
$stage = new Si4QrStage($W->sagas, $W->bridges, $W->glpi, $W->qr);
ok('🔒 dueño viejo con BRIDGED (otro token) ⇒ LEASE_LOST sin crear código', $stage->advance($u, hash('sha256', 'viejo'))['kind'] === Si4QrStage::O_LEASE_LOST
    && $W->qr->codes === []);

// =====================================================================================================================
echo "== SI4-3 · ACK fallido: la saga queda QR_READY, el outbox sin DONE; el reintento NO rehace nada ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-Q-ACKF']);
$W->source->failAcks = 1;
$m = $W->worker3()->run();
$s = $W->sagas->get($u);
$ensure = $W->qr->ensureCalls;
$render = $W->qr->renderCalls;
$posts = $W->posts();
$adds = $W->glpi->addCalls;
ok('ack fallido ⇒ QR_READY + last_error_class ack + outbox RETRY (no DONE)', $m['qr_ready'] === 1 && $s['state'] === SagaState::QR_READY
    && $s['last_error_class'] === 'ack' && $W->source->row($u)['status'] === 'RETRY' && $W->source->acksApplied === []);
$f = (new Si4Finalizer($W->sagas, $W->source, $W->finCursor))->run();
ok('🔒 finalizador con el outbox ≠ DONE ⇒ NO marca COMPLETED', $f['pending'] === 1 && $f['completed'] === 0 && $W->sagas->get($u)['state'] === SagaState::QR_READY);
$W->now += 4000;
$m = $W->worker3()->run();
ok('reintento: revalida y confirma ⇒ COMPLETED, DONE una vez', $m['completed'] === 1 && $W->sagas->get($u)['state'] === SagaState::COMPLETED && si4qDone($W, $u) === 1);
ok('🔒 el reintento desde QR_READY no rehízo nada (ni Snipe, ni GLPI, ni código, ni etiqueta)', $W->posts() === $posts && $W->glpi->addCalls === $adds
    && $W->qr->ensureCalls === $ensure && $W->qr->renderCalls === $render && si4qOnes($W, $u));
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-Q-ACKL']);
si4qRunCrash($W, 'before_ack');
$W->source->expire($u); // el lease vence justo antes del ack: Compras lo rechaza
si4gExpire($W, $u);
ok('lease vencido antes del ack ⇒ outbox reclamable, saga QR_READY', $W->sagas->get($u)['state'] === SagaState::QR_READY && $W->source->acksApplied === []);
$W->worker3()->run();
ok('el nuevo dueño confirma con SU token ⇒ COMPLETED', $W->sagas->get($u)['state'] === SagaState::COMPLETED && si4qDone($W, $u) === 1 && si4qOnes($W, $u));

// =====================================================================================================================
echo "== SI4-3 · Finalizador durable ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-Q-FIN']);
si4qRunCrash($W, 'after_ack');
$fin = new Si4Finalizer($W->sagas, $W->source, $W->finCursor);
ok('crash tras el ack: QR_READY con el outbox DONE', $W->sagas->get($u)['state'] === SagaState::QR_READY && $W->source->row($u)['status'] === 'DONE');
$f = $fin->run();
ok('finalizador ⇒ COMPLETED (sin lease, guarda state = QR_READY)', $f['completed'] === 1 && $W->sagas->get($u)['state'] === SagaState::COMPLETED
    && $W->sagas->get($u)['completed_at'] !== null);
ok('finalizador idempotente (nada más que cerrar; complete() repetido = false)', $fin->run()['scanned'] === 0 && !$W->sagas->complete($u, 'x') && si4qDone($W, $u) === 1);
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-Q-FIN2']);
si4qRunCrash($W, 'after_ack');
$W->source->setActiveEntities([2]);
ok('handoff no visible para la sesión ⇒ no se completa (skipped)', (new Si4Finalizer($W->sagas, $W->source, $W->finCursor))->run()['skipped'] === 1
    && $W->sagas->get($u)['state'] === SagaState::QR_READY);
$W->source->setActiveEntities([1, 2]);
$W->sagas->rows[$u]['payload_sha256'] = str_repeat('0', 64);
ok('🔒 payload distinto del procesado ⇒ no se completa', (new Si4Finalizer($W->sagas, $W->source, $W->finCursor))->finalizeOne($u) === Si4Finalizer::F_SKIPPED
    && $W->sagas->get($u)['state'] === SagaState::QR_READY);

// =====================================================================================================================
echo "== SI4-3 · Finalizador: recorrido round-robin acotado con cursor DURABLE y wrap-around ==\n";

/**
 * Saga en QR_READY con su outbox en el estado pedido (DONE = ack aplicado y crash antes de COMPLETED; RETRY; LEASED),
 * armada directamente con los dobles (sólo importa al finalizador).
 *
 * @return array{0:string,1:string} [uuid, lease token]
 */
function si4qQrReady(Si4World $W, string $outbox): array
{
    $u = $W->unit(['serial' => 'SN-FC-' . bin2hex(random_bytes(3))]);
    $c = $W->source->claimPending('w-fc', 1, 900)[0];
    $sha = hash('sha256', $c['lease_token']);
    $W->sagas->acquire($u, ['entities_id' => 1, 'requests_id' => 11, 'items_id' => 21, 'payload_sha256' => $c['payload_sha256'], 'correlation_id' => 'fc'],
        $sha, $c['leased_until'], (int) $c['attempts'], 'w-fc');
    $W->sagas->transition($u, $sha, SagaState::PENDING, ['state' => SagaState::QR_READY], 'qr_ready');
    if ($outbox === 'DONE') {
        $W->source->acknowledgeProcessed($u, $c['lease_token']);
    } elseif ($outbox === 'RETRY') {
        $W->source->markRetry($u, $c['lease_token'], 'pendiente', new \DateTimeImmutable('@' . ($W->now + 3600)));
    }
    return [$u, $c['lease_token']];
}

/** Estado de la saga. */
function si4qState(Si4World $W, string $u): string
{
    return (string) $W->sagas->get($u)['state'];
}

$W = new Si4World();
[$u1] = si4qQrReady($W, 'RETRY');
[$u2] = si4qQrReady($W, 'LEASED');
[$u3] = si4qQrReady($W, 'DONE');
[$u4] = si4qQrReady($W, 'DONE');
[$u5] = si4qQrReady($W, 'LEASED');
$id = static fn (string $u): int => (int) $W->sagas->get($u)['id'];
$cursor = $W->finCursor; // "tabla propia": sobrevive a cada Si4Finalizer (otro proceso CLI)
$fin = static fn (): Si4Finalizer => new Si4Finalizer($W->sagas, $W->source, $cursor, null, 2);
$r1 = $fin()->run();
ok('corrida 1 (batch 2): inspecciona 1 y 2 (pendientes), completa 0; el cursor queda en la saga 2 (última INSPECCIONADA)', $r1['scanned'] === 2
    && $r1['completed'] === 0 && $r1['pending'] === 2 && $cursor->get() === $id($u2));
$r2 = $fin()->run();
ok('🔒 corrida 2 (Si4Finalizer RECONSTRUIDO): llega a 3 y 4 ⇒ ambas COMPLETED (1 y 2 no las bloquean)', $r2['scanned'] === 2 && $r2['completed'] === 2
    && si4qState($W, $u3) === SagaState::COMPLETED && si4qState($W, $u4) === SagaState::COMPLETED && $cursor->get() === $id($u4));
$r3 = $fin()->run();
ok('corrida 3: alcanza el fin (saga 5, lote incompleto) ⇒ wrap-around (cursor = 0)', $r3['scanned'] === 1 && $r3['pending'] === 1 && $r3['cursor_to'] === 0
    && $cursor->get() === 0);
$W->now += 4000;
$claim = $W->source->claimPending('w-fc2', 1, 900);
$W->source->acknowledgeProcessed($u1, $claim[0]['lease_token']);
$done1 = false;
for ($i = 0; $i < 3 && !$done1; $i++) {
    $fin()->run();
    $done1 = si4qState($W, $u1) === SagaState::COMPLETED;
}
ok('🔒 la saga 1 pasa a outbox DONE ⇒ la ronda siguiente la completa (nunca abandonada)', ($claim[0]['receipt_unit_uuid'] ?? '') === $u1 && $done1);
ok('🔒 nunca COMPLETED con el outbox ≠ DONE: 2 y 5 siguen QR_READY', si4qState($W, $u2) === SagaState::QR_READY && si4qState($W, $u5) === SagaState::QR_READY);
$cursor->value = 99999;
$rw = $fin()->run();
ok('cursor más allá de la última saga ⇒ wrap-around en la MISMA corrida', $rw['wrapped'] && $rw['scanned'] === 2 && $rw['cursor_from'] === 99999);

// Propiedad: más sagas que el lote, con las DONE al final y en posiciones aleatorias (semilla fija).
foreach (['DONE al final' => static fn (int $i): bool => $i >= 25, 'DONE aleatorias' => static fn (int $i): bool => (($i * 7919) % 5) === 0] as $label => $isDone) {
    $W = new Si4World();
    $cursor = $W->finCursor;
    $done = $pending = [];
    for ($i = 1; $i <= 32; $i++) {
        [$u] = si4qQrReady($W, $isDone($i) ? 'DONE' : ($i % 2 === 0 ? 'RETRY' : 'LEASED'));
        if ($isDone($i)) {
            $done[] = $u;
        } else {
            $pending[] = $u;
        }
    }
    $runs = 0;
    $all = static fn (): bool => array_filter($done, static fn (string $u): bool => si4qState($W, $u) !== SagaState::COMPLETED) === [];
    while (!$all() && $runs < 40) {
        (new Si4Finalizer($W->sagas, $W->source, $cursor, null, 2))->run(); // un proceso CLI nuevo en cada corrida
        $runs++;
    }
    ok("🔒 propiedad ({$label}, 32 sagas, lote 2): TODAS las DONE (" . count($done) . ') terminan COMPLETED en ' . $runs . ' corridas; ninguna pendiente se completa',
        $done !== [] && $all() && $runs <= 17 && array_filter($pending, static fn (string $u): bool => si4qState($W, $u) !== SagaState::QR_READY) === []);
}

// Concurrencia: otra corrida mueve el cursor entre get() y advance() ⇒ compare-and-set no lo pisa (nunca retrocede).
$W = new Si4World();
for ($i = 1; $i <= 6; $i++) {
    si4qQrReady($W, 'LEASED');
}
$inner = $W->finCursor;
$racy = new class ($inner) implements GlpiPlugin\Companyintegrations\Si4\FinalizerCursor {
    /** @var callable|null */
    public $race = null;

    public function __construct(private GlpiPlugin\Companyintegrations\Si4\FinalizerCursor $inner)
    {
    }

    public function get(): int
    {
        return $this->inner->get();
    }

    public function advance(int $expected, int $next): bool
    {
        if ($this->race !== null) {
            ($this->race)();
            $this->race = null;
        }
        return $this->inner->advance($expected, $next);
    }
};
$racy->race = static function () use ($inner): void {
    $inner->advance($inner->get(), 4); // otra corrida inspeccionó hasta la saga 4
};
(new Si4Finalizer($W->sagas, $W->source, $racy, null, 2))->run();
ok('🔒 carrera: el avance obsoleto (0→2) no pisa al concurrente (0→4): el cursor no retrocede ni se corrompe', $inner->get() === 4);
$r = (new Si4Finalizer($W->sagas, $W->source, $inner, null, 2))->run();
ok('tras la carrera el recorrido sigue desde 4 (cobertura futura intacta)', $r['cursor_from'] === 4 && $r['scanned'] === 2 && $inner->get() === 6);

// =====================================================================================================================
echo "== SI4-3 · Revalidación previa al ack: divergencia ⇒ MANUAL_REVIEW sin ack ==\n";
$cases = [
    'código revocado'              => [static function (Si4World $W, array $s): void { $W->qr->codes[(int) $s['qr_code_id']]['status'] = 'revoked'; }, 'qr_code_revoked'],
    'código apunta a otro activo'  => [static function (Si4World $W, array $s): void {
        $W->qr->codes[(int) $s['qr_code_id']]['items_id'] = $W->glpi->create('Computer', ['entities_id' => 1, 'serial' => 'OTRO', 'otherserial' => 'OTRO', 'name' => 'x', 'model_id' => 0]);
    }, 'qr_code_other_asset'],
    'código de un activo purgado'  => [static function (Si4World $W, array $s): void { $W->qr->codes[(int) $s['qr_code_id']]['items_id'] = 999; }, 'qr_not_found'],
    'código visible cambiado'      => [static function (Si4World $W, array $s): void { $W->qr->codes[(int) $s['qr_code_id']]['public_code'] = 'OTRO-1'; }, 'qr_public_code'],
    'puente reasignado'            => [static function (Si4World $W, array $s): void { $W->bridges->rows[(int) $s['asset_bridge_id']]['glpi_items_id'] = 999; }, 'bridge_diverged'],
    'puente de otra unidad'        => [static function (Si4World $W, array $s): void { $W->bridges->rows[(int) $s['asset_bridge_id']]['receipt_unit_uuid'] = 'otra'; }, 'bridge_diverged'],
    'activo GLPI en la papelera'   => [static function (Si4World $W, array $s): void { $W->glpi->items['Computer'][(int) $s['glpi_items_id']]['is_deleted'] = 1; }, 'glpi_asset_diverged'],
    'activo GLPI en otra entidad'  => [static function (Si4World $W, array $s): void { $W->glpi->items['Computer'][(int) $s['glpi_items_id']]['entities_id'] = 2; }, 'glpi_asset_diverged'],
];
foreach ($cases as $label => [$mutate, $class]) {
    $W = new Si4World();
    $u = $W->unit(['serial' => 'SN-QV-' . substr(md5($label), 0, 6)]);
    si4qRunCrash($W, 'after_qr_ready');
    $mutate($W, $W->sagas->get($u));
    si4gExpire($W, $u);
    $m = $W->worker3()->run();
    $s = $W->sagas->get($u);
    ok("🔒 {$label} entre QR_READY y el ack ⇒ MANUAL_REVIEW ({$class}), sin ack, outbox ERROR", $m['manual_review'] === 1 && $s['state'] === SagaState::MANUAL_REVIEW
        && $s['last_error_class'] === $class && $W->source->acksApplied === [] && $W->source->row($u)['status'] === 'ERROR');
}

// =====================================================================================================================
echo "== SI4-3 · ACL de companyqr (RIGHT_GENERATE / RIGHT_PRINT) y multi-entidad ==\n";
foreach (['canGenerate' => 'RIGHT_GENERATE', 'canPrint' => 'RIGHT_PRINT', 'available' => 'companyqr inactivo'] as $knob => $label) {
    $W = new Si4World();
    $u = $W->unit(['serial' => 'SN-QA-' . $knob]);
    $W->qr->{$knob} = false;
    $m = $W->worker3()->run();
    $s = $W->sagas->get($u);
    ok("🔒 sin {$label} ⇒ BLOCKED_CONFIG (resume BRIDGED), sin código, sin ack, outbox RETRY", $m['blocked_config'] === 1 && $s['state'] === SagaState::BLOCKED_CONFIG
        && $s['resume_state'] === SagaState::BRIDGED && $s['last_error_class'] === 'qr_acl' && $W->qr->codes === [] && $W->source->acksApplied === []
        && $W->source->row($u)['status'] === 'RETRY');
    $W->qr->{$knob} = true;
    $W->now += 4000;
    $m = $W->worker3()->run();
    ok("otorgado {$label} ⇒ reanuda desde BRIDGED hasta COMPLETED (1 código)", $m['completed'] === 1 && $W->sagas->get($u)['state'] === SagaState::COMPLETED && si4qOnes($W, $u));
}
$W = new Si4World();
$u = si4qBridged($W, ['entity_id' => 2, 'supplier_id' => 4]);
$W->glpi->entityAccess[2] = false;
$m = $W->worker3()->run();
ok('🔒 activo de una entidad que el usuario técnico no ve ⇒ BLOCKED_CONFIG (qr_acl), sin código', $m['blocked_config'] === 1
    && $W->sagas->get($u)['last_error_class'] === 'qr_acl' && $W->qr->codes === []);
$W->glpi->entityAccess[2] = true;
$W->now += 4000;
$W->worker3()->run();
$s = $W->sagas->get($u);
ok('con acceso ⇒ código de la entidad de la unidad (2) y COMPLETED', $s['state'] === SagaState::COMPLETED && ($W->qr->codes[(int) $s['qr_code_id']]['entities_id'] ?? 0) === 2);
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-Q-RENDER']);
$W->qr->renderFails = true;
$m = $W->worker3()->run();
$s = $W->sagas->get($u);
ok('etiqueta no renderizable ⇒ BLOCKED_CONFIG (qr_label), código ya registrado, sin QR_READY ni ack', $m['blocked_config'] === 1 && $s['last_error_class'] === 'qr_label'
    && (int) $s['qr_code_id'] > 0 && $s['label_ready_at'] === null && $W->source->acksApplied === []);
$W->qr->renderFails = false;
$W->qr->renderOutput = '<html>no soy un PDF</html>' . str_repeat(' ', 80);
$W->now += 4000;
$W->worker3()->run();
ok('salida que no es un PDF ⇒ sigue BLOCKED_CONFIG (qr_label)', $W->sagas->get($u)['state'] === SagaState::BLOCKED_CONFIG && $W->sagas->get($u)['last_error_class'] === 'qr_label');
$W->qr->renderOutput = null;
$W->now += 4000;
$W->worker3()->run();
ok('etiqueta reparada ⇒ COMPLETED con el MISMO código', $W->sagas->get($u)['state'] === SagaState::COMPLETED && $W->qr->createCalls === 1 && si4qOnes($W, $u));

// =====================================================================================================================
echo "== SI4-3 · Token: una API que lo devolviera se frena (fail-closed) y nunca se persiste ==\n";
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-Q-LEAK']);
$W->qr->leakToken = true;
$m = $W->worker3()->run();
$s = $W->sagas->get($u);
$tok = (string) ($W->qr->find('Computer', (int) $s['glpi_items_id'])['token'] ?? 'x');
ok('🔒 metadatos con token ⇒ MANUAL_REVIEW (qr_meta_unexpected), sin ack', $m['manual_review'] === 1 && $s['last_error_class'] === 'qr_meta_unexpected' && $W->source->acksApplied === []);
ok('🔒 el token no quedó en saga, bitácora, puente, outbox ni logs', !str_contains(si4qDump($W), $tok) && !str_contains((string) $W->source->row($u)['last_error'], $tok));

// =====================================================================================================================
echo "== SI4-3 · Guardas de código ==\n";
$src = [];
foreach (glob(dirname(__DIR__, 2) . '/src/*/*.php') ?: [] as $f) {
    if (preg_match('/Selftest/', basename($f)) === 1) {
        continue; // los selftests (código de prueba) ejercitan los adaptadores directamente
    }
    $src[basename($f)] = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($f));
}
$callers = array_keys(array_filter($src, static fn (string $c): bool => preg_match('/->acknowledgeProcessed\(/', $c) === 1));
sort($callers);
ok('🔒 sólo Si4Worker invoca acknowledgeProcessed() (los adaptadores del puerto sólo lo delegan)', $callers === ['PurchasingHandoffSource.php', 'Si4Worker.php']
    && substr_count($src['Si4Worker.php'], '->acknowledgeProcessed(') === 1);
$w = $src['Si4Worker.php'];
$cq = substr($w, (int) strpos($w, 'function continueQr'));
ok('🔒 en continueQr el ack va DESPUÉS de advance() (QR_READY) y de revalidateForAck()', strpos($cq, '->advance(') !== false
    && strpos($cq, '->revalidateForAck(') > strpos($cq, '->advance(') && strpos($cq, '->acknowledgeProcessed(') > strpos($cq, '->revalidateForAck('));
$qrUsers = array_keys(array_filter($src, static fn (string $c): bool => str_contains($c, 'GlpiPlugin\\Companyqr\\')));
sort($qrUsers);
ok('🔒 companyqr sólo por su API pública (CoreQrGateway) y su contrato de derechos (WorkerSession)', $qrUsers === ['CoreQrGateway.php', 'WorkerSession.php']);
$all = implode("\n", $src);
ok('🔒 ni tablas privadas de companyqr ni la ruta /scan armada en companyintegrations', stripos($all, 'glpi_plugin_companyqr') === false && stripos($all, '/scan/') === false);
ok('🔒 COMPLETED sólo lo escribe complete() (finalizador)', !preg_match("/'state'\s*=>\s*SagaState::COMPLETED/", $all));
