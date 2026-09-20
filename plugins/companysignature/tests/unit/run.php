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

echo "\n" . ($fail > 0
    ? "\033[31mUNIT FAIL: {$fail}/{$total}\033[0m"
    : "\033[32mUNIT OK: {$total}/{$total}\033[0m") . "\n";

exit($fail > 0 ? 1 : 0);
