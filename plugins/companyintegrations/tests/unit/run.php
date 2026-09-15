<?php

/**
 * Tests UNITARIOS + de CONTRATO de companyintegrations (sin bootstrap de GLPI).
 *
 * Cubren la lógica pura (clasificador de errores, backoff, circuit breaker, saneado de logs,
 * clasificación de reconciliación, chequeo de etiquetas) y el CONTRATO del SnipeItClient contra
 * un transporte de prueba (auth/429/timeout/5xx/circuit-breaker/found/not-found/token-fuera-de-logs).
 * Se ejecuta en el job estático de CI:  php plugins/companyintegrations/tests/unit/run.php
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

$svc = dirname(__DIR__, 2) . '/src/Service/';
$cli = dirname(__DIR__, 2) . '/src/Client/';
require $svc . 'ErrorClassifier.php';
require $svc . 'BackoffPolicy.php';
require $svc . 'CircuitBreaker.php';
require $svc . 'LogSanitizer.php';
require $svc . 'CorrelationId.php';
require $svc . 'ReconciliationClassifier.php';
require $svc . 'LabelConfigChecker.php';
require $cli . 'HttpResponse.php';
require $cli . 'HttpTransportException.php';
require $cli . 'HttpTransport.php';
require $cli . 'ArrayTransport.php';
require $cli . 'SnipeClientConfig.php';
require $cli . 'SnipeException.php';
require $cli . 'SnipeItClient.php';

use GlpiPlugin\Companyintegrations\Client\ArrayTransport;
use GlpiPlugin\Companyintegrations\Client\HttpResponse;
use GlpiPlugin\Companyintegrations\Client\HttpTransportException;
use GlpiPlugin\Companyintegrations\Client\SnipeClientConfig;
use GlpiPlugin\Companyintegrations\Client\SnipeException;
use GlpiPlugin\Companyintegrations\Client\SnipeItClient;
use GlpiPlugin\Companyintegrations\Service\BackoffPolicy;
use GlpiPlugin\Companyintegrations\Service\CircuitBreaker;
use GlpiPlugin\Companyintegrations\Service\ErrorClassifier;
use GlpiPlugin\Companyintegrations\Service\LabelConfigChecker;
use GlpiPlugin\Companyintegrations\Service\LogSanitizer;
use GlpiPlugin\Companyintegrations\Service\ReconciliationClassifier;

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

/** @param array<string,mixed> $body */
function resp(int $status, array $body = [], array $headers = []): HttpResponse
{
    return new HttpResponse($status, $headers, json_encode($body) ?: '');
}

function makeClient(array $queue, string $token = 'TESTTOKEN', ?array &$logs = null, int $maxRetries = 3, int $breakerThreshold = 5, int $cooldown = 60): SnipeItClient
{
    if (!is_array($logs)) {
        $logs = [];
    }
    $cfg = new SnipeClientConfig('https://snipe.test', $token, 5000, $maxRetries, 1, $breakerThreshold, $cooldown);
    // Captura por referencia del parámetro (que a su vez referencia la variable del llamador).
    $logger = function (string $lvl, string $msg, array $ctx) use (&$logs): void {
        $logs[] = $lvl . ' ' . $msg . ' ' . json_encode($ctx);
    };
    return new SnipeItClient(new ArrayTransport($queue), $cfg, $logger, false); // sleep=false
}

echo "== ErrorClassifier ==\n";
$ec = new ErrorClassifier();
ok('200 → ok', $ec->classify(200) === ErrorClassifier::OK);
ok('401 → auth', $ec->classify(401) === ErrorClassifier::AUTH);
ok('403 → auth', $ec->classify(403) === ErrorClassifier::AUTH);
ok('404 → notfound', $ec->classify(404) === ErrorClassifier::NOT_FOUND);
ok('409 → conflict', $ec->classify(409) === ErrorClassifier::CONFLICT);
ok('429 → ratelimit', $ec->classify(429) === ErrorClassifier::RATELIMIT);
ok('500 → server', $ec->classify(500) === ErrorClassifier::SERVER);
ok('418 → client', $ec->classify(418) === ErrorClassifier::CLIENT);
ok('server es reintentable', $ec->isRetryable(ErrorClassifier::SERVER));
ok('ratelimit es reintentable', $ec->isRetryable(ErrorClassifier::RATELIMIT));
ok('auth NO es reintentable', !$ec->isRetryable(ErrorClassifier::AUTH));

echo "== BackoffPolicy ==\n";
$bp = new BackoffPolicy(200, 16000);
ok('attempt 0 → 200ms', $bp->delayMs(0) === 200);
ok('attempt 1 → 400ms', $bp->delayMs(1) === 400);
ok('attempt 3 → 1600ms', $bp->delayMs(3) === 1600);
ok('cap a maxMs', $bp->delayMs(20) === 16000);
ok('retry-after respetado', $bp->delayMs(0, 3000) === 3000);
ok('retry-after acotado a max', $bp->delayMs(0, 999999) === 16000);

