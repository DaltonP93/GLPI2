<?php

/**
 * Tests UNITARIOS + de CONTRATO + crash/retry de SI4-1 (ADR-0020), sin GLPI. Incluido desde `run.php` (comparte
 * `ok()` y los contadores). Usa `FakeSnipeServer` (contrato real v8.7.2 con estado + fallas inyectables),
 * `InMemoryHandoffSource` (semántica de lease de `PurchasingIntegrationApi`) e `InMemorySagaStore` (fencing).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use GlpiPlugin\Companyintegrations\Client\ArrayTransport;
use GlpiPlugin\Companyintegrations\Client\CreateResult;
use GlpiPlugin\Companyintegrations\Client\FakeSnipeServer;
use GlpiPlugin\Companyintegrations\Client\HttpResponse;
use GlpiPlugin\Companyintegrations\Client\SnipeAssetWriter;
use GlpiPlugin\Companyintegrations\Client\SnipeClientConfig;
use GlpiPlugin\Companyintegrations\Client\SnipeEnvelope;
use GlpiPlugin\Companyintegrations\Client\SnipeException;
use GlpiPlugin\Companyintegrations\Si4\ArrayGlpiMappingResolver;
use GlpiPlugin\Companyintegrations\Si4\ArrayMappingResolver;
use GlpiPlugin\Companyintegrations\Si4\InMemoryBridgeStore;
use GlpiPlugin\Companyintegrations\Si4\InMemoryGlpiAssets;
use GlpiPlugin\Companyintegrations\Si4\InMemoryFinalizerCursor;
use GlpiPlugin\Companyintegrations\Si4\InMemoryQrGateway;
use GlpiPlugin\Companyintegrations\Si4\Si4QrStage;
use GlpiPlugin\Companyintegrations\Si4\Si4GlpiStage;
use GlpiPlugin\Companyintegrations\Si4\AssetTagDeriver;
use GlpiPlugin\Companyintegrations\Si4\HandoffSource;
use GlpiPlugin\Companyintegrations\Si4\InMemoryHandoffSource;
use GlpiPlugin\Companyintegrations\Si4\InMemorySagaStore;
use GlpiPlugin\Companyintegrations\Si4\MappingResolver;
use GlpiPlugin\Companyintegrations\Si4\MappingRules;
use GlpiPlugin\Companyintegrations\Si4\RemoteAssetMatcher;
use GlpiPlugin\Companyintegrations\Si4\SagaState;
use GlpiPlugin\Companyintegrations\Si4\Si4Config;
use GlpiPlugin\Companyintegrations\Si4\Si4Errors;
use GlpiPlugin\Companyintegrations\Si4\Si4Worker;
use GlpiPlugin\Companyintegrations\Si4\SimulatedCrash;

const SI4_TOKEN = 'SI4-SUPER-SECRET-TOKEN-0123456789';

/** Mundo de prueba: Snipe fake + outbox en memoria + saga en memoria + reloj compartido. */
final class Si4World
{
    /** @var array<int,Si4World> todos los mundos creados (para la comprobación global "nunca ack") */
    public static array $all = [];
    public int $now = 1_800_000_000;
    public FakeSnipeServer $snipe;
    public InMemoryHandoffSource $source;
    public InMemorySagaStore $sagas;
    public ArrayMappingResolver $mapping;
    // SI4-2 (ADR-0021): GLPI en memoria, puente y mapeo categoría ⇒ itemtype/modelo GLPI.
    public InMemoryGlpiAssets $glpi;
    public InMemoryBridgeStore $bridges;
    public ArrayGlpiMappingResolver $glpiMap;
    // SI4-3 (ADR-0022): companyqr en memoria (API pública emulada).
    public InMemoryQrGateway $qr;
    /** Cursor "durable" del finalizador: compartido por todos los workers del mundo (= tabla propia entre procesos). */
    public InMemoryFinalizerCursor $finCursor;
    /** @var array<string,string> */
    public array $cfg;
    /** @var array<int,string> */
    public array $logs = [];

    public function __construct()
    {
        $clock = fn (): int => $this->now;
        $this->snipe = new FakeSnipeServer(SI4_TOKEN);
        $this->snipe->statusLabels = [5 => true];
        $this->snipe->models = [31 => true, 32 => true];
        $this->snipe->companies = [7 => true, 8 => true];
        $this->source = new InMemoryHandoffSource($clock, [1, 2]);
        $this->sagas = new InMemorySagaStore($clock);
        $this->mapping = new ArrayMappingResolver([1 => [7], 2 => [8]], ['NB' => 31, 'MON' => 32], 5);
        $this->glpi = new InMemoryGlpiAssets();
        $this->bridges = new InMemoryBridgeStore();
        $this->glpiMap = new ArrayGlpiMappingResolver(['NB' => ['glpi_itemtype' => 'Computer', 'glpi_model_id' => 41],
            'MON' => ['glpi_itemtype' => 'Monitor', 'glpi_model_id' => 0]]);
        $this->qr = new InMemoryQrGateway($this->glpi);
        $this->finCursor = new InMemoryFinalizerCursor();
        $this->cfg = [
            'si4_enabled' => '1', 'si4_asset_tag_prefix' => 'GP2-', 'si4_snipe_status_id' => '5', 'si4_lease_seconds' => '900',
            'si4_max_units_per_run' => '50', 'si4_retry_base_seconds' => '60', 'si4_retry_max_seconds' => '3600',
            'si4_config_retry_seconds' => '3600', 'si4_auth_retry_seconds' => '900', 'si4_uncertain_cooldown_seconds' => '300',
            'si4_worker_id' => 'w-test',
        ];
        self::$all[] = $this;
    }

    /** POST efectivamente ENVIADOS (incluye los que fallaron antes de ejecutarse). */
    public function posts(): int
    {
        return count(array_filter($this->snipe->requests, static fn (array $r): bool => $r['method'] === 'POST'));
    }

