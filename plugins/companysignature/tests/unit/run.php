<?php

/**
 * Tests UNITARIOS puros de companysignature (sin bootstrap de GLPI).
 *
 * Ejercitan la lógica que NO depende del core (gate §11/§14): canonicalización determinista +
 * hash reproducible, clave de idempotencia, tokens opacos, detección de cambio, y el puerto de
 * firma certificada (NullSigner). Se ejecuta en el job estático de CI:
 *   php plugins/companysignature/tests/unit/run.php
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

$svc = dirname(__DIR__, 2) . '/src/Service/';
require $svc . 'Canonicalizer.php';
require $svc . 'Hasher.php';
require $svc . 'IdempotencyKey.php';
require $svc . 'TokenGenerator.php';
require $svc . 'SubstantiveChange.php';
require $svc . 'SignatureResult.php';
require $svc . 'CertifiedSignerInterface.php';
require $svc . 'NullSigner.php';

use GlpiPlugin\Companysignature\Service\Canonicalizer;
use GlpiPlugin\Companysignature\Service\Hasher;
use GlpiPlugin\Companysignature\Service\IdempotencyKey;
use GlpiPlugin\Companysignature\Service\NullSigner;
use GlpiPlugin\Companysignature\Service\SubstantiveChange;
use GlpiPlugin\Companysignature\Service\TokenGenerator;

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

/** Snapshot de ejemplo, domain-agnostic (payload arbitrario suministrado por el dominio). */
function snap(array $payload, int $version = 1, string $schema = 'demo/v1'): array
{
    return [
        'schema'           => $schema,
        'subject_type'     => 'Computer',
        'subject_id'       => 42,
        'entity_id'        => 3,
        'document_version' => $version,
        'payload'          => $payload,
    ];
}

echo "== Canonicalizer / Hasher (D4 · §14) ==\n";
$c = new Canonicalizer();
$h = new Hasher($c);

// (1) Misma representación lógica con distinto ORDEN de claves → mismo hash.
$a = snap(['b' => 2, 'a' => 1, 'nested' => ['y' => 'Y', 'x' => 'X']]);
$b = snap(['nested' => ['x' => 'X', 'y' => 'Y'], 'a' => 1, 'b' => 2]);
ok('orden de claves no cambia el canónico', $c->canonical($a) === $c->canonical($b));
ok('orden de claves no cambia el hash', $h->contentHash($a) === $h->contentHash($b));

// (2) Cambio sustantivo → hash diferente.
$cchg = snap(['b' => 2, 'a' => 1, 'nested' => ['y' => 'Y', 'x' => 'X-CHANGED']]);
ok('cambio de contenido → hash distinto', $h->contentHash($a) !== $h->contentHash($cchg));

// (3) schema y document_version PARTICIPAN del hash.
ok('schema participa del hash', $h->contentHash(snap(['a' => 1], 1, 'demo/v1')) !== $h->contentHash(snap(['a' => 1], 1, 'demo/v2')));
ok('document_version participa del hash', $h->contentHash(snap(['a' => 1], 1)) !== $h->contentHash(snap(['a' => 1], 2)));

// (4) Los arrays de LISTA preservan orden (no se reordenan) → distinto orden = distinto hash.
ok('listas preservan orden (semántico)', $h->contentHash(snap(['items' => [1, 2, 3]])) !== $h->contentHash(snap(['items' => [3, 2, 1]])));

// (5) Decimales/montos como STRING exacto son estables; un FLOAT se rechaza (fail-closed).
ok('decimal como string es válido y estable', $h->contentHash(snap(['amount' => '1000.00'])) === $h->contentHash(snap(['amount' => '1000.00'])));
$threw = false;
try {
    $c->canonical(snap(['amount' => 1000.50])); // float binario
} catch (\InvalidArgumentException) {
    $threw = true;
}
ok('float en el snapshot → rechazado (fail-closed)', $threw);

// (6) Contrato: falta una clave obligatoria → excepción.
$threw = false;
try {
    $c->canonical(['schema' => 'x', 'payload' => []]); // faltan subject_type, etc.
} catch (\InvalidArgumentException) {
    $threw = true;
}
ok('snapshot incompleto → excepción', $threw);

// (7) Reproducibilidad simple + longitud de hash.
$hash = $h->contentHash(snap(['a' => 1]));
ok('sha256 hex (64 chars)', strlen($hash) === 64 && preg_match('/^[0-9a-f]+$/', $hash) === 1);
ok('null/bool/string normalizados sin romper', is_string($c->canonical(snap(['n' => null, 't' => true, 'f' => false, 's' => 'x']))));