echo "== CircuitBreaker ==\n";
$cb = new CircuitBreaker(2, 30);
ok('inicial permite', $cb->allow(1000));
$cb->onFailure(1000);
ok('1 fallo aún permite', $cb->allow(1000));
$cb->onFailure(1000);
ok('2 fallos → abierto', $cb->isOpen(1000));
ok('sigue abierto durante cooldown', $cb->isOpen(1020));
ok('half-open tras cooldown', $cb->allow(1031));
$cb->onSuccess();
ok('éxito lo cierra', $cb->allow(1031) && $cb->consecutiveFailures() === 0);

echo "== LogSanitizer ==\n";
$ls = new LogSanitizer();
ok('redacta Bearer', !str_contains($ls->redact('Authorization: Bearer abc123secret'), 'abc123secret'));
ok('redacta token=', !str_contains($ls->redact('url?token=supersecret&x=1'), 'supersecret'));
$red = $ls->redactArray(['authorization' => 'Bearer zzz', 'nested' => ['token' => 'qqq', 'ok' => 'visible']]);
ok('redacta clave authorization', $red['authorization'] === '***REDACTED***');
ok('redacta token anidado', $red['nested']['token'] === '***REDACTED***');
ok('conserva valores no sensibles', $red['nested']['ok'] === 'visible');

echo "== ReconciliationClassifier ==\n";
$rc = new ReconciliationClassifier();
$asset = ['id' => 1, 'asset_tag' => 'NB-1', 'serial' => 'S1', 'company' => ['id' => 7]];
ok('compañía no mapeada → COMPANY_UNMAPPED',
    $rc->classify($asset, null, null, [])['classification'] === ReconciliationClassifier::COMPANY_UNMAPPED);
ok('sin candidatos → SNIPE_ONLY',
    $rc->classify($asset, null, 3, [])['classification'] === ReconciliationClassifier::SNIPE_ONLY);
ok('un candidato → MATCHED con link',
    (function () use ($rc, $asset) {
        $r = $rc->classify($asset, null, 3, [['itemtype' => 'Computer', 'items_id' => 42, 'serial' => 'S1']]);
        return $r['classification'] === ReconciliationClassifier::MATCHED && $r['link'] === true && $r['glpi_items_id'] === 42;
    })());
ok('dos candidatos → AMBIGUOUS (sin link)',
    (function () use ($rc, $asset) {
        $r = $rc->classify($asset, null, 3, [
            ['itemtype' => 'Computer', 'items_id' => 1, 'serial' => 'S1'],
            ['itemtype' => 'Computer', 'items_id' => 2, 'serial' => 'S1'],
        ]);
        return $r['classification'] === ReconciliationClassifier::AMBIGUOUS && $r['link'] === false;
    })());
ok('puente con serial distinto → SERIAL_CONFLICT',
    $rc->classify($asset, ['glpi_itemtype' => 'Computer', 'glpi_items_id' => 9, 'serial' => 'OTHER'], 3, [])['classification'] === ReconciliationClassifier::SERIAL_CONFLICT);
ok('puente coherente → MATCHED (sin re-link)',
    (function () use ($rc, $asset) {
        $r = $rc->classify($asset, ['glpi_itemtype' => 'Computer', 'glpi_items_id' => 9, 'serial' => 'S1'], 3, []);
        return $r['classification'] === ReconciliationClassifier::MATCHED && $r['link'] === false;
    })());

echo "== LabelConfigChecker ==\n";
$lc = new LabelConfigChecker();
ok('plain_asset_tag + prefijo → ok',
    $lc->check(['label2_2d_target' => 'plain_asset_tag', 'label2_2d_prefix' => 'https://portal/asset/'])['ok'] === true);
ok('sin prefijo → no ok',
    $lc->check(['label2_2d_target' => 'plain_asset_tag', 'label2_2d_prefix' => ''])['ok'] === false);
ok('target incorrecto → no ok',
    $lc->check(['label2_2d_target' => 'asset_id', 'label2_2d_prefix' => 'x'])['ok'] === false);
ok('null → no ok', $lc->check(null)['ok'] === false);

echo "== SnipeItClient (contract) ==\n";
// auth ok
$c = makeClient([resp(200, ['total' => 1, 'rows' => [['id' => 1, 'asset_tag' => 'NB-1']]])]);
ok('ping/list 200 → filas', $c->listHardware()[0]['asset_tag'] === 'NB-1');