    /** @param array<string,mixed> $over */
    public function unit(array $over = []): string
    {
        $uuid = $over['receipt_unit_uuid'] ?? sprintf('%08x-%04x-4%03x-a%03x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));
        $this->source->add($over + [
            'schema_version' => 1, 'receipt_unit_uuid' => $uuid, 'request_id' => 11, 'request_number' => 'SC-2026-0011',
            'item_id' => 21, 'entity_id' => 1, 'serial' => null, 'description' => 'Notebook 14"', 'category' => 'NB',
            'supplier_id' => 3, 'currency' => 'PYG', 'currency_scale' => 0, 'unit_cost' => '2339',
            'received_at' => '2026-09-29 10:00:00', 'correlation_id' => 'corr-si4-test',
        ]);
        return $uuid;
    }

    /** @param callable(string,string):void|null $probe @param array<string,string> $cfgOver */
    public function worker(?callable $probe = null, array $cfgOver = []): Si4Worker
    {
        $logs = &$this->logs;
        $logger = function (string $l, string $m, array $c) use (&$logs): void {
            $logs[] = $l . ' ' . $m . ' ' . json_encode($c);
        };
        $conf = new SnipeClientConfig('https://snipe.test', SI4_TOKEN, 5000, 2, 1, 50, 60);
        $writer = new SnipeAssetWriter($this->snipe, $conf, $logger, false);
        return new Si4Worker($this->source, $this->sagas, $this->mapping, $writer, Si4Config::fromArray($cfgOver + $this->cfg), 5000, 2, $logger, $probe, fn (): int => $this->now);
    }

    /**
     * Worker SI4-2: el mismo de SI4-1 + `Si4GlpiStage` (activo GLPI, Infocom, asset_bridge).
     *
     * @param callable(string,string):void|null $probe @param array<string,string> $cfgOver
     */
    public function worker2(?callable $probe = null, array $cfgOver = []): Si4Worker
    {
        $logs = &$this->logs;
        $logger = function (string $l, string $m, array $c) use (&$logs): void {
            $logs[] = $l . ' ' . $m . ' ' . json_encode($c);
        };
        $conf = new SnipeClientConfig('https://snipe.test', SI4_TOKEN, 5000, 2, 1, 50, 60);
        $writer = new SnipeAssetWriter($this->snipe, $conf, $logger, false);
        $cfg = Si4Config::fromArray($cfgOver + $this->cfg);
        $stage = new Si4GlpiStage($this->sagas, $this->glpi, $this->glpiMap, $this->bridges, $cfg->infocomCurrency, $probe);
        return new Si4Worker($this->source, $this->sagas, $this->mapping, $writer, $cfg, 5000, 2, $logger, $probe, fn (): int => $this->now, $stage);
    }

    /**
     * Worker SI4-3: el de SI4-2 + `Si4QrStage` (código companyqr, etiqueta, ack y finalizador).
     *
     * @param callable(string,string):void|null $probe @param array<string,string> $cfgOver
     */
    public function worker3(?callable $probe = null, array $cfgOver = []): Si4Worker
    {
        $logs = &$this->logs;
        $logger = function (string $l, string $m, array $c) use (&$logs): void {
            $logs[] = $l . ' ' . $m . ' ' . json_encode($c);
        };
        $conf = new SnipeClientConfig('https://snipe.test', SI4_TOKEN, 5000, 2, 1, 50, 60);
        $writer = new SnipeAssetWriter($this->snipe, $conf, $logger, false);
        $cfg = Si4Config::fromArray($cfgOver + $this->cfg);
        $stage = new Si4GlpiStage($this->sagas, $this->glpi, $this->glpiMap, $this->bridges, $cfg->infocomCurrency, $probe);
        $qr = new Si4QrStage($this->sagas, $this->bridges, $this->glpi, $this->qr, $probe);
        return new Si4Worker($this->source, $this->sagas, $this->mapping, $writer, $cfg, 5000, 2, $logger, $probe, fn (): int => $this->now, $stage, $qr, $this->finCursor);
    }

    public function tag(string $uuid): string
    {
        return AssetTagDeriver::tagFor('GP2-', $uuid);
    }

    public function assetsFor(string $uuid): int
    {
        return count($this->snipe->liveByTag($this->tag($uuid)));
    }
}

function si4Throws(callable $fn): bool
{
    try {
        $fn();
    } catch (\Throwable) {
        return true;
    }
    return false;
}

// =====================================================================================================================
echo "== SI4 · SnipeEnvelope (contrato real v8.7.2: HTTP 200 para éxito y error) ==\n";
ok('status:error detectado', SnipeEnvelope::isError(['status' => 'error', 'messages' => 'x', 'payload' => null]));
ok('status:success detectado', SnipeEnvelope::isSuccess(['status' => 'success', 'payload' => ['id' => 1]]));
ok('campos de validación ordenados', SnipeEnvelope::errorFields(['status' => 'error', 'messages' => ['serial' => ['u'], 'asset_tag' => ['u']]]) === ['asset_tag', 'serial']);
ok('"no existe" (texto) ⇒ sin campos', SnipeEnvelope::errorFields(['status' => 'error', 'messages' => 'Asset does not exist.']) === []);
ok('rows de listado', count(SnipeEnvelope::rows(['total' => 2, 'rows' => [['id' => 1], ['id' => 2]]]) ?? []) === 2);
ok('texto escapado (e()) se decodifica', SnipeEnvelope::text('SN&amp;1') === 'SN&1');

echo "== SI4 · SI-1 compatible: getHardwareByTag con 200 + status:error ⇒ null ==\n";
$c = makeClient([resp(200, ['status' => 'error', 'messages' => 'Asset does not exist.', 'payload' => null])]);
ok('200 + status:error ⇒ null (antes devolvía el sobre como activo)', $c->getHardwareByTag('NOPE') === null);
$c = makeClient([resp(200, ['id' => 9, 'asset_tag' => 'T-9'])]);
ok('activo real sigue resolviéndose', ($c->getHardwareByTag('T-9')['id'] ?? 0) === 9);

echo "== SI4 · AssetTagDeriver (identidad remota determinista) ==\n";
$u = '3f9a1c2b-7d4e-4f60-8a1b-0c2d3e4f5a6b';
ok('tag = prefijo + 32 hex en mayúsculas', AssetTagDeriver::tagFor('GP2-', $u) === 'GP2-3F9A1C2B7D4E4F608A1B0C2D3E4F5A6B');
ok('determinista (misma unidad ⇒ mismo tag)', AssetTagDeriver::tagFor('GP2-', $u) === AssetTagDeriver::tagFor('GP2-', $u));
ok('unidades distintas ⇒ tags distintos', AssetTagDeriver::tagFor('GP2-', $u) !== AssetTagDeriver::tagFor('GP2-', '3f9a1c2b-7d4e-4f60-8a1b-0c2d3e4f5a6c'));
ok('prefijo inválido ⇒ fail-closed', si4Throws(fn () => AssetTagDeriver::tagFor('gp2 ', $u)));
ok('uuid inválido ⇒ fail-closed', si4Throws(fn () => AssetTagDeriver::tagFor('GP2-', 'no-uuid')));

echo "== SI4 · RemoteAssetMatcher (tag + compañía + modelo + serial + marca de procedencia; caso A/B) ==\n";
$t = 'GP2-ABC';
$mu = '3f9a1c2b-7d4e-4f60-8a1b-0c2d3e4f5a6b';
$otherU = '3f9a1c2b-7d4e-4f60-8a1b-0c2d3e4f5a6c';
$ownNotes = FakeSnipeServer::markdownInline('GLPI2 SI-4 · request=SC-1 · correlation=c · ' . RemoteAssetMatcher::marker($mu));
$live = fn (int $id, int $co = 7, string $ser = '', ?array $del = null, int $model = 31, ?string $notes = null): array => ['id' => $id, 'asset_tag' => $t, 'serial' => $ser,
    'company' => ['id' => $co], 'model' => ['id' => $model], 'notes' => $notes ?? $ownNotes, 'deleted_at' => $del];
$cls = fn (array $rows, ?string $serial = null, bool $own = true): string => RemoteAssetMatcher::classify($rows, $t, 7, 31, $serial, $mu, $own)['kind'];
ok('sin filas ⇒ NONE', $cls([]) === RemoteAssetMatcher::NONE);
ok('todo coincide + marca propia + POST propio ⇒ ONE', RemoteAssetMatcher::classify([$live(5)], $t, 7, 31, null, $mu, true) === ['kind' => RemoteAssetMatcher::ONE, 'asset_id' => 5, 'detail' => '']);
ok('sólo borrado ⇒ DELETED (no recrear)', $cls([$live(5, 7, '', ['datetime' => 'x'])]) === RemoteAssetMatcher::DELETED);
ok('dos vivos ⇒ DUPLICATE', $cls([$live(5), $live(6)]) === RemoteAssetMatcher::DUPLICATE);
ok('vivo + borrado ⇒ DUPLICATE', $cls([$live(5), $live(6, 7, '', ['datetime' => 'x'])]) === RemoteAssetMatcher::DUPLICATE);
ok('otra compañía ⇒ COMPANY_MISMATCH (multi-entidad)', $cls([$live(5, 8)]) === RemoteAssetMatcher::COMPANY_MISMATCH);
ok('🔒 mismo tag + misma compañía + sin serial + OTRO modelo ⇒ MODEL_MISMATCH', $cls([$live(5, 7, '', null, 99)]) === RemoteAssetMatcher::MODEL_MISMATCH);
ok('serial distinto ⇒ SERIAL_MISMATCH', $cls([$live(5, 7, 'SN-X')], 'SN-Y') === RemoteAssetMatcher::SERIAL_MISMATCH);
ok('serial escapado por el transformer coincide', $cls([$live(5, 7, 'SN&amp;1')], 'SN&1') === RemoteAssetMatcher::ONE);
ok('🔒 tag/compañía/modelo correctos pero SIN marca ⇒ OWNERSHIP_MISMATCH', $cls([$live(5, 7, '', null, 31, 'alta manual')]) === RemoteAssetMatcher::OWNERSHIP_MISMATCH
    && $cls([['id' => 5, 'asset_tag' => $t, 'serial' => '', 'company' => ['id' => 7], 'model' => ['id' => 31], 'notes' => null, 'deleted_at' => null]]) === RemoteAssetMatcher::OWNERSHIP_MISMATCH);
ok('🔒 marca de OTRO receipt_unit_uuid ⇒ OWNERSHIP_MISMATCH', $cls([$live(5, 7, '', null, 31, 'x · ' . RemoteAssetMatcher::marker($otherU))]) === RemoteAssetMatcher::OWNERSHIP_MISMATCH);
ok('🔒 dos marcas (propia + otra) ⇒ OWNERSHIP_MISMATCH', $cls([$live(5, 7, '', null, 31, RemoteAssetMatcher::marker($mu) . ' ' . RemoteAssetMatcher::marker($otherU))]) === RemoteAssetMatcher::OWNERSHIP_MISMATCH);
ok('🔒 marca con sufijo (uuid más largo) no cuenta', $cls([$live(5, 7, '', null, 31, RemoteAssetMatcher::marker($mu) . 'ff')]) === RemoteAssetMatcher::OWNERSHIP_MISMATCH);
ok('🔒 caso A: tag preexistente (sin POST de esta saga) NUNCA se adopta, aunque coincida todo', $cls([$live(5)], null, false) === RemoteAssetMatcher::PREEXISTING);
ok('marca propia sobrevive al markdown de Snipe (énfasis antes de la marca)', RemoteAssetMatcher::hasOwnMarker(
    FakeSnipeServer::markdownInline('nota _importante_ del admin · a_b_ c · ' . RemoteAssetMatcher::marker($mu)), $mu));

echo "== SI4 · MappingRules (mapeos validados, fail-closed) ==\n";
ok('sin compañía ⇒ bloqueado', !MappingRules::decide([], 31, 5)['ok']);
ok('compañía ambigua ⇒ bloqueado', !MappingRules::decide([7, 8], 31, 5)['ok']);
ok('sin modelo ⇒ bloqueado', !MappingRules::decide([7], 0, 5)['ok']);
ok('sin status ⇒ bloqueado', !MappingRules::decide([7], 31, 0)['ok']);
ok('completo ⇒ ids resueltos', MappingRules::decide([7, 7], 31, 5) === ['ok' => true, 'company_id' => 7, 'model_id' => 31, 'status_id' => 5, 'reason' => '']);

echo "== SI4 · Si4Config ==\n";
$w0 = new Si4World();
$okCfg = Si4Config::fromArray($w0->cfg);
ok('config de prueba válida', $okCfg->errors(5000, 2) === []);
ok('status sin configurar ⇒ error', Si4Config::fromArray(['si4_snipe_status_id' => '0'] + $w0->cfg)->errors(5000, 2) !== []);
ok('lease insuficiente para timeout/reintentos ⇒ error', Si4Config::fromArray(['si4_lease_seconds' => '60'] + $w0->cfg)->errors(5000, 2) !== []);
ok('cooldown < 2×timeout ⇒ error', Si4Config::fromArray(['si4_uncertain_cooldown_seconds' => '10'] + $w0->cfg)->errors(30000, 2) !== []);
ok('prefijo inválido ⇒ error', Si4Config::fromArray(['si4_asset_tag_prefix' => 'x y'] + $w0->cfg)->errors(5000, 2) !== []);
ok('backoff exponencial acotado', $okCfg->backoffSeconds(1) === 60 && $okCfg->backoffSeconds(3) === 240 && $okCfg->backoffSeconds(40) === 3600);
ok('Retry-After gana si es mayor', $okCfg->backoffSeconds(1, 120) === 120);
ok('presupuesto de escritura cubre el POST', Si4Config::writeBudgetSeconds(5000) >= 5 + 30);

echo "== SI4 · Si4Errors (saneado) ==\n";
$san = Si4Errors::sanitize("cURL: fail\n Authorization: Bearer " . SI4_TOKEN . ' https://svc:pw123@snipe.test/x token=abc');
ok('sin token Bearer', !str_contains($san, SI4_TOKEN));
ok('sin credenciales en URL', !str_contains($san, 'pw123'));
ok('sin token=…', !str_contains($san, 'token=abc'));
ok('sin controles y acotado', !str_contains($san, "\n") && mb_strlen(Si4Errors::sanitize(str_repeat('x', 900))) <= Si4Errors::MAX);

// =====================================================================================================================
echo "== SI4 · Contrato WRITE (SnipeAssetWriter) ==\n";
$logs = [];
$logger = function (string $l, string $m, array $c) use (&$logs): void {
    $logs[] = $l . ' ' . $m . ' ' . json_encode($c);
};
$mk = fn (array $q, int $retries = 2): array => [$tr = new ArrayTransport($q), new SnipeAssetWriter($tr, new SnipeClientConfig('https://snipe.test', SI4_TOKEN, 5000, $retries, 1, 50, 60), $logger, false)];

[$tr, $w] = $mk([resp(200, ['status' => 'success', 'payload' => ['id' => 77, 'asset_tag' => 'GP2-A']])]);
$r = $w->createAsset(['asset_tag' => 'GP2-A', 'model_id' => 31, 'status_id' => 5], 'c-1');
ok('POST 200 success ⇒ CREATED con id', $r->kind === CreateResult::CREATED && $r->assetId === 77);
[$tr, $w] = $mk([resp(200, ['status' => 'error', 'messages' => ['asset_tag' => ['The asset tag must be unique.']], 'payload' => null])]);
$r = $w->createAsset(['asset_tag' => 'GP2-A'], 'c-1');
ok('POST 200 status:error ⇒ VALIDATION(asset_tag) (no es éxito aunque sea 200)', $r->kind === CreateResult::VALIDATION && $r->hasField('asset_tag'));
[$tr, $w] = $mk([resp(200, ['status' => 'success', 'payload' => ['id' => 77, 'asset_tag' => 'OTRO']])]);
ok('2xx con tag distinto ⇒ UNCERTAIN (no se asume éxito)', $w->createAsset(['asset_tag' => 'GP2-A'], 'c')->kind === CreateResult::UNCERTAIN);
[$tr, $w] = $mk([resp(409)]);
ok('409 ⇒ CONFLICT explícito (sin reintento)', $w->createAsset(['asset_tag' => 'GP2-A'], 'c')->kind === CreateResult::CONFLICT && count($tr->calls) === 1);
[$tr, $w] = $mk([resp(401)]);
ok('401 ⇒ AUTH (fail-closed, sin reintento)', $w->createAsset(['asset_tag' => 'GP2-A'], 'c')->kind === CreateResult::AUTH && count($tr->calls) === 1);
[$tr, $w] = $mk([resp(403)]);
ok('403 ⇒ AUTH (fail-closed, sin reintento)', $w->createAsset(['asset_tag' => 'GP2-A'], 'c')->kind === CreateResult::AUTH && count($tr->calls) === 1);
[$tr, $w] = $mk([resp(429, [], ['Retry-After' => '42'])]);
$r = $w->createAsset(['asset_tag' => 'GP2-A'], 'c');
ok('429 ⇒ RATE_LIMIT con Retry-After (sin reintento ciego)', $r->kind === CreateResult::RATE_LIMIT && $r->retryAfterSec === 42 && count($tr->calls) === 1);
[$tr, $w] = $mk([resp(503)]);
ok('5xx en POST ⇒ UNCERTAIN (UN solo POST)', $w->createAsset(['asset_tag' => 'GP2-A'], 'c')->kind === CreateResult::UNCERTAIN && count($tr->calls) === 1);
[$tr, $w] = $mk([new \GlpiPlugin\Companyintegrations\Client\HttpTransportException('Operation timed out')]);
ok('timeout en POST ⇒ UNCERTAIN (UN solo POST)', $w->createAsset(['asset_tag' => 'GP2-A'], 'c')->kind === CreateResult::UNCERTAIN && count($tr->calls) === 1);

[$tr, $w] = $mk([resp(200, ['status' => 'error', 'messages' => 'Asset does not exist.', 'payload' => null])]);
ok('lookup: "no existe" (200 status:error) ⇒ []', $w->lookupByTag('GP2-A', 'c') === []);
ok('lookup pide ?deleted=true (ve soft-deleted)', str_contains($tr->calls[0]['url'], '/api/v1/hardware/bytag/GP2-A?deleted=true'));
[$tr, $w] = $mk([resp(429, [], ['Retry-After' => '1']), resp(200, ['total' => 1, 'rows' => [['id' => 5, 'asset_tag' => 'GP2-A']]])]);
ok('lookup: 429 ⇒ reintento con backoff y luego OK', count($w->lookupByTag('GP2-A', 'c')) === 1 && count($tr->calls) === 2);
[$tr, $w] = $mk([resp(500), resp(502), resp(503)]);
ok('lookup: 5xx agotado ⇒ SnipeException(transport) tras 3 intentos', si4Throws(fn () => $w->lookupByTag('GP2-A', 'c')) && count($tr->calls) === 3);
[$tr, $w] = $mk([new \GlpiPlugin\Companyintegrations\Client\HttpTransportException('timeout'), resp(200, ['total' => 0, 'rows' => []])]);
ok('lookup: timeout ⇒ reintento y luego OK', $w->lookupByTag('GP2-A', 'c') === [] && count($tr->calls) === 2);
[$tr, $w] = $mk([resp(401)]);
$kind = '';
try {
    $w->lookupByTag('GP2-A', 'c');
} catch (SnipeException $e) {
    $kind = $e->kind;
}
ok('lookup: 401 ⇒ AUTH sin reintento', $kind === SnipeException::AUTH && count($tr->calls) === 1);
[$tr, $w] = $mk([resp(200, ['status' => 'error', 'messages' => 'Statuslabel not found', 'payload' => null])]);
ok('statusLabel inexistente ⇒ null', $w->statusLabel(99, 'c') === null);

$fs = new FakeSnipeServer(SI4_TOKEN);
$fs->statusLabels = [5 => true];
$fs->models = [31 => true];
$fw = new SnipeAssetWriter($fs, new SnipeClientConfig('https://snipe.test', SI4_TOKEN, 5000, 2, 1, 50, 60), $logger, false);
$fw->createAsset(['asset_tag' => 'GP2-H', 'model_id' => 31, 'status_id' => 5], 'corr-h');
$h = $fs->requests[0]['headers'];
ok('headers: Bearer + JSON + User-Agent propio + correlation', ($h['Authorization'] ?? '') === 'Bearer ' . SI4_TOKEN
    && ($h['Content-Type'] ?? '') === 'application/json' && ($h['User-Agent'] ?? '') === SnipeAssetWriter::USER_AGENT && ($h['X-Correlation-ID'] ?? '') === 'corr-h');
ok('TLS: http:// rechazado para el writer (config)', si4Throws(fn () => new SnipeClientConfig('http://snipe.test', SI4_TOKEN)));
$curl = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Client/CurlTransport.php');
ok('TLS: CurlTransport verifica peer y host, sin desactivarlo', str_contains($curl, 'CURLOPT_SSL_VERIFYPEER => true') && str_contains($curl, 'CURLOPT_SSL_VERIFYHOST => 2')
    && preg_match('/SSL_VERIFY(PEER|HOST)\s*=>\s*(false|0)\b/', $curl) !== 1);

// =====================================================================================================================
echo "== SI4 · Worker: create-or-reconcile, sin ack (SI4-1 incompleto) ==\n";
$W = new Si4World();
$u1 = $W->unit();
$m = $W->worker()->run();
$saga = $W->sagas->get($u1);
ok('crea el activo remoto (1 POST)', $m['created'] === 1 && $W->posts() === 1 && $W->assetsFor($u1) === 1);
ok('saga SNIPE_CREATED con snipe_asset_id persistido', $saga['state'] === SagaState::SNIPE_CREATED && (int) $saga['snipe_asset_id'] > 0 && $saga['snipe_outcome'] === 'created');
ok('tag remoto = tag determinista', $saga['snipe_asset_tag'] === $W->tag($u1));
ok('NO acknowledgeProcessed (el outbox sigue LEASED)', $W->source->acks === [] && $W->source->row($u1)['status'] === 'LEASED');
ok('sin markRetry/markError en el camino feliz', $W->source->retries === [] && $W->source->errors === []);
$asset = $W->snipe->assets[(int) $saga['snipe_asset_id']];
ok('POST con compañía/modelo/estado del mapeo (sin IDs literales)', $asset['company_id'] === 7 && $asset['model_id'] === 31 && $asset['status_id'] === 5);

echo "== SI4 · Mismo receipt_unit_uuid procesado N veces ⇒ un solo activo ==\n";
$reqBefore = count($W->snipe->requests);
for ($i = 0; $i < 4; $i++) {
    $W->source->expire($u1);
    $W->now += 10;
    $mi = $W->worker()->run();
}
ok('re-claims ⇒ estacionada (parked), sin POST', $mi['parked'] === 1 && $W->posts() === 1 && $W->assetsFor($u1) === 1);
ok('re-claims de una saga estacionada no llaman a Snipe (sólo el preflight)', count($W->snipe->requests) - $reqBefore === 4);
ok('sigue sin ack', $W->source->acks === []);
ok('una sola saga para la unidad', count(array_filter($W->sagas->rows, fn ($r) => $r['receipt_unit_uuid'] === $u1)) === 1);

echo "== SI4 · Crash DESPUÉS del write remoto y ANTES de persistir ⇒ el retry NO duplica ==\n";
$W = new Si4World();
$u = $W->unit();
$crashAfterCreate = function (string $point) {
    if ($point === 'after_remote_create') {
        throw new SimulatedCrash('muere el proceso tras el POST');
    }
};
ok('el proceso "muere" tras el POST', si4Throws(fn () => $W->worker($crashAfterCreate)->run()));
$s = $W->sagas->get($u);
ok('Snipe tiene el activo pero la saga NO tiene snipe_asset_id', $W->assetsFor($u) === 1 && $s['snipe_asset_id'] === null && $s['state'] === SagaState::SNIPE_CREATING);
ok('sin settle (el lease queda hasta vencer)', $W->source->row($u)['status'] === 'LEASED' && $W->source->retries === []);
$W->source->expire($u);
$W->now += 5;
$m = $W->worker()->run();
$s = $W->sagas->get($u);
ok('retry ⇒ lookup-first encuentra el activo y lo VINCULA', $m['reconciled'] === 1 && $s['state'] === SagaState::SNIPE_CREATED && $s['snipe_outcome'] === 'reconciled');
ok('🔒 exactamente UN activo remoto y UN POST en total', $W->assetsFor($u) === 1 && $W->posts() === 1);
ok('snipe_asset_id = el creado por el intento caído', (int) $s['snipe_asset_id'] === (int) $W->snipe->liveByTag($W->tag($u))[0]['id']);

echo "== SI4 · Crash antes del POST / después de persistir ==\n";
$W = new Si4World();
$u = $W->unit();
ok('muere antes del POST (intención ya persistida)', si4Throws(fn () => $W->worker(fn (string $p) => $p === 'before_remote_create' ? throw new SimulatedCrash('x') : null)->run()));
$W->source->expire($u);
$W->now += 5;
$W->worker()->run();
ok('retry ⇒ crea una vez (nada se había enviado)', $W->assetsFor($u) === 1 && $W->posts() === 1 && $W->sagas->get($u)['state'] === SagaState::SNIPE_CREATED);
$W = new Si4World();
$u = $W->unit();
ok('muere tras persistir el vínculo', si4Throws(fn () => $W->worker(fn (string $p) => $p === 'after_link' ? throw new SimulatedCrash('x') : null)->run()));
$W->source->expire($u);
$W->now += 5;
$m = $W->worker()->run();
ok('retry ⇒ estacionada, sin nuevo POST', $m['parked'] === 1 && $W->posts() === 1 && $W->assetsFor($u) === 1);

echo "== SI4 · POST incierto (timeout / 5xx DESPUÉS de ejecutar) ⇒ enfriamiento + lookup ⇒ sin duplicar ==\n";
foreach ([FakeSnipeServer::TIMEOUT_AFTER => 'timeout', FakeSnipeServer::S500_AFTER => '5xx'] as $fault => $label) {
    $W = new Si4World();
    $u = $W->unit();
    $W->snipe->failNext('POST', '/api/v1/hardware', $fault);
    $m = $W->worker()->run();
    $row = $W->source->row($u);
    ok("{$label} tras ejecutar ⇒ UNCERTAIN, saga SNIPE_CREATING, outbox RETRY", $m['uncertain'] === 1 && $W->sagas->get($u)['state'] === SagaState::SNIPE_CREATING && $row['status'] === 'RETRY');
    ok("{$label}: next_retry_at ≥ enfriamiento", $row['next_retry_at'] >= $W->now + 300);
    $W->now = $row['next_retry_at'] + 1;
    $m = $W->worker()->run();
    ok("{$label}: retry reconcilia (1 activo, 1 POST)", $m['reconciled'] === 1 && $W->assetsFor($u) === 1 && $W->posts() === 1);
}
$W = new Si4World();
$u = $W->unit();
$W->snipe->failNext('POST', '/api/v1/hardware', FakeSnipeServer::TIMEOUT_BEFORE);
$W->worker()->run();
$W->now = $W->source->row($u)['next_retry_at'] + 1;
$m = $W->worker()->run();
ok('timeout ANTES de ejecutar ⇒ retry crea (1 activo, 2 POST enviados, 1 ejecutado)', $m['created'] === 1 && $W->assetsFor($u) === 1 && $W->posts() === 2 && $W->snipe->postCount === 1);

echo "== SI4 · 409 explícito / carrera de creación ==\n";
$W = new Si4World();
$u = $W->unit();
$W->snipe->failNext('POST', '/api/v1/hardware', FakeSnipeServer::RACE_CREATE);
$m = $W->worker()->run();
ok('otro proceso crea el mismo tag ⇒ validación asset_tag ⇒ reconcilia (1 activo)', $m['reconciled'] === 1 && $W->assetsFor($u) === 1 && $W->sagas->get($u)['state'] === SagaState::SNIPE_CREATED);
$W = new Si4World();
$u = $W->unit();
$W->snipe->failNext('POST', '/api/v1/hardware', FakeSnipeServer::S409);
$m = $W->worker()->run();
ok('409 sin activo vinculable ⇒ MANUAL_REVIEW + markError (nunca reintento ciego)', $m['manual_review'] === 1 && $W->source->row($u)['status'] === 'ERROR' && $W->assetsFor($u) === 0);

echo "== SI4 · 429 / 5xx / timeout ⇒ retry con backoff ==\n";
$W = new Si4World();
$u = $W->unit();
$W->snipe->failNext('POST', '/api/v1/hardware', FakeSnipeServer::S429, 120);
$m = $W->worker()->run();
ok('429 en POST ⇒ outbox RETRY con next ≥ Retry-After', $m['retry'] === 1 && $W->source->row($u)['status'] === 'RETRY' && $W->source->row($u)['next_retry_at'] >= $W->now + 120);
$W->now = $W->source->row($u)['next_retry_at'] + 1;
$m = $W->worker()->run();
ok('… y el retry crea (1 activo)', $m['created'] === 1 && $W->assetsFor($u) === 1);
$W = new Si4World();
$u = $W->unit();
foreach ([1, 2, 3] as $_) {
    $W->snipe->failNext('GET', '/api/v1/hardware/bytag', FakeSnipeServer::S500_BEFORE);
}
$m = $W->worker()->run();
ok('5xx en lookup agotado ⇒ RETRY y NINGÚN POST', $m['retry'] === 1 && $W->posts() === 0 && $W->source->row($u)['status'] === 'RETRY');
$W = new Si4World();
$u = $W->unit();
foreach ([1, 2, 3] as $_) {
    $W->snipe->failNext('GET', '/api/v1/hardware/bytag', FakeSnipeServer::TIMEOUT_BEFORE);
}
$m = $W->worker()->run();
ok('timeout en lookup agotado ⇒ RETRY y NINGÚN POST', $m['retry'] === 1 && $W->posts() === 0);

echo "== SI4 · 401/403 fail-closed ==\n";
$W = new Si4World();
$W->unit();
$W->snipe->failNext('GET', '/api/v1/statuslabels', FakeSnipeServer::S401);
$m = $W->worker()->run();
ok('401 en el preflight ⇒ corrida abortada SIN reclamar nada', $m['aborted'] === 'auth' && $m['claimed'] === 0);
$W = new Si4World();
$a = $W->unit();
$b = $W->unit();
$W->snipe->failNext('POST', '/api/v1/hardware', FakeSnipeServer::S403);
$m = $W->worker()->run();
ok('403 en POST ⇒ markRetry + corrida abortada (la 2.ª unidad no se toca)', $m['aborted'] === 'auth' && $m['claimed'] === 1 && $W->source->row($b)['status'] === 'PENDING');
ok('403 ⇒ un POST enviado y nada creado', $W->posts() === 1 && $W->snipe->assets === []);

echo "== SI4 · Mapeo ausente / rechazado ⇒ BLOCKED_CONFIG (RETRY tardío) ==\n";
$W = new Si4World();
$u = $W->unit(['category' => 'SIN-MAPEO']);
$m = $W->worker()->run();
ok('categoría sin modelo ⇒ BLOCKED_CONFIG + RETRY (config)', $m['blocked_config'] === 1 && $W->sagas->get($u)['state'] === SagaState::BLOCKED_CONFIG
    && $W->source->row($u)['status'] === 'RETRY' && $W->source->row($u)['next_retry_at'] >= $W->now + 3600);
ok('… sin llamadas de escritura a Snipe', $W->posts() === 0);
$W->mapping->modelsByCategory['SIN-MAPEO'] = 32;
$W->now = $W->source->row($u)['next_retry_at'] + 1;
$m = $W->worker()->run();
ok('al configurar el mapeo la unidad avanza', $m['created'] === 1 && $W->assetsFor($u) === 1);
$W = new Si4World();
$W->mapping->companiesByEntity[1] = [7, 8];
$u = $W->unit();
ok('compañía ambigua para la entidad ⇒ BLOCKED_CONFIG', $W->worker()->run()['blocked_config'] === 1);
$W = new Si4World();
$W->snipe->models = [];
$u = $W->unit();
$m = $W->worker()->run();
ok('Snipe rechaza model_id ⇒ BLOCKED_CONFIG + RETRY (no ERROR)', $m['blocked_config'] === 1 && $W->source->row($u)['status'] === 'RETRY');

echo "== SI4 · Conflictos ⇒ MANUAL_REVIEW + markError (sin recrear ni borrar) ==\n";
$W = new Si4World();
$W->snipe->seed(['asset_tag' => 'OTRO', 'serial' => 'SN-DUP', 'company_id' => 7, 'model_id' => 31, 'status_id' => 5]);
$u = $W->unit(['serial' => 'SN-DUP']);
$m = $W->worker()->run();
ok('serial repetido en Snipe ⇒ serial_conflict (MANUAL_REVIEW, outbox ERROR)', $m['manual_review'] === 1 && $W->sagas->get($u)['last_error_class'] === 'serial_conflict' && $W->source->row($u)['status'] === 'ERROR');
$W = new Si4World();
$u = $W->unit();
$id = $W->snipe->seed(['asset_tag' => $W->tag($u), 'company_id' => 7, 'model_id' => 31, 'status_id' => 5]);
$W->snipe->softDelete($id);
$m = $W->worker()->run();
ok('activo con nuestro tag soft-deleted ⇒ MANUAL_REVIEW, NO se recrea', $m['manual_review'] === 1 && $W->posts() === 0);
$W = new Si4World();
$u = $W->unit();
$W->snipe->seed(['asset_tag' => $W->tag($u), 'company_id' => 8, 'model_id' => 31, 'status_id' => 5]);
$m = $W->worker()->run();
ok('activo preexistente con nuestro tag en OTRA compañía ⇒ MANUAL_REVIEW (no se adopta)', $m['manual_review'] === 1
    && $W->sagas->get($u)['last_error_class'] === 'preexisting' && $W->sagas->get($u)['snipe_asset_id'] === null && $W->posts() === 0);

echo "== SI4 · Identidad remota: modelo + marca de procedencia (caso A preexistente / caso B recuperación) ==\n";
// Caso A — el escenario exacto del blocker: mismo tag, misma compañía, sin serial, OTRO modelo.
$W = new Si4World();
$u = $W->unit();
$W->snipe->seed(['asset_tag' => $W->tag($u), 'company_id' => 7, 'model_id' => 32, 'status_id' => 5]);
$m = $W->worker()->run();
ok('🔒 A: tag + compañía correctos, sin serial, OTRO modelo ⇒ MANUAL_REVIEW sin vincular ni crear', $m['manual_review'] === 1
    && $W->sagas->get($u)['snipe_asset_id'] === null && $W->source->row($u)['status'] === 'ERROR' && $W->posts() === 0);
$W = new Si4World();
$u = $W->unit();
$W->snipe->seed(['asset_tag' => $W->tag($u), 'company_id' => 7, 'model_id' => 31, 'status_id' => 5, 'notes' => RemoteAssetMatcher::marker($u)]);
$m = $W->worker()->run();
ok('🔒 A: tag preexistente aunque traiga todo (incluso la marca) ⇒ MANUAL_REVIEW (sin POST previo de la saga)', $m['manual_review'] === 1
    && $W->sagas->get($u)['last_error_class'] === 'preexisting' && $W->sagas->get($u)['snipe_asset_id'] === null);
// Caso B — crash después del POST y el activo remoto ya no corresponde a la unidad.
$crash = fn (string $p) => $p === 'after_remote_create' ? throw new SimulatedCrash('muere tras el POST') : null;
$caseB = function (callable $tamper, string $label, string $expectClass) use ($crash): void {
    $W = new Si4World();
    $u = $W->unit();
    si4Throws(fn () => $W->worker($crash)->run());
    $id = (int) $W->snipe->liveByTag($W->tag($u))[0]['id'];
    $tamper($W, $id, $u);
    $W->source->expire($u);
    $W->now += 5;
    $m = $W->worker()->run();
    $s = $W->sagas->get($u);
    ok("🔒 B: {$label} ⇒ MANUAL_REVIEW sin vincular snipe_asset_id (1 POST)", $m['manual_review'] === 1 && $s['state'] === SagaState::MANUAL_REVIEW
        && $s['last_error_class'] === $expectClass && $s['snipe_asset_id'] === null && $W->posts() === 1 && $W->source->row($u)['status'] === 'ERROR');
};
$caseB(function (Si4World $W, int $id): void { $W->snipe->assets[$id]['model_id'] = 32; }, 'tag + compañía correctos, OTRO modelo', 'model_mismatch');
$caseB(function (Si4World $W, int $id): void { $W->snipe->assets[$id]['notes'] = 'alta manual en Snipe'; }, 'tag/compañía/modelo correctos, marca AUSENTE', 'ownership_mismatch');
$caseB(function (Si4World $W, int $id): void { $W->snipe->assets[$id]['notes'] = 'GLPI2 SI-4 · ' . RemoteAssetMatcher::marker('3f9a1c2b-7d4e-4f60-8a1b-0c2d3e4f5a6c'); }, 'marca de OTRO receipt_unit_uuid', 'ownership_mismatch');
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-B-1']);
si4Throws(fn () => $W->worker($crash)->run());
$W->source->expire($u);
$W->now += 5;
$m = $W->worker()->run();
$s = $W->sagas->get($u);
ok('B: crash tras el POST ⇒ GET por tag con modelo/compañía/serial/marca coincidentes ⇒ RECONCILED con exactamente 1 POST', $m['reconciled'] === 1
    && $s['state'] === SagaState::SNIPE_CREATED && (int) $s['snipe_asset_id'] === (int) $W->snipe->liveByTag($W->tag($u))[0]['id'] && $W->posts() === 1);

echo "== SI4 · Verificación posterior al POST con las MISMAS exigencias ==\n";
foreach ([
    ['modelo distinto', fn (Si4World $W, string $u) => $W->snipe->assets[(int) $W->snipe->liveByTag($W->tag($u))[0]['id']]['model_id'] = 32],
    ['marca distinta', fn (Si4World $W, string $u) => $W->snipe->assets[(int) $W->snipe->liveByTag($W->tag($u))[0]['id']]['notes'] = RemoteAssetMatcher::marker('3f9a1c2b-7d4e-4f60-8a1b-0c2d3e4f5a6c')],
] as [$label, $tamper]) {
    $W = new Si4World();
    $u = $W->unit();
    $m = $W->worker(function (string $p, string $uu) use ($W, $tamper): void {
        if ($p === 'after_remote_create') {
            $tamper($W, $uu);
        }
    })->run();
    $s = $W->sagas->get($u);
    ok("🔒 POST success pero el GET posterior trae {$label} ⇒ MANUAL_REVIEW (nunca SNIPE_CREATED)", $m['manual_review'] === 1 && $m['created'] === 0
        && $s['state'] === SagaState::MANUAL_REVIEW && $s['last_error_class'] === 'post_verify' && $W->source->row($u)['status'] === 'ERROR');
}
$W = new Si4World();
$u = $W->unit(['serial' => 'SN-PV-1']);
$m = $W->worker(function (string $p, string $uu) use ($W): void {
    if ($p === 'after_remote_create') {
        $W->snipe->assets[(int) $W->snipe->liveByTag($W->tag($uu))[0]['id']]['serial'] = 'SN-PV-OTRO';
    }
})->run();
ok('🔒 POST success pero el GET posterior trae serial distinto ⇒ MANUAL_REVIEW (no se relaja el serial tras crear)', $m['manual_review'] === 1
    && $W->sagas->get($u)['last_error_class'] === 'post_verify');
$W = new Si4World();
$u = $W->unit();
$m = $W->worker(function (string $p) use ($W): void {
    if ($p === 'after_record_created') {
        foreach ([1, 2, 3] as $_) {
            $W->snipe->failNext('GET', '/api/v1/hardware/bytag', FakeSnipeServer::S500_BEFORE);
        }
    }
})->run();
$s = $W->sagas->get($u);
ok('GET posterior no disponible ⇒ RETRY; la saga queda SNIPE_CREATING con el id NO verificado', $m['retry'] === 1 && $s['state'] === SagaState::SNIPE_CREATING
    && (int) $s['snipe_asset_id'] > 0 && $W->source->row($u)['status'] === 'RETRY');
$W->now = $W->source->row($u)['next_retry_at'] + 1;
$m = $W->worker()->run();
ok('… el retry verifica igual y recién ahí SNIPE_CREATED (outcome created, 1 POST)', $m['created'] === 1 && $W->sagas->get($u)['state'] === SagaState::SNIPE_CREATED
    && $W->sagas->get($u)['snipe_outcome'] === 'created' && $W->posts() === 1);
$W = new Si4World();
$u = $W->unit();
$W->worker(function (string $p) use ($W): void {
    if ($p === 'after_record_created') {
        foreach ([1, 2, 3] as $_) {
            $W->snipe->failNext('GET', '/api/v1/hardware/bytag', FakeSnipeServer::S500_BEFORE);
        }
    }
})->run();
$W->snipe->assets = []; // el activo creado por esta saga desaparece de Snipe
$W->now = $W->source->row($u)['next_retry_at'] + 1;
$m = $W->worker()->run();
ok('🔒 el activo creado por esta saga ya no aparece ⇒ MANUAL_REVIEW, NUNCA un segundo POST', $m['manual_review'] === 1
    && $W->sagas->get($u)['last_error_class'] === 'created_asset_missing' && $W->posts() === 1);
$W = new Si4World();
$u = $W->unit(['request_number' => 'SC_2026_ 0011', 'correlation_id' => 'corr_x']);
$m = $W->worker()->run();
$notes = (string) $W->snipe->assets[(int) $W->sagas->get($u)['snipe_asset_id']]['notes'];
ok('la marca va AL FINAL de notes y sobrevive al markdown de Snipe (created + verificado)', $m['created'] === 1 && str_ends_with($notes, RemoteAssetMatcher::marker($u)));
$W = new Si4World();
$u = $W->unit();
$dup = function (string $p, string $uu) use ($W) {
    if ($p === 'after_remote_create') {
        $W->snipe->seed(['asset_tag' => $W->tag($uu), 'company_id' => 7, 'model_id' => 31, 'status_id' => 5]);
    }
};
$m = $W->worker($dup)->run();
ok('verificación posterior detecta duplicado ⇒ MANUAL_REVIEW (no se borra nada)', $m['manual_review'] === 1 && count($W->snipe->liveByTag($W->tag($u))) === 2);

echo "== SI4 · Multi-entidad ==\n";
$W = new Si4World();
$e1 = $W->unit(['entity_id' => 1]);
$e2 = $W->unit(['entity_id' => 2, 'category' => 'MON']);
$W->source->setActiveEntities([1]);
$W->worker()->run();
ok('worker de la entidad 1 no toma la unidad de la entidad 2', $W->source->row($e2)['status'] === 'PENDING' && $W->sagas->get($e2) === null);
$W->source->setActiveEntities([2]);
$W->worker()->run();
$c1 = $W->snipe->assets[(int) $W->sagas->get($e1)['snipe_asset_id']]['company_id'];
$c2 = $W->snipe->assets[(int) $W->sagas->get($e2)['snipe_asset_id']]['company_id'];
ok('cada unidad se crea en la compañía mapeada a SU entidad', $c1 === 7 && $c2 === 8);

echo "== SI4 · Dos workers ⇒ una sola saga; lease vencido ⇒ el worker viejo no finaliza ==\n";
$W = new Si4World();
$u = $W->unit();
$tokenA = null;
$bResult = null;
$probeA = function (string $p, string $uu) use ($W, &$tokenA, &$bResult) {
    if ($p === 'after_remote_create') {
        $tokenA = $W->source->row($uu)['token'];
        // El worker A se "congela" tras el POST: su lease vence y el worker B toma la unidad.
        $W->now += 901;
        $bResult = $W->worker(null, ['si4_worker_id' => 'w-B'])->run();
    }
};
$mA = $W->worker($probeA, ['si4_worker_id' => 'w-A'])->run();
$s = $W->sagas->get($u);
ok('B toma la saga vencida y RECONCILIA (sin segundo POST)', ($bResult['reconciled'] ?? 0) === 1 && $W->posts() === 1 && $W->assetsFor($u) === 1);
ok('🔒 A no puede persistir: fencing ⇒ lease_lost', $mA['lease_lost'] === 1);
ok('🔒 una sola saga, dueño = B', count(array_filter($W->sagas->rows, fn ($r) => $r['receipt_unit_uuid'] === $u)) === 1 && $s['worker_id'] === 'w-B' && $s['state'] === SagaState::SNIPE_CREATED);
ok('🔒 A no puede finalizar el outbox (ack/retry/error rechazados)', si4Throws(fn () => $W->source->acknowledgeProcessed($u, (string) $tokenA))
    && si4Throws(fn () => $W->source->markRetry($u, (string) $tokenA, 'x', new \DateTimeImmutable('@' . ($W->now + 60))))
    && si4Throws(fn () => $W->source->markError($u, (string) $tokenA, 'x')));
$meta = ['entities_id' => 1, 'requests_id' => 11, 'items_id' => 21, 'payload_sha256' => (string) $s['payload_sha256'], 'correlation_id' => 'c'];
ok('🔒 un claim VIEJO (época menor) no puede tomar la saga', $W->sagas->acquire($u, $meta, hash('sha256', 'viejo'), gmdate('Y-m-d H:i:s', $W->now + 900), 1, 'w-old') === null
    && $W->sagas->get($u)['worker_id'] === 'w-B');
$W2 = new Si4World();
$u = $W2->unit();
$W2->source->claimPending('w-A', 1, 900);
$m = $W2->worker(null, ['si4_worker_id' => 'w-B'])->run();
ok('con el lease de A vigente, B no obtiene la unidad (claim exclusivo)', $m['claimed'] === 0 && $W2->sagas->get($u) === null);

echo "== SI4 · Guardas ==\n";
$W = new Si4World();
$ug = $W->unit();
ok('deshabilitado ⇒ no reclama nada', $W->worker(null, ['si4_enabled' => '0'])->run()['aborted'] === 'disabled' && $W->source->row($ug)['status'] === 'PENDING' && $W->snipe->requests === []);
$m = $W->worker(null, ['si4_snipe_status_id' => '0'])->run();
ok('configuración inválida ⇒ no reclama nada', $m['aborted'] === 'config' && $m['claimed'] === 0);
$m = $W->worker(null, ['si4_snipe_status_id' => '99'])->run();
ok('status inexistente en Snipe (preflight) ⇒ no reclama nada', $m['aborted'] === 'config' && $m['claimed'] === 0);
$W = new Si4World();
$u = $W->unit();
ok('cambio de prefijo entre intentos: se conserva el tag registrado', si4Throws(fn () => $W->worker(fn (string $p) => $p === 'after_remote_create' ? throw new SimulatedCrash('x') : null)->run()));
$W->source->expire($u);
$W->now += 5;
$m = $W->worker(null, ['si4_asset_tag_prefix' => 'NEW-'])->run();
ok('… reconcilia con el tag original (1 activo)', $m['reconciled'] === 1 && $W->assetsFor($u) === 1 && $W->posts() === 1);

echo "== SI4 · Token nunca en logs / saga / outbox ==\n";
$W = new Si4World();
$u = $W->unit();
$W->snipe->failNext('POST', '/api/v1/hardware', FakeSnipeServer::S401);
$W->worker()->run();
$W2 = new Si4World();
$u2 = $W2->unit();
// Transporte "indiscreto": el mensaje de error incluye el header Authorization (defensa en profundidad).
$W2->snipe->failNext('POST', '/api/v1/hardware', FakeSnipeServer::LEAKY_ERROR);
$W2->worker()->run();
$blob = implode("\n", array_merge($W->logs, $W2->logs)) . json_encode($W->sagas->rows) . json_encode($W->sagas->log)
    . json_encode($W->source->retries) . json_encode($W2->sagas->rows) . json_encode($W2->source->retries);
ok('🔒 el token NUNCA aparece en logs, saga, bitácora ni last_error del outbox', $W->logs !== [] && !str_contains($blob, SI4_TOKEN));
// Excepciones internas cuyo mensaje trae una credencial: el motivo que llega a markRetry/markError se sanea igual.
$W3 = new Si4World();
$u3 = $W3->unit();
$W3->mapping = new ArrayMappingResolver([], [], 5);
$throwing = new class implements MappingResolver {
    public function resolve(array $payload): array
    {
        throw new \RuntimeException('mapping db down; Authorization: Bearer ' . SI4_TOKEN);
    }
};
$logs3 = [];
$lg3 = function (string $l, string $m, array $c) use (&$logs3): void {
    $logs3[] = $l . ' ' . $m . ' ' . json_encode($c);
};
$wr3 = new SnipeAssetWriter($W3->snipe, new SnipeClientConfig('https://snipe.test', SI4_TOKEN, 5000, 2, 1, 50, 60), $lg3, false);
$m3 = (new Si4Worker($W3->source, $W3->sagas, $throwing, $wr3, Si4Config::fromArray($W3->cfg), 5000, 2, $lg3, null, fn (): int => $W3->now))->run();
ok('🔒 excepción inesperada con token ⇒ markRetry con motivo SANEADO', $m3['retry'] === 1 && $W3->source->retries !== []
    && !str_contains(json_encode($W3->source->retries) . json_encode($W3->source->row($u3)) . implode("\n", $logs3), SI4_TOKEN));
$W4 = new Si4World();
$u4 = $W4->unit();
$failingGet = new class ($W4->source) implements HandoffSource {
    public function __construct(private InMemoryHandoffSource $inner)
    {
    }
    public function claimPending(string $workerId, int $limit, int $leaseSeconds): array
    {
        return $this->inner->claimPending($workerId, $limit, $leaseSeconds);
    }
    public function getHandoff(string $receiptUnitUuid): ?array
    {
        throw new \RuntimeException('payload integrity check failed token=' . SI4_TOKEN);
    }
    public function acknowledgeProcessed(string $receiptUnitUuid, string $leaseToken): array
    {
        return $this->inner->acknowledgeProcessed($receiptUnitUuid, $leaseToken);
    }
    public function markRetry(string $receiptUnitUuid, string $leaseToken, string $error, \DateTimeInterface $nextRetryAt): array
    {
        return $this->inner->markRetry($receiptUnitUuid, $leaseToken, $error, $nextRetryAt);
    }
    public function markError(string $receiptUnitUuid, string $leaseToken, string $error): array
    {
        return $this->inner->markError($receiptUnitUuid, $leaseToken, $error);
    }
};
$logs4 = [];
$lg4 = function (string $l, string $m, array $c) use (&$logs4): void {
    $logs4[] = $l . ' ' . $m . ' ' . json_encode($c);
};
$wr4 = new SnipeAssetWriter($W4->snipe, new SnipeClientConfig('https://snipe.test', SI4_TOKEN, 5000, 2, 1, 50, 60), $lg4, false);
$m4 = (new Si4Worker($failingGet, $W4->sagas, $W4->mapping, $wr4, Si4Config::fromArray($W4->cfg), 5000, 2, $lg4, null, fn (): int => $W4->now))->run();
ok('🔒 getHandoff falla con token ⇒ markError con motivo SANEADO', $m4['error'] === 1 && $W4->source->errors !== []
    && !str_contains(json_encode($W4->source->errors) . json_encode($W4->source->row($u4)) . implode("\n", $logs4), SI4_TOKEN));
ok('🔒 la saga guarda sólo la huella del lease (no el token)', !str_contains(json_encode($W->sagas->rows), (string) $W->source->row($u)['token']));

echo "== SI4 · Nunca acknowledgeProcessed en SI4-1 (todas las corridas) ==\n";
$acks = 0;
foreach (Si4World::$all as $world) {
    $acks += count($world->source->acks);
}
ok('🔒 ninguna de las ' . count(Si4World::$all) . ' corridas del worker llamó acknowledgeProcessed()', $acks === 0 && count(Si4World::$all) >= 25);
