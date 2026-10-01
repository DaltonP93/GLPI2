<?php

/**
 * Worker de la saga SI-4 — incremento SI4-1 (ADR-0020): consume el handoff de Compras POR LEASE y lleva cada unidad
 * hasta `SNIPE_CREATED` con create-or-reconcile IDEMPOTENTE del activo en Snipe-IT.
 *
 * Por unidad (una por claim; el lease cubre el peor caso):
 *   1. `getHandoff()` fresco: sigue LEASED y con el mismo hash del claim (payload inmutable), si no ⇒ no se toca.
 *   2. Saga propia: se crea o se TOMA con fencing (época monótona del lease = `attempts` del claim; sha256 del token +
 *      lease_until con el reloj de la BD para cada escritura).
 *   3. Saga ya en `SNIPE_CREATED` ⇒ ESTACIONADA: ninguna llamada remota, ningún settle (SI4-1 no termina SI-4).
 *   4. Mapeos validados (compañía/modelo/estado) — ausentes ⇒ BLOCKED_CONFIG + `markRetry` tardío.
 *   5. Tag determinista (el ya registrado en la saga, si existe) y BUSCAR PRIMERO (`bytag?deleted=true`). Sólo se
 *      adopta un activo si TODO coincide (tag, compañía, modelo, serial si lo hay y la marca de procedencia
 *      `receipt_unit_uuid=<uuid>` en notes) Y la saga ya había intentado crear (recuperación de su propio POST); un tag
 *      preexistente o cualquier diferencia ⇒ MANUAL_REVIEW + `markError` (`RemoteAssetMatcher`).
 *   6. Intención de creación persistida exigiendo lease restante ≥ presupuesto de escritura, y UN POST.
 *   7. Resultado del POST clasificado (ver `CreateResult`). Creado ⇒ `snipe_asset_id` se registra NO verificado
 *      (SNIPE_CREATING) y sólo pasa a SNIPE_CREATED si el GET posterior cumple exactamente las mismas propiedades.
 *
 * SI4-2 (ADR-0021), con `Si4GlpiStage` inyectada: desde `SNIPE_CREATED` la MISMA pasada (o un claim posterior) sigue
 * con el activo GLPI, su Infocom y `asset_bridge` hasta `BRIDGED`. Al reanudar desde una etapa post-Snipe, el activo de
 * Snipe se RELEE antes de escribir en GLPI (tag, compañía y modelo registrados, serial, marca y mismo id); si diverge ⇒
 * MANUAL_REVIEW. Sin la etapa GLPI (modo SI4-1) la unidad se estaciona en `SNIPE_CREATED` como antes.
 *
 * SI4-3 (ADR-0022), con `Si4QrStage` inyectada: desde `BRIDGED` la MISMA saga obtiene el código companyqr ACTIVO del
 * activo (API pública de companyqr) y verifica que la etiqueta se renderiza ⇒ `QR_READY`; revalida puente/activo/código
 * y RECIÉN ENTONCES llama `acknowledgeProcessed()` — el ÚLTIMO efecto externo, el único punto del worker que lo hace —
 * y cierra la saga en `COMPLETED` por el finalizador (`Si4Finalizer`: sólo con el outbox DONE). Un ack fallido deja la
 * saga en QR_READY y el outbox sin DONE (reintento sin rehacer nada); un crash tras el ack lo cierra el finalizador,
 * que corre al inicio de cada corrida. Sin la etapa QR (modos SI4-1/SI4-2) NUNCA hay ack: la unidad se estaciona.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Client\CreateResult;
use GlpiPlugin\Companyintegrations\Client\SnipeAssetWriter;
use GlpiPlugin\Companyintegrations\Client\SnipeException;
use GlpiPlugin\Companyintegrations\Service\CorrelationId;
use GlpiPlugin\Companyintegrations\Service\LogSanitizer;

final class Si4Worker
{
    // Resultados por unidad (métricas de la corrida).
    public const R_CREATED        = 'created';
    public const R_RECONCILED     = 'reconciled';
    public const R_PARKED         = 'parked';
    public const R_BLOCKED_CONFIG = 'blocked_config';
    public const R_MANUAL_REVIEW  = 'manual_review';
    public const R_RETRY          = 'retry';
    public const R_UNCERTAIN      = 'uncertain';
    public const R_LEASE_LOST     = 'lease_lost';
    public const R_ERROR          = 'error';
    public const R_BRIDGED        = 'bridged';
    public const R_QR_READY       = 'qr_ready';
    public const R_COMPLETED      = 'completed';

    private HandoffSource $source;
    private SagaStore $sagas;
    private MappingResolver $mapping;
    private SnipeAssetWriter $writer;
    private Si4Config $cfg;
    private int $timeoutMs;
    private int $maxRetries;
    /** @var callable(string,string,array<string,mixed>):void */
    private $logger;
    /** @var callable(string,string):void|null */
    private $probe;
    /** @var callable():int */
    private $clock;
    private LogSanitizer $sanitizer;
    private ?string $abort = null;
    private ?Si4GlpiStage $glpi;
    private ?Si4QrStage $qr;
    private ?Si4Finalizer $finalizer = null;
    /** @var array{payload:array<string,mixed>, attempts:int, corr:string}|null contexto de la unidad en proceso */
    private ?array $unit = null;
    /** @var array<string,int> sub-resultados de la corrida (Snipe/GLPI) */
    private array $stats = [];

    /**
     * @param callable(string,string,array<string,mixed>):void|null $logger
     * @param callable(string,string):void|null $probe  punto de inyección de fallas SÓLO para tests (null en producción)
     * @param callable():int|null $clock
     * @param Si4GlpiStage|null $glpi etapas GLPI de SI4-2 (null = modo SI4-1: estacionar en SNIPE_CREATED)
     * @param Si4QrStage|null $qr etapa QR + ack + finalización de SI4-3 (null = modo SI4-2: estacionar en BRIDGED, sin ack)
     * @param FinalizerCursor|null $finalizerCursor cursor DURABLE del finalizador (obligatorio con la etapa QR)
     */
    public function __construct(
        HandoffSource $source,
        SagaStore $sagas,
        MappingResolver $mapping,
        SnipeAssetWriter $writer,
        Si4Config $cfg,
        int $timeoutMs,
        int $maxRetries,
        ?callable $logger = null,
        ?callable $probe = null,
        ?callable $clock = null,
        ?Si4GlpiStage $glpi = null,
        ?Si4QrStage $qr = null,
        ?FinalizerCursor $finalizerCursor = null
    ) {
        $this->source     = $source;
        $this->sagas      = $sagas;
        $this->mapping    = $mapping;
        $this->writer     = $writer;
        $this->cfg        = $cfg;
        $this->timeoutMs  = $timeoutMs;
        $this->maxRetries = $maxRetries;
        $this->logger     = $logger ?? static function (): void {};
        $this->probe      = $probe;
        $this->clock      = $clock ?? static fn (): int => time();
        $this->sanitizer  = new LogSanitizer();
        $this->glpi       = $glpi;
        $this->qr         = $glpi !== null ? $qr : null; // la etapa QR sigue a la GLPI
        if ($this->qr !== null) {
            if ($finalizerCursor === null) {
                throw new \InvalidArgumentException('la etapa QR exige el cursor durable del finalizador');
            }
            $this->finalizer = new Si4Finalizer($sagas, $source, $finalizerCursor, fn (string $l, string $msg, array $c) => $this->log($l, $msg, $c));
        }
    }

    public function workerId(): string
    {
        if ($this->cfg->workerId !== '') {
            return $this->cfg->workerId;
        }
        $host = preg_replace('/[^A-Za-z0-9._-]/', '', (string) gethostname()) ?: 'host';
        return 'si4:' . substr($host, 0, 60) . ':' . getmypid();
    }

    /**
     * Una corrida: preflight (config + Snipe) ANTES de reclamar; luego claims de a una unidad hasta vaciar, agotar
     * `si4_max_units_per_run` o abortar (auth/circuit).
     *
     * SI4-3: ANTES de todo (sin depender de Snipe) el finalizador cierra las sagas QR_READY cuyo outbox ya está DONE.
     *
     * @return array<string,mixed> métricas: claimed, created, reconciled, bridged, qr_ready, completed, parked,
     *                             blocked_config, manual_review, retry, uncertain, lease_lost, error, finalized, aborted
     *                             (motivo o null) y los sub-resultados snipe_created, snipe_reconciled, glpi_created,
     *                             glpi_linked, qr_created, qr_existing
     */
    public function run(): array
    {
        $m = ['claimed' => 0, self::R_CREATED => 0, self::R_RECONCILED => 0, self::R_BRIDGED => 0, self::R_QR_READY => 0, self::R_COMPLETED => 0,
              self::R_PARKED => 0, self::R_BLOCKED_CONFIG => 0, self::R_MANUAL_REVIEW => 0, self::R_RETRY => 0, self::R_UNCERTAIN => 0,
              self::R_LEASE_LOST => 0, self::R_ERROR => 0, 'finalized' => 0, 'aborted' => null, 'config_errors' => []];
        $this->abort = null;
        $this->stats = ['snipe_created' => 0, 'snipe_reconciled' => 0, 'glpi_created' => 0, 'glpi_linked' => 0, 'qr_created' => 0, 'qr_existing' => 0];
        if (!$this->cfg->enabled) {
            $m['aborted'] = 'disabled';
            return $m;
        }
        if ($this->finalizer !== null) {
            // Crash tras un ack: la saga quedó en QR_READY con el outbox ya DONE ⇒ COMPLETED (sin lease, sin Snipe). Pasada
            // ACOTADA del recorrido round-robin con cursor durable: ninguna saga pendiente bloquea a las posteriores.
            $m['finalized'] = $this->finalizer->run()[Si4Finalizer::F_COMPLETED];
        }
        $errors = $this->cfg->errors($this->timeoutMs, $this->maxRetries);
        if ($errors !== []) {
            $m['aborted'] = 'config';
            $m['config_errors'] = $errors;
            $this->log('error', 'configuración SI-4 inválida: no se reclama nada', ['errors' => implode('; ', $errors)]);
            return $m;
        }
        $pre = $this->preflight();
        if ($pre !== null) {
            $m['aborted'] = $pre;
            return $m;
        }
        $worker = $this->workerId();
        for ($i = 0; $i < $this->cfg->maxPerRun; $i++) {
            try {
                $claimed = $this->source->claimPending($worker, 1, $this->cfg->leaseSeconds);
            } catch (\Exception $e) {
                $m['aborted'] = 'source';
                $this->log('error', 'claimPending falló', ['error' => Si4Errors::sanitize($e->getMessage())]);
                break;
            }
            if ($claimed === []) {
                break;
            }
            $m['claimed']++;
            $r = $this->processUnit($claimed[0], $worker);
            $m[$r] = ($m[$r] ?? 0) + 1;
            if ($this->abort !== null) {
                $m['aborted'] = $this->abort;
                break;
            }
        }
        $m += $this->stats;
        $this->log('info', 'corrida SI-4 terminada', array_diff_key($m, ['config_errors' => 1]));
        return $m;
    }

    /** Preflight remoto ANTES de reclamar: credencial válida y status configurado existente. null = OK. */
    private function preflight(): ?string
    {
        $corr = CorrelationId::generate('si4pre');
        try {
            if ($this->writer->statusLabel($this->cfg->statusId, $corr) === null) {
                $this->log('error', 'preflight: si4_snipe_status_id no existe en Snipe', ['status_id' => $this->cfg->statusId]);
                return 'config';
            }
        } catch (SnipeException $e) {
            $this->log('error', 'preflight Snipe falló: no se reclama nada', ['kind' => $e->kind]);
            return $e->kind === SnipeException::AUTH ? 'auth' : 'snipe_unavailable';
        }
        return null;
    }

    /** @param array<string,mixed> $c fila de claimPending */
    private function processUnit(array $c, string $worker): string
    {
        $uuid    = (string) $c['receipt_unit_uuid'];
        $token   = (string) $c['lease_token'];
        $sha     = (string) $c['payload_sha256'];
        $payload = (array) $c['payload'];
        $attempts = (int) ($c['attempts'] ?? 1);
        $tokenSha = hash('sha256', $token);
        $corr = is_string($payload['correlation_id'] ?? null) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $payload['correlation_id']) === 1
            ? $payload['correlation_id'] : CorrelationId::generate('si4');
        $ctx = ['receipt_unit_uuid' => $uuid, 'correlation_id' => $corr];
        $this->unit = ['payload' => $payload, 'attempts' => $attempts, 'corr' => $corr];

        try {
            // 1) Handoff fresco por la API de Compras (misma validación que el claim) + inmutabilidad del payload.
            try {
                $h = $this->source->getHandoff($uuid);
            } catch (\RuntimeException $e) {
                $this->settleError($uuid, $token, 'handoff inválido: ' . $e->getMessage(), $ctx);
                return self::R_ERROR;
            }
            if ($h === null || (string) ($h['status'] ?? '') !== 'LEASED') {
                $this->log('warning', 'handoff ya no está en LEASED para este worker', $ctx);
                return self::R_LEASE_LOST;
            }
            if (!hash_equals((string) ($h['payload_sha256'] ?? ''), $sha)) {
                $this->settleError($uuid, $token, 'payload distinto del reclamado (inmutabilidad)', $ctx);
                return self::R_ERROR;
            }

            // 2) Saga con fencing.
            $saga = $this->sagas->acquire($uuid, [
                'entities_id' => (int) ($payload['entity_id'] ?? 0), 'requests_id' => (int) ($payload['request_id'] ?? 0),
                'items_id' => (int) ($payload['item_id'] ?? 0), 'payload_sha256' => $sha, 'correlation_id' => $corr,
            ], $tokenSha, (string) $c['leased_until'], $attempts, $worker);
            if ($saga === null) {
                $this->log('warning', 'saga en poder de un lease más nuevo (claim viejo): no se toca', $ctx);
                return self::R_LEASE_LOST;
            }
            $state = (string) $saga['state'];
            if (!hash_equals((string) $saga['payload_sha256'], $sha)) {
                return $this->manualReview($uuid, $token, $tokenSha, $state, 'payload distinto del procesado antes (inmutabilidad)', 'payload_changed', $ctx);
            }

            // 3) Etapas ya cumplidas.
            $resume = (string) ($saga['resume_state'] ?? '');
            if ($state === SagaState::COMPLETED) {
                // No debería ocurrir (COMPLETED exige el outbox DONE): no se toca nada ni se confirma de nuevo.
                $this->log('warning', 'saga COMPLETED con su handoff reclamado otra vez: no se toca', $ctx);
                return self::R_PARKED;
            }
            if (in_array($state, [SagaState::BRIDGED, SagaState::QR_READY], true)
                || ($state === SagaState::BLOCKED_CONFIG && $resume === SagaState::BRIDGED)) {
                if ($this->qr === null) {
                    $this->log('info', 'saga estacionada en ' . $state . ' (modo SI4-2): sin llamadas remotas, sin escrituras ni ack', $ctx);
                    return self::R_PARKED;
                }
                return $this->continueQr($uuid, $token, $tokenSha, $attempts, $ctx);
            }
            if (in_array($state, SagaState::POST_SNIPE, true)
                || ($state === SagaState::BLOCKED_CONFIG && in_array($resume, SagaState::POST_SNIPE, true))) {
                if ($this->glpi === null) {
                    $this->log('info', 'saga estacionada en ' . $state . ' (modo SI4-1): sin llamadas remotas ni ack', $ctx);
                    return self::R_PARKED;
                }
                return $this->continueGlpi($uuid, $token, $tokenSha, $payload, $attempts, $corr, $ctx, false);
            }
            if ($state === SagaState::MANUAL_REVIEW) {
                $this->settleError($uuid, $token, 'saga en revisión manual: ' . (string) ($saga['last_error'] ?? ''), $ctx);
                return self::R_MANUAL_REVIEW;
            }

            // 4) Mapeos validados.
            $map = $this->mapping->resolve($payload);
            if (!$map['ok']) {
                if (!$this->sagas->transition($uuid, $tokenSha, $state, ['state' => SagaState::BLOCKED_CONFIG,
                    'last_error' => Si4Errors::sanitize($map['reason']), 'last_error_class' => 'mapping'], 'blocked_config', $map['reason'])) {
                    return self::R_LEASE_LOST;
                }
                $this->settleRetry($uuid, $token, $map['reason'], $this->cfg->configRetrySec, $ctx);
                return self::R_BLOCKED_CONFIG;
            }

            // 5) Identidad remota determinista (estable: la ya registrada gana) y BUSCAR PRIMERO.
            $tag = (string) ($saga['snipe_asset_tag'] ?? '');
            if ($tag === '') {
                $tag = AssetTagDeriver::tagFor($this->cfg->prefix, $uuid);
            }
            $ctx['asset_tag'] = $tag;
            $ownCreate  = (int) ($saga['remote_create_calls'] ?? 0) > 0; // caso B: esta saga ya intentó crear
            $recordedId = (int) ($saga['snipe_asset_id'] ?? 0);         // creado por esta saga, aún sin verificar
            try {
                $rows = $this->writer->lookupByTag($tag, $corr);
            } catch (SnipeException $e) {
                return $this->onReadFailure($e, $uuid, $token, $tokenSha, $state, $attempts, $ctx);
            }
            $match = RemoteAssetMatcher::classify($rows, $tag, $map['company_id'], $map['model_id'], $this->serialOf($payload), $uuid, $ownCreate);
            if ($match['kind'] === RemoteAssetMatcher::ONE) {
                if ($recordedId > 0 && $recordedId !== $match['asset_id']) {
                    return $this->manualReview($uuid, $token, $tokenSha, $state, 'el activo del tag no es el que creó esta saga (#' . $recordedId . ')', 'id_mismatch', $ctx);
                }
                $outcome = $recordedId === $match['asset_id'] ? SagaState::OUTCOME_CREATED : SagaState::OUTCOME_RECONCILED;
                return $this->link($uuid, $token, $tokenSha, $state, $match['asset_id'], $tag, $map, $outcome, (int) ($saga['remote_create_calls'] ?? 0), $ctx);
            }
            if ($match['kind'] !== RemoteAssetMatcher::NONE) {
                return $this->manualReview($uuid, $token, $tokenSha, $state, 'remoto ' . $match['kind'] . ': ' . $match['detail'], $match['kind'], $ctx);
            }
            if ($recordedId > 0) {
                // Esta saga YA creó un activo y su tag no lo encuentra: nunca se crea un segundo.
                return $this->manualReview($uuid, $token, $tokenSha, $state, 'el activo creado por esta saga (#' . $recordedId . ') ya no aparece por su tag', 'created_asset_missing', $ctx);
            }

            // 6) Intención persistida con presupuesto de lease, luego UN POST.
            $calls = (int) ($saga['remote_create_calls'] ?? 0) + 1;
            if (!$this->sagas->transition($uuid, $tokenSha, $state, [
                'state' => SagaState::SNIPE_CREATING, 'snipe_asset_tag' => $tag, 'snipe_company_id' => $map['company_id'],
                'snipe_model_id' => $map['model_id'], 'snipe_status_id' => $map['status_id'], 'remote_create_calls' => $calls,
                'last_error' => null, 'last_error_class' => null,
            ], 'create_intent', 'POST #' . $calls, Si4Config::writeBudgetSeconds($this->timeoutMs))) {
                $this->log('warning', 'lease insuficiente para un POST seguro: no se envía', $ctx);
                return self::R_LEASE_LOST;
            }
            $state = SagaState::SNIPE_CREATING;
            $this->probe('before_remote_create', $uuid);
            $res = $this->writer->createAsset(self::assetFields($tag, $map, $payload, $uuid, $corr), $corr);
            $this->probe('after_remote_create', $uuid);

            // 7) Clasificación del POST.
            return $this->onCreateResult($res, $uuid, $token, $tokenSha, $state, $tag, $map, $payload, $attempts, $calls, $corr, $ctx);
        } catch (\Exception $e) {
            // Defensa: excepción inesperada ⇒ reintento acotado (nunca ack). `SimulatedCrash` (\Error) NO se atrapa.
            $this->log('error', 'error inesperado procesando la unidad', $ctx + ['error' => Si4Errors::sanitize($e->getMessage())]);
            $this->settleRetry($uuid, $token, 'error inesperado: ' . $e->getMessage(), $this->cfg->backoffSeconds($attempts), $ctx);
            return self::R_RETRY;
        }
    }

    /**
     * @param array{ok:bool, company_id:int, model_id:int, status_id:int, reason:string} $map
     * @param array<string,mixed> $payload @param array<string,mixed> $ctx
     */
    private function onCreateResult(CreateResult $res, string $uuid, string $token, string $tokenSha, string $state, string $tag, array $map, array $payload, int $attempts, int $calls, string $corr, array $ctx): string
    {
        switch ($res->kind) {
            case CreateResult::CREATED:
                return $this->recordAndVerifyCreated($res->assetId, $uuid, $token, $tokenSha, $state, $tag, $map, $payload, $attempts, $calls, $corr, $ctx);

            case CreateResult::VALIDATION:
                if ($res->hasField('asset_tag')) {
                    return $this->reconcileAfterConflict($uuid, $token, $tokenSha, $state, $tag, $map, $payload, $attempts, $calls, $corr, $ctx);
                }
                if ($res->hasField('serial')) {
                    return $this->manualReview($uuid, $token, $tokenSha, $state, 'serial_conflict: Snipe rechaza el serial (ya existe)', 'serial_conflict', $ctx);
                }
                if (array_intersect($res->fields, ['model_id', 'status_id', 'company_id']) !== []) {
                    $why = 'mapping rechazado por Snipe: ' . implode(',', $res->fields);
                    if (!$this->sagas->transition($uuid, $tokenSha, $state, ['state' => SagaState::BLOCKED_CONFIG,
                        'last_error' => Si4Errors::sanitize($why), 'last_error_class' => 'mapping_remote'], 'blocked_config', $why)) {
                        return self::R_LEASE_LOST;
                    }
                    $this->settleRetry($uuid, $token, $why, $this->cfg->configRetrySec, $ctx);
                    return self::R_BLOCKED_CONFIG;
                }
                return $this->manualReview($uuid, $token, $tokenSha, $state, 'validación Snipe: ' . implode(',', $res->fields), 'validation', $ctx);

            case CreateResult::CONFLICT:
                return $this->reconcileAfterConflict($uuid, $token, $tokenSha, $state, $tag, $map, $payload, $attempts, $calls, $corr, $ctx);

            case CreateResult::AUTH:
                $this->recordError($uuid, $tokenSha, $state, 'auth', 'Snipe rechazó la credencial (' . $res->httpStatus . ')');
                $this->settleRetry($uuid, $token, 'Snipe auth ' . $res->httpStatus . ' (fail-closed)', $this->cfg->authRetrySec, $ctx);
                $this->abort = 'auth';
                return self::R_RETRY;

            case CreateResult::RATE_LIMIT:
                $this->recordError($uuid, $tokenSha, $state, 'rate_limit', 'Snipe 429 (sin efecto)');
                $this->settleRetry($uuid, $token, 'Snipe 429 (rate limit)', $this->cfg->backoffSeconds($attempts, $res->retryAfterSec), $ctx);
                return self::R_RETRY;

            case CreateResult::UNCERTAIN:
                // El activo PUDO crearse: la saga queda en SNIPE_CREATING y el próximo intento BUSCA primero.
                $this->recordError($uuid, $tokenSha, $state, 'uncertain', 'POST incierto (' . $res->detail . ' ' . $res->httpStatus . ')');
                $this->settleRetry($uuid, $token, 'POST incierto (' . $res->detail . '): se buscará antes de reintentar', max($this->cfg->uncertainCooldownSec, $this->cfg->backoffSeconds($attempts)), $ctx);
                return self::R_UNCERTAIN;

            case CreateResult::CIRCUIT:
                $this->settleRetry($uuid, $token, 'circuit breaker abierto', $this->cfg->backoffSeconds($attempts), $ctx);
                $this->abort = 'circuit';
                return self::R_RETRY;

            default: // REJECTED
                return $this->manualReview($uuid, $token, $tokenSha, $state, 'POST rechazado (' . $res->httpStatus . ' ' . $res->detail . ')', 'rejected', $ctx);
        }
    }

    /**
     * "Ya existe" (validación de asset_tag o 409): el activo con NUESTRO tag lo creó un intento anterior (o una carrera):
     * buscar y vincular sólo si es exactamente uno vivo coherente.
     *
     * @param array{ok:bool, company_id:int, model_id:int, status_id:int, reason:string} $map
     * @param array<string,mixed> $payload @param array<string,mixed> $ctx
     */
    private function reconcileAfterConflict(string $uuid, string $token, string $tokenSha, string $state, string $tag, array $map, array $payload, int $attempts, int $calls, string $corr, array $ctx): string
    {
        try {
            $rows = $this->writer->lookupByTag($tag, $corr);
        } catch (SnipeException $e) {
            return $this->onReadFailure($e, $uuid, $token, $tokenSha, $state, $attempts, $ctx);
        }
        $match = RemoteAssetMatcher::classify($rows, $tag, $map['company_id'], $map['model_id'], $this->serialOf($payload), $uuid, true);
        if ($match['kind'] === RemoteAssetMatcher::ONE) {
            return $this->link($uuid, $token, $tokenSha, $state, $match['asset_id'], $tag, $map, SagaState::OUTCOME_RECONCILED, $calls, $ctx);
        }
        return $this->manualReview($uuid, $token, $tokenSha, $state, 'conflicto de tag sin activo vinculable (' . $match['kind'] . ')', 'tag_conflict', $ctx);
    }

    /**
     * POST exitoso: registra `snipe_asset_id` NO verificado (SNIPE_CREATING, con fencing) y verifica con un GET que el
     * activo cumpla EXACTAMENTE las mismas propiedades que exige la recuperación (tag, compañía, modelo, serial si lo
     * hay, marca de procedencia) y que sea el mismo id. Sólo entonces SNIPE_CREATED; cualquier diferencia ⇒
     * MANUAL_REVIEW; GET no disponible ⇒ RETRY (el próximo intento busca primero y verifica igual).
     *
     * @param array{ok:bool, company_id:int, model_id:int, status_id:int, reason:string} $map
     * @param array<string,mixed> $payload @param array<string,mixed> $ctx
     */
    private function recordAndVerifyCreated(int $assetId, string $uuid, string $token, string $tokenSha, string $state, string $tag, array $map, array $payload, int $attempts, int $calls, string $corr, array $ctx): string
    {
        try {
            $ok = $this->sagas->transition($uuid, $tokenSha, $state, [
                'state' => SagaState::SNIPE_CREATING, 'snipe_asset_id' => $assetId, 'snipe_asset_tag' => $tag,
                'snipe_outcome' => SagaState::OUTCOME_CREATED, 'last_error' => null, 'last_error_class' => null,
            ], 'snipe_created_unverified', 'POST #' . $calls . ' ⇒ #' . $assetId);
        } catch (\RuntimeException $e) {
            return $this->manualReview($uuid, $token, $tokenSha, $state, 'no se pudo registrar el activo remoto: ' . $e->getMessage(), 'link_unique', $ctx);
        }
        if (!$ok) {
            // Lease perdido tras el POST: el nuevo dueño lo encontrará por tag + marca (buscar primero) y lo vinculará.
            $this->log('warning', 'lease perdido al registrar el activo creado', $ctx + ['snipe_asset_id' => $assetId]);
            return self::R_LEASE_LOST;
        }
        $this->probe('after_record_created', $uuid);
        try {
            $rows = $this->writer->lookupByTag($tag, $corr);
        } catch (SnipeException $e) {
            return $this->onReadFailure($e, $uuid, $token, $tokenSha, $state, $attempts, $ctx);
        }
        $check = RemoteAssetMatcher::classify($rows, $tag, $map['company_id'], $map['model_id'], $this->serialOf($payload), $uuid, true);
        if ($check['kind'] !== RemoteAssetMatcher::ONE || $check['asset_id'] !== $assetId) {
            return $this->manualReview($uuid, $token, $tokenSha, $state, 'verificación posterior: ' . $check['kind'] . ' ' . $check['detail'], 'post_verify', $ctx);
        }
        return $this->link($uuid, $token, $tokenSha, $state, $assetId, $tag, $map, SagaState::OUTCOME_CREATED, $calls, $ctx);
    }

    /**
     * Persiste el vínculo VERIFICADO (`SNIPE_CREATED` + `snipe_asset_id`) con fencing. Sólo se llama con un activo que
     * `RemoteAssetMatcher` aceptó con todas sus comprobaciones (recuperación o verificación posterior al POST).
     *
     * @param array{ok:bool, company_id:int, model_id:int, status_id:int, reason:string} $map @param array<string,mixed> $ctx
     */
    private function link(string $uuid, string $token, string $tokenSha, string $state, int $assetId, string $tag, array $map, string $outcome, int $calls, array $ctx): string
    {
        try {
            $ok = $this->sagas->transition($uuid, $tokenSha, $state, [
                'state' => SagaState::SNIPE_CREATED, 'snipe_asset_id' => $assetId, 'snipe_asset_tag' => $tag, 'snipe_outcome' => $outcome,
                'snipe_company_id' => $map['company_id'], 'snipe_model_id' => $map['model_id'], 'snipe_status_id' => $map['status_id'],
                'remote_create_calls' => $calls, 'last_error' => null, 'last_error_class' => null,
            ], 'snipe_linked', $outcome . ' #' . $assetId);
        } catch (\RuntimeException $e) {
            return $this->manualReview($uuid, $token, $tokenSha, $state, 'no se pudo ligar el activo remoto: ' . $e->getMessage(), 'link_unique', $ctx);
        }
        if (!$ok) {
            // Lease perdido tras el POST: el nuevo dueño lo encontrará por tag (buscar primero) y lo vinculará.
            $this->log('warning', 'lease perdido al persistir el vínculo', $ctx + ['snipe_asset_id' => $assetId]);
            return self::R_LEASE_LOST;
        }
        $this->probe('after_link', $uuid);
        $this->stats['snipe_' . $outcome] = ($this->stats['snipe_' . $outcome] ?? 0) + 1;
        if ($this->glpi !== null && $this->unit !== null) {
            // SI4-2: el activo de Snipe se acaba de verificar en esta misma pasada ⇒ se sigue sin releerlo.
            $this->log('info', 'activo remoto vinculado y verificado; sigue la etapa GLPI', $ctx + ['snipe_asset_id' => $assetId, 'outcome' => $outcome]);
            return $this->continueGlpi($uuid, $token, $tokenSha, $this->unit['payload'], $this->unit['attempts'], $this->unit['corr'], $ctx, true);
        }
        $this->log('info', 'activo remoto vinculado y verificado; unidad estacionada en SNIPE_CREATED (sin ack: SI-4 incompleto)', $ctx + ['snipe_asset_id' => $assetId, 'outcome' => $outcome]);
        return $outcome === SagaState::OUTCOME_CREATED ? self::R_CREATED : self::R_RECONCILED;
    }

    /**
     * SI4-2: activo GLPI → Infocom → asset_bridge (ADR-0021) desde una etapa post-Snipe. Si el activo de Snipe no se
     * verificó en esta pasada, se RELEE antes de cualquier escritura en GLPI (nunca se corrige Snipe).
     *
     * @param array<string,mixed> $payload @param array<string,mixed> $ctx
     */
    private function continueGlpi(string $uuid, string $token, string $tokenSha, array $payload, int $attempts, string $corr, array $ctx, bool $snipeVerified): string
    {
        $saga = $this->sagas->get($uuid);
        if ($saga === null || $this->glpi === null) {
            return self::R_LEASE_LOST;
        }
        $state = (string) $saga['state'];
        if (!$snipeVerified) {
            $tag = (string) ($saga['snipe_asset_tag'] ?? '');
            try {
                $rows = $this->writer->lookupByTag($tag, $corr);
            } catch (SnipeException $e) {
                return $this->onReadFailure($e, $uuid, $token, $tokenSha, $state, $attempts, $ctx);
            }
            $v = RemoteAssetMatcher::classify($rows, $tag, (int) ($saga['snipe_company_id'] ?? 0), (int) ($saga['snipe_model_id'] ?? 0), $this->serialOf($payload), $uuid, true);
            if ($v['kind'] !== RemoteAssetMatcher::ONE || $v['asset_id'] !== (int) ($saga['snipe_asset_id'] ?? 0)) {
                return $this->manualReview($uuid, $token, $tokenSha, $state, 'el activo Snipe ya no es coherente con la saga (' . $v['kind'] . ' ' . $v['detail'] . ')', 'snipe_diverged', $ctx);
            }
        }
        $o = $this->glpi->advance($uuid, $tokenSha, $payload, $corr);
        switch ($o['kind']) {
            case Si4GlpiStage::O_BRIDGED:
                $final = $this->sagas->get($uuid) ?? [];
                $glpiOutcome = (string) ($final['glpi_outcome'] ?? '');
                if (isset($this->stats['glpi_' . $glpiOutcome])) {
                    $this->stats['glpi_' . $glpiOutcome]++;
                }
                $this->log('info', $this->qr !== null ? 'unidad en BRIDGED: activo GLPI, Infocom y asset_bridge listos; sigue la etapa QR'
                    : 'unidad en BRIDGED: activo GLPI, Infocom y asset_bridge listos; estacionada (modo SI4-2, sin ack)', $ctx + [
                    'glpi_itemtype' => (string) ($final['glpi_itemtype'] ?? ''), 'glpi_items_id' => (int) ($final['glpi_items_id'] ?? 0),
                    'glpi_outcome' => $glpiOutcome, 'infocom_outcome' => (string) ($final['infocom_outcome'] ?? ''),
                    'asset_bridge_id' => (int) ($final['asset_bridge_id'] ?? 0),
                ]);
                if ($this->qr !== null) {
                    return $this->continueQr($uuid, $token, $tokenSha, $attempts, $ctx);
                }
                return self::R_BRIDGED;
            case Si4GlpiStage::O_LEASE_LOST:
                $this->log('warning', 'lease perdido o insuficiente en la etapa GLPI: el próximo dueño reanuda', $ctx);
                return self::R_LEASE_LOST;
            case Si4GlpiStage::O_BLOCKED:
                if (!$this->sagas->transition($uuid, $tokenSha, $o['state'], ['state' => SagaState::BLOCKED_CONFIG, 'resume_state' => $o['resume'],
                    'last_error' => Si4Errors::sanitize($o['why']), 'last_error_class' => $o['class']], 'blocked_config', $o['why'])) {
                    return self::R_LEASE_LOST;
                }
                $this->settleRetry($uuid, $token, $o['why'], $this->cfg->configRetrySec, $ctx);
                return self::R_BLOCKED_CONFIG;
            default: // O_MANUAL
                return $this->manualReview($uuid, $token, $tokenSha, $o['state'], $o['why'], $o['class'], $ctx);
        }
    }

    /**
     * SI4-3 (ADR-0022): BRIDGED ⇒ QR_READY ⇒ revalidación ⇒ `acknowledgeProcessed()` (ÚLTIMO efecto externo) ⇒ COMPLETED
     * por el finalizador. Desde QR_READY (ack fallido o crash antes del ack) no se rehace nada: revalida y confirma.
     *
     * @param array<string,mixed> $ctx
     */
    private function continueQr(string $uuid, string $token, string $tokenSha, int $attempts, array $ctx): string
    {
        if ($this->qr === null || $this->finalizer === null) {
            return self::R_PARKED;
        }
        $o = $this->qr->advance($uuid, $tokenSha);
        if ($o['kind'] === Si4QrStage::O_QR_READY) {
            $o = $this->qr->revalidateForAck($uuid, $tokenSha);
        }
        switch ($o['kind']) {
            case Si4QrStage::O_QR_READY:
                break;
            case Si4QrStage::O_LEASE_LOST:
                $this->log('warning', 'lease perdido o insuficiente en la etapa QR: el próximo dueño reanuda (sin ack)', $ctx);
                return self::R_LEASE_LOST;
            case Si4QrStage::O_BLOCKED:
                if (!$this->sagas->transition($uuid, $tokenSha, $o['state'], ['state' => SagaState::BLOCKED_CONFIG, 'resume_state' => $o['resume'],
                    'last_error' => Si4Errors::sanitize($o['why']), 'last_error_class' => $o['class']], 'blocked_config', $o['why'])) {
                    return self::R_LEASE_LOST;
                }
                $this->settleRetry($uuid, $token, $o['why'], $this->cfg->configRetrySec, $ctx);
                return self::R_BLOCKED_CONFIG;
            default: // O_MANUAL: sin ack
                return $this->manualReview($uuid, $token, $tokenSha, $o['state'], $o['why'], $o['class'], $ctx);
        }
        $saga = $this->sagas->get($uuid) ?? [];
        $qrOutcome = (string) ($saga['qr_outcome'] ?? '');
        $this->probe('before_ack', $uuid);
        try {
            $ack = $this->source->acknowledgeProcessed($uuid, $token);
            if (($ack['status'] ?? '') !== Si4Finalizer::OUTBOX_DONE) {
                throw new \RuntimeException('el outbox no quedó DONE (' . (string) ($ack['status'] ?? '') . ')');
            }
        } catch (\Exception $e) {
            // La saga QUEDA en QR_READY y el outbox sin DONE: el reintento sólo revalida y vuelve a confirmar.
            $this->recordError($uuid, $tokenSha, SagaState::QR_READY, 'ack', 'acknowledgeProcessed falló: ' . $e->getMessage());
            $this->log('warning', 'acknowledgeProcessed falló: la saga sigue en QR_READY', $ctx + ['error' => Si4Errors::sanitize($e->getMessage())]);
            $this->settleRetry($uuid, $token, 'ack: ' . $e->getMessage(), $this->cfg->backoffSeconds($attempts), $ctx);
            return self::R_QR_READY;
        }
        $this->probe('after_ack', $uuid);
        if (isset($this->stats['qr_' . $qrOutcome])) {
            $this->stats['qr_' . $qrOutcome]++;
        }
        if ($this->finalizer->finalizeOne($uuid) === Si4Finalizer::F_COMPLETED) {
            $this->probe('after_completed', $uuid);
            $this->log('info', 'unidad COMPLETED: código companyqr activo, etiqueta lista y outbox DONE', $ctx + [
                'qr_code_id' => (int) ($saga['qr_code_id'] ?? 0), 'qr_outcome' => $qrOutcome,
            ]);
            return self::R_COMPLETED;
        }
        // Ack aplicado pero la saga no pudo cerrarse ahora: el finalizador la cierra en la próxima corrida.
        $this->log('warning', 'ack aplicado; la saga queda en QR_READY hasta el finalizador', $ctx);
        return self::R_QR_READY;
    }

    /** @param array<string,mixed> $ctx */
    private function onReadFailure(SnipeException $e, string $uuid, string $token, string $tokenSha, string $state, int $attempts, array $ctx): string
    {
        $this->recordError($uuid, $tokenSha, $state, $e->kind, 'lectura Snipe: ' . $e->kind);
        if ($e->kind === SnipeException::AUTH) {
            $this->settleRetry($uuid, $token, 'Snipe auth (fail-closed)', $this->cfg->authRetrySec, $ctx);
            $this->abort = 'auth';
            return self::R_RETRY;
        }
        if ($e->kind === SnipeException::CIRCUIT_OPEN) {
            $this->abort = 'circuit';
        }
        $this->settleRetry($uuid, $token, 'lectura Snipe: ' . $e->kind, $this->cfg->backoffSeconds($attempts), $ctx);
        return self::R_RETRY;
    }

    /** @param array<string,mixed> $ctx */
    private function manualReview(string $uuid, string $token, string $tokenSha, string $state, string $why, string $class, array $ctx): string
    {
        if (!$this->sagas->transition($uuid, $tokenSha, $state, ['state' => SagaState::MANUAL_REVIEW,
            'last_error' => Si4Errors::sanitize($why), 'last_error_class' => substr($class, 0, 30)], 'manual_review', $why)) {
            return self::R_LEASE_LOST;
        }
        $this->settleError($uuid, $token, 'revisión manual: ' . $why, $ctx);
        return self::R_MANUAL_REVIEW;
    }

    /** Registra el último error en la saga SIN cambiar de estado (best-effort; con fencing). */
    private function recordError(string $uuid, string $tokenSha, string $state, string $class, string $why): void
    {
        $this->sagas->transition($uuid, $tokenSha, $state, ['last_error' => Si4Errors::sanitize($why), 'last_error_class' => substr($class, 0, 30)], 'error', $why);
    }

    /** @param array<string,mixed> $ctx */
    private function settleRetry(string $uuid, string $token, string $error, int $delaySec, array $ctx): void
    {
        try {
            $next = (new \DateTimeImmutable('@' . ((int) ($this->clock)() + max(1, $delaySec))));
            $this->source->markRetry($uuid, $token, Si4Errors::sanitize($error), $next);
        } catch (\RuntimeException $e) {
            $this->log('warning', 'markRetry rechazado (lease perdido)', $ctx + ['error' => Si4Errors::sanitize($e->getMessage())]);
        }
    }

    /** @param array<string,mixed> $ctx */
    private function settleError(string $uuid, string $token, string $error, array $ctx): void
    {
        try {
            $this->source->markError($uuid, $token, Si4Errors::sanitize($error));
        } catch (\RuntimeException $e) {
            $this->log('warning', 'markError rechazado (lease perdido)', $ctx + ['error' => Si4Errors::sanitize($e->getMessage())]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function serialOf(array $payload): ?string
    {
        $s = $payload['serial'] ?? null;
        return is_string($s) && $s !== '' ? $s : null;
    }

    /**
     * Campos del POST: sólo lo necesario y verificado (ADR-0020 §10: sin costo). `notes` lleva la MARCA DE PROCEDENCIA
     * `receipt_unit_uuid=<uuid>` AL FINAL: Snipe devuelve notes con Parsedown::line (safe mode), y al final ningún `_…_`
     * posterior puede formar un énfasis que la atraviese; los demás valores se reducen a `[A-Za-z0-9.:-]`.
     *
     * @param array{ok:bool, company_id:int, model_id:int, status_id:int, reason:string} $map @param array<string,mixed> $payload
     * @return array<string,scalar|null>
     */
    public static function assetFields(string $tag, array $map, array $payload, string $uuid, string $corr): array
    {
        $f = [
            'asset_tag'    => $tag,
            'model_id'     => $map['model_id'],
            'status_id'    => $map['status_id'],
            'company_id'   => $map['company_id'],
            'name'         => mb_substr((string) ($payload['description'] ?? ''), 0, 255),
            'order_number' => mb_substr((string) ($payload['request_number'] ?? ''), 0, 191),
            'notes'        => 'GLPI2 SI-4 · request=' . self::plain((string) ($payload['request_number'] ?? '')) . ' · correlation='
                . self::plain($corr) . ' · ' . RemoteAssetMatcher::marker($uuid),
        ];
        $serial = is_string($payload['serial'] ?? null) && $payload['serial'] !== '' ? $payload['serial'] : null;
        if ($serial !== null) {
            $f['serial'] = $serial;
        }
        return $f;
    }

    /** Texto seguro para notes (sin caracteres de markdown). */
    private static function plain(string $v): string
    {
        return (string) preg_replace('/[^A-Za-z0-9.:-]/', '-', mb_substr($v, 0, 64));
    }

    private function probe(string $point, string $uuid): void
    {
        if ($this->probe !== null) {
            ($this->probe)($point, $uuid);
        }
    }

    /** @param array<string,mixed> $ctx */
    private function log(string $level, string $message, array $ctx = []): void
    {
        $ctx['worker'] = $this->cfg->workerId !== '' ? $this->cfg->workerId : 'si4';
        ($this->logger)($level, $this->sanitizer->redact($message), $this->sanitizer->redactArray($ctx));
    }
}