// 401 → AUTH, sin reintento
$t = new ArrayTransport([resp(401)]);
$c = new SnipeItClient($t, new SnipeClientConfig('https://s', 'x', 5000, 3, 1, 5, 60), null, false);
try {
    $c->listHardware();
    ok('401 lanza', false);
} catch (SnipeException $e) {
    ok('401 → AUTH', $e->kind === SnipeException::AUTH);
    ok('401 no reintenta (1 llamada)', count($t->calls) === 1);
}

// 403 → AUTH
try {
    (new SnipeItClient(new ArrayTransport([resp(403)]), new SnipeClientConfig('https://s', 'x', 5000, 3, 1, 5, 60), null, false))->listHardware();
    ok('403 lanza', false);
} catch (SnipeException $e) {
    ok('403 → AUTH', $e->kind === SnipeException::AUTH);
}

// 429 agotado → RATE_LIMIT (maxRetries=2 → 3 llamadas)
$t = new ArrayTransport([resp(429), resp(429), resp(429)]);
$c = new SnipeItClient($t, new SnipeClientConfig('https://s', 'x', 5000, 2, 1, 5, 60), null, false);
try {
    $c->listHardware();
    ok('429 agotado lanza', false);
} catch (SnipeException $e) {
    ok('429 agotado → RATE_LIMIT', $e->kind === SnipeException::RATE_LIMIT);
    ok('429 reintenta (3 llamadas)', count($t->calls) === 3);
}

// timeout con recuperación: 2 timeouts y luego 200 (maxRetries=3)
$t = new ArrayTransport([new HttpTransportException('timeout'), new HttpTransportException('timeout'), resp(200, ['rows' => [['id' => 9]]])]);
$c = new SnipeItClient($t, new SnipeClientConfig('https://s', 'x', 5000, 3, 1, 5, 60), null, false);
ok('timeout recupera tras reintento', ($c->listHardware()[0]['id'] ?? 0) === 9);

// timeout agotado → TRANSPORT
try {
    (new SnipeItClient(new ArrayTransport([new HttpTransportException('t'), new HttpTransportException('t')]),
        new SnipeClientConfig('https://s', 'x', 5000, 1, 1, 5, 60), null, false))->listHardware();
    ok('timeout agotado lanza', false);
} catch (SnipeException $e) {
    ok('timeout agotado → TRANSPORT', $e->kind === SnipeException::TRANSPORT);
}

// 5xx agotado → TRANSPORT
try {
    (new SnipeItClient(new ArrayTransport([resp(500), resp(500)]),
        new SnipeClientConfig('https://s', 'x', 5000, 1, 1, 5, 60), null, false))->listHardware();
    ok('5xx agotado lanza', false);
} catch (SnipeException $e) {
    ok('5xx agotado → TRANSPORT', $e->kind === SnipeException::TRANSPORT);
}

// circuit breaker: threshold=2, maxRetries=0 → 2 fallos abren, 3ra llamada CIRCUIT_OPEN sin enviar
$t = new ArrayTransport([resp(500), resp(500)]);
$c = new SnipeItClient($t, new SnipeClientConfig('https://s', 'x', 5000, 0, 1, 2, 60), null, false);
for ($i = 0; $i < 2; $i++) {
    try {
        $c->listHardware();
    } catch (SnipeException) {
    }
}
$circuitOpen = false;
try {
    $c->listHardware();
} catch (SnipeException $e) {
    $circuitOpen = $e->kind === SnipeException::CIRCUIT_OPEN;
}
ok('circuit breaker abre tras umbral', $circuitOpen);
ok('circuit abierto NO envía (2 llamadas totales)', count($t->calls) === 2);

// asset found / not found
$c = makeClient([resp(200, ['id' => 5, 'asset_tag' => 'NB-5'])]);
ok('getHardwareByTag encontrado', ($c->getHardwareByTag('NB-5')['id'] ?? 0) === 5);
$c = makeClient([resp(404)]);
ok('getHardwareByTag 404 → null', $c->getHardwareByTag('NOPE') === null);

// token nunca en logs
$logs = [];
$c = makeClient([resp(401)], 'SUPERSECRETTOKEN', $logs);
try {
    $c->listHardware();
} catch (SnipeException) {
}
$joined = implode("\n", $logs);
ok('el token NUNCA aparece en logs', $joined !== '' && !str_contains($joined, 'SUPERSECRETTOKEN'));
ok('sólo se hicieron GET (read-only)', (function () {
    $t = new ArrayTransport([resp(200, ['rows' => []])]);
    $c = new SnipeItClient($t, new SnipeClientConfig('https://s', 'x', 5000, 0, 1, 5, 60), null, false);
    $c->listHardware();
    return $t->allReadOnly();
})());

echo "\n" . ($fail > 0
    ? "\033[31mUNIT FAIL: {$fail}/{$total}\033[0m"
    : "\033[32mUNIT OK: {$total}/{$total}\033[0m") . "\n";

exit($fail > 0 ? 1 : 0);