echo "== IdempotencyKey (§3/§13: identidad por id de historial DURABLE) ==\n";
$k = new IdempotencyKey();
$base = $k->forHistoryEvent(1001, 1, 'approved');
ok('replay del MISMO evento histórico → misma clave', $base === $k->forHistoryEvent(1001, 1, 'approved'));
ok('DOS eventos históricos distintos (mismo tipo/versión) → claves distintas', $base !== $k->forHistoryEvent(1002, 1, 'approved'));
ok('distinta document_version → distinta clave', $base !== $k->forHistoryEvent(1001, 2, 'approved'));
ok('distinto tipo/decisión → distinta clave', $base !== $k->forHistoryEvent(1001, 1, 'rejected'));
ok('clave es hash acotado (64 chars)', strlen($base) === 64 && preg_match('/^[0-9a-f]+$/', $base) === 1);
$inv = $k->forInvalidation(2001, 1, 55);
ok('invalidación: determinista (misma evidencia afectada)', $inv === $k->forInvalidation(2001, 1, 55));
ok('invalidación: distinta evidencia afectada → distinta clave', $inv !== $k->forInvalidation(2001, 1, 56));
ok('invalidación difiere de la aprobación', $inv !== $base);

echo "== TokenGenerator (§13) ==\n";
$tg = new TokenGenerator();
$t1 = $tg->generate();
$t2 = $tg->generate();
ok('token bien formado', TokenGenerator::isWellFormed($t1));
ok('dos tokens difieren (no secuencial/aleatorio)', $t1 !== $t2);
ok('charset base32 minúsculas', preg_match('/^[a-z2-7]+$/', $t1) === 1);
ok('token corto → no bien formado', TokenGenerator::isWellFormed('abc') === false);
ok('token con mayúsculas → no bien formado', TokenGenerator::isWellFormed(strtoupper($t1)) === false);
// NO derivado del hash: el token no debe coincidir con el content_sha256 de un contenido.
ok('token NO es el content_sha256', $t1 !== $hash);

echo "== SubstantiveChange (§6) ==\n";
$sc = new SubstantiveChange();
ok('hashes iguales → no difiere', $sc->differs('a', 'a') === false);
ok('hashes distintos → difiere', $sc->differs('a', 'b') === true);

echo "== ReconcileService::safeWatermark (fail-closed §1) ==\n";
require $svc . 'ReconcileService.php'; // sólo se ejercita el helper PURO (no toca BD/GLPI)
$sw = 'GlpiPlugin\\Companysignature\\Service\\ReconcileService';
ok('todo durable → avanza contiguo al último', $sw::safeWatermark(0, [
    ['hid' => 100, 'durable' => true], ['hid' => 101, 'durable' => true], ['hid' => 102, 'durable' => true],
]) === 102);
ok('#100 OK, #101 FALLA, #102 existe → watermark queda en 100', $sw::safeWatermark(0, [
    ['hid' => 100, 'durable' => true], ['hid' => 101, 'durable' => false], ['hid' => 102, 'durable' => true],
]) === 100);
ok('primer evento no durable → no avanza (queda en el actual)', $sw::safeWatermark(50, [
    ['hid' => 51, 'durable' => false], ['hid' => 52, 'durable' => true],
]) === 50);
ok('lista vacía → conserva el watermark', $sw::safeWatermark(7, []) === 7);
ok('nunca retrocede por debajo del actual', $sw::safeWatermark(500, [
    ['hid' => 100, 'durable' => true],
]) === 500);

echo "== NullSigner (puerto certificado, §8) ==\n";
$ns = new NullSigner();
$res = $ns->sign('payload');
ok('NullSigner NO certifica (certified=false)', $res->certified === false);
ok('NullSigner provider = null', $ns->provider() === 'null' && $res->provider === 'null');
ok('NullSigner.verify siempre false', $ns->verify('payload', 'sig') === false);
ok('firma certificada ≠ evidencia interna (sin firma)', $res->signature === '');

echo "== Materializer::checkpointEntryId (invalidación exacta por checkpoint) ==\n";
require_once $svc . 'Materializer.php'; // sólo el helper PURO (no toca BD/GLPI)
$mat = 'GlpiPlugin\\Companysignature\\Service\\Materializer';
// Ledger: start(DRAFT) → submit(A) → decisión jefe → approve(A→B) → decisión compras → invalidación(→B)
$ledger = [
    ['id' => 10, 'event' => 'started', 'from_code' => '', 'to_code' => 'DRAFT'],
    ['id' => 11, 'event' => 'transitioned', 'from_code' => 'DRAFT', 'to_code' => 'A'],
    ['id' => 12, 'event' => 'decision_recorded', 'from_code' => 'A', 'to_code' => 'A'],
    ['id' => 13, 'event' => 'transitioned', 'from_code' => 'A', 'to_code' => 'B'],
    ['id' => 14, 'event' => 'decision_recorded', 'from_code' => 'B', 'to_code' => 'B'],
    ['id' => 15, 'event' => 'approval_invalidated', 'from_code' => 'B', 'to_code' => 'B'],
];
ok('reabrir a B ⇒ entrada = transición A→B (#13): la decisión del jefe (#12) NO se anula', $mat::checkpointEntryId($ledger, 15, 'B') === 13);
ok('reabrir a A ⇒ entrada = submit (#11): se anulan #12 y #14', $mat::checkpointEntryId($ledger, 15, 'A') === 11);
ok('reabrir al inicial ⇒ entrada = started (#10): se anulan todas (compatibilidad)', $mat::checkpointEntryId($ledger, 15, 'DRAFT') === 10);
ok('checkpoint desconocido ⇒ 0 (conservador: anula todas)', $mat::checkpointEntryId($ledger, 15, 'ZZZ') === 0);
ok('checkpoint vacío ⇒ 0', $mat::checkpointEntryId($ledger, 15, '') === 0);
ok('sólo filas ANTERIORES a la invalidación cuentan', $mat::checkpointEntryId(array_merge($ledger, [
    ['id' => 16, 'event' => 'transitioned', 'from_code' => 'A', 'to_code' => 'B'],
]), 15, 'B') === 13);
ok('una invalidación previa que reabrió a B cuenta como entrada', $mat::checkpointEntryId(array_merge($ledger, [
    ['id' => 16, 'event' => 'decision_recorded', 'from_code' => 'B', 'to_code' => 'B'],
    ['id' => 17, 'event' => 'approval_invalidated', 'from_code' => 'B', 'to_code' => 'B'],
]), 17, 'B') === 15);

echo "== Materializer::isVoidedByCheckpoint (por VISITA de estado; ambos órdenes del motor) ==\n";
// Quórum: la decisión se registra ANTES de la transición que sale del estado.
ok('quórum: reabrir a B NO anula la decisión tomada en A (#12)', $mat::isVoidedByCheckpoint($ledger, 12, 13) === false);
ok('quórum: reabrir a B anula la decisión tomada en B (#14)', $mat::isVoidedByCheckpoint($ledger, 14, 13) === true);
ok('quórum: reabrir a A anula ambas', $mat::isVoidedByCheckpoint($ledger, 12, 11) && $mat::isVoidedByCheckpoint($ledger, 14, 11));
// Actor único: el motor registra la decisión DESPUÉS de la fila `transitioned` que sale del estado.
$single = [
    ['id' => 10, 'event' => 'started', 'from_code' => '', 'to_code' => 'DRAFT'],
    ['id' => 11, 'event' => 'transitioned', 'from_code' => 'DRAFT', 'to_code' => 'S1'],
    ['id' => 12, 'event' => 'transitioned', 'from_code' => 'S1', 'to_code' => 'S2'],
    ['id' => 13, 'event' => 'decision_recorded', 'from_code' => 'S1', 'to_code' => 'S2'],
    ['id' => 14, 'event' => 'transitioned', 'from_code' => 'S2', 'to_code' => 'OK'],
    ['id' => 15, 'event' => 'decision_recorded', 'from_code' => 'S2', 'to_code' => 'OK'],
    ['id' => 16, 'event' => 'approval_invalidated', 'from_code' => 'OK', 'to_code' => 'S2'],
];
$entryS2 = $mat::checkpointEntryId($single, 16, 'S2');
ok('actor único: entrada a S2 = #12', $entryS2 === 12);
ok('actor único: visita de la decisión #13 (en S1) comenzó en #11', $mat::visitStartOf($single, 13) === 11);
ok('actor único: reabrir a S2 NO anula la decisión de S1 aunque su id (#13) sea posterior a la entrada', $mat::isVoidedByCheckpoint($single, 13, $entryS2) === false);
ok('actor único: reabrir a S2 anula la decisión de S2 (#15)', $mat::isVoidedByCheckpoint($single, 15, $entryS2) === true);
ok('conservador: sin entrada localizable (0) ⇒ anula', $mat::isVoidedByCheckpoint($single, 13, 0) === true);
ok('conservador: decisión fuera del ledger ⇒ anula', $mat::isVoidedByCheckpoint($single, 999, $entryS2) === true);

echo "== Materializer::resolveCheckpoint (lectura del ledger con resultado EXPLÍCITO) ==\n";
ok('ledger NO legible (null) + reopen_to_code ⇒ pending (nunca "anular todo")', $mat::resolveCheckpoint(null, 16, 'S2') === ['status' => 'pending', 'entry' => 0]);
ok('ledger incompleto (no contiene la propia invalidación) ⇒ pending', $mat::resolveCheckpoint(array_slice($single, 0, 3), 16, 'S2')['status'] === 'pending');
ok('ledger vacío ([]) con reopen_to_code ⇒ pending (vacío ≠ certeza)', $mat::resolveCheckpoint([], 16, 'S2')['status'] === 'pending');
ok('ledger completo ⇒ ok con la entrada al checkpoint', $mat::resolveCheckpoint($single, 16, 'S2') === ['status' => 'ok', 'entry' => 12]);
ok('ledger completo pero checkpoint nunca ingresado ⇒ error (visible, sin evidencias)', $mat::resolveCheckpoint($single, 16, 'NUNCA')['status'] === 'error');
ok('invalidación LEGACY sin reopen_to_code ⇒ legacy (anula todas, documentado)', $mat::resolveCheckpoint(null, 16, '') === ['status' => 'legacy', 'entry' => 0]);

echo "\n" . ($fail > 0
    ? "\033[31mUNIT FAIL: {$fail}/{$total}\033[0m"
    : "\033[32mUNIT OK: {$total}/{$total}\033[0m") . "\n";

exit($fail > 0 ? 1 : 0);
