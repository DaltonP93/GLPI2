<?php

/**
 * Escenarios de SELFTEST de SI4-3 (ADR-0022) sobre GLPI 11.0.8 REAL: companyqr por su API pública (`CompanyQrApi` vía
 * `CoreQrGateway`), outbox REAL de Compras (`PurchasingIntegrationApi`: claim, ack, getHandoff), `DbSagaStore` con el
 * reloj de la BD y el worker cableado por el comando real (`Si4RunCommand::buildWorker`). Reusa las fixtures de SI4-1
 * (Compras + Snipe fake) y SI4-2 (modelo y mapeo GLPI).
 *
 *   [SI4Q-CONTRACT]  contratos compartidos: metadatos (sin token), estado ACTIVO de companyqr, DONE del outbox
 *   [SI4Q-UPGRADE]   0.4.0 (SI4-2 con sagas BRIDGED) → install() ×2 (0.5.0): columnas nuevas + UNIQUE(qr_code_id); sagas,
 *                    puentes y códigos companyqr intactos
 *   [SI4Q-RESUME]    una saga BRIDGED de SI4-2 continúa con el comando SI4-3 hasta COMPLETED sin volver a Snipe/GLPI
 *   [SI4Q-E2E]       outbox real ⇒ worker del comando real ⇒ Snipe ⇒ GLPI ⇒ Infocom ⇒ puente ⇒ código ⇒ etiqueta ⇒ ack ⇒
 *                    COMPLETED; PDF real con el public_code
 *   [SI4Q-EXISTING]  código ACTIVO preexistente reutilizado; con otro public_code / REVOKED / SUSPENDED ⇒ MANUAL_REVIEW sin
 *                    rotar/reactivar ni ack
 *   [SI4Q-CRASH]     8 crash points §8 + vencimiento del lease: 1 activo Snipe/GLPI, 1 Infocom, 1 puente, 1 código y el
 *                    outbox DONE una sola vez (auditoría de Compras)
 *   [SI4Q-WORKERS]   dos workers ⇒ un código; el dueño viejo no escribe
 *   [SI4Q-FENCE]     `holds()` con el reloj de la BD antes de escribir en companyqr: lease corto o re-tomado ⇒ sin código
 *   [SI4Q-ACK]       ack fallido ⇒ QR_READY y outbox sin DONE; el finalizador no cierra; el reintento confirma sin rehacer
 *   [SI4Q-FINALIZER] crash tras el ack ⇒ el finalizador (sin lease) cierra en COMPLETED; idempotente
 *   [SI4Q-FINALIZER-CURSOR] recorrido round-robin con cursor DURABLE (tabla propia) y wrap-around: sagas pendientes no
 *                    bloquean a las posteriores (lote 2, objetos reconstruidos = otro proceso CLI); propiedad con más
 *                    sagas que el lote; compare-and-set ante una carrera
 *   [SI4Q-MISMATCH]  código revocado / puente divergente entre QR_READY y el ack ⇒ MANUAL_REVIEW sin ack
 *   [SI4Q-ACL]       sin RIGHT_GENERATE / RIGHT_PRINT de companyqr ⇒ BLOCKED_CONFIG sin código (sin bypass); reanuda
 *   [SI4Q-MULTI-ENT] activo de una entidad que el usuario técnico no ve ⇒ BLOCKED_CONFIG; con acceso ⇒ código de esa entidad
 *   [SI4Q-TOKEN]     el token del QR no está en saga, bitácora, outbox ni logs; sin columnas para token/PDF
 *   [SI4Q-NO-SIDE-EFFECTS] si4_enabled = 0 por defecto, sin Acción automática, Snipe sólo hardware/statuslabels
 *
 * El vencimiento del lease tras un crash se emula con `markRetry(ahora)` por la API PÚBLICA de Compras (la fila vuelve
 * a ser reclamable igual que con el lease vencido), nunca con SQL sobre sus tablas.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Command;

use Computer;
use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Model\Si4Saga;
use GlpiPlugin\Companyintegrations\Model\Si4SagaLog;
use GlpiPlugin\Companyintegrations\Service\PluginConfig as IntegrationsConfig;
use GlpiPlugin\Companyintegrations\Si4\AssetTagDeriver;
use GlpiPlugin\Companyintegrations\Si4\CoreGlpiAssetGateway;
use GlpiPlugin\Companyintegrations\Si4\CoreQrGateway;
use GlpiPlugin\Companyintegrations\Si4\DbBridgeStore;
use GlpiPlugin\Companyintegrations\Si4\DbFinalizerCursor;
use GlpiPlugin\Companyintegrations\Si4\DbSagaStore;
use GlpiPlugin\Companyintegrations\Si4\HandoffSource;
use GlpiPlugin\Companyintegrations\Si4\PurchasingHandoffSource;
use GlpiPlugin\Companyintegrations\Si4\QrCodeRules;
use GlpiPlugin\Companyintegrations\Si4\SagaState;
use GlpiPlugin\Companyintegrations\Si4\Si4Config;
use GlpiPlugin\Companyintegrations\Si4\Si4Finalizer;
use GlpiPlugin\Companyintegrations\Si4\Si4QrStage;
use GlpiPlugin\Companyintegrations\Si4\Si4Worker;
use GlpiPlugin\Companyintegrations\Si4\SimulatedCrash;
use GlpiPlugin\Companyintegrations\Si4\WorkerSession;
use GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi;
use GlpiPlugin\Companypurchasing\Model\OutboxEntry;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companyqr\Api\CompanyQrApi;
use GlpiPlugin\Companyqr\Model\Code as QrCode;
use GlpiPlugin\Companyqr\Service\CodeManager;

trait Si4QrSelftestScenarios
{
    /** Columnas de `si4_sagas` en 0.4.0 (SI4-2): deben quedar intactas tras el upgrade. */
    private const SI4Q_SAGA_COLS_040 = [
        'id', 'receipt_unit_uuid', 'entities_id', 'state', 'snipe_asset_id', 'snipe_asset_tag', 'lease_token_sha256', 'lease_until',
        'lease_epoch', 'row_version', 'last_error', 'glpi_itemtype', 'glpi_items_id', 'glpi_entity_id', 'glpi_outcome', 'glpi_infocom_id',
        'infocom_outcome', 'asset_bridge_id', 'resume_state', 'glpi_mapping_id', 'glpi_model_id', 'glpi_mapping_hash',
    ];
    private const SI4Q_SAGA_COLS_NEW = ['qr_code_id', 'qr_public_code', 'qr_outcome', 'label_ready_at', 'completed_at'];
    private const SI4Q_POINTS = ['before_qr', 'after_qr_code', 'after_qr_persisted', 'after_label_render', 'after_qr_ready', 'before_ack', 'after_ack', 'after_completed'];

    /** Fuente de handoff de los escenarios: la API REAL de Compras envuelta para recordar tokens e inyectar fallas de ack. */
    private ?HandoffSource $si4qSrc = null;
    /** @var array<int,string> unidades de SI4-3 */
    private array $si4qUuids = [];

    private function runSi4qScenarios(): void
    {
        $this->out->writeln('== [SI4Q] SI4-3: código companyqr + etiqueta + ack + finalizador (ADR-0022) ==');
        if (!class_exists(CompanyQrApi::class) || !\Plugin::isPluginActive('companyqr')) {
            $this->check('[SI4Q-CONTRACT] companyqr activo con CompanyQrApi', false);
            return;
        }
        $this->si4qSrc = $this->si4qRecordingSource();
        try {
            $this->si4qContract();
            $this->si4qUpgrade();
            $this->si4qResume();
            $this->si4qE2e();
            $this->si4qExisting();
            $this->si4qCrash();
            $this->si4qWorkers();
            $this->si4qFence();
            $this->si4qAck();
            $this->si4qFinalizer();
            $this->si4qMismatch();
            $this->si4qAcl();
            $this->si4qMultiEntity();
            $this->si4qFinalizerCursor();
            $this->si4qFinalizerProperty();
            $this->si4qToken();
            $this->si4qNoSideEffects();
        } catch (\Throwable $e) {
            $this->check('[SI4Q-E2E] sin excepciones: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
        } finally {
            $this->si4qCleanup();
        }
    }

    // ------------------------------------------------------------------ [SI4Q-CONTRACT]

    private function si4qContract(): void
    {
        $this->check('[SI4Q-CONTRACT] metadatos de companyqr = los que acepta SI4-3 (sin token)', QrCodeRules::META_KEYS === CompanyQrApi::META_KEYS
            && !in_array('token', QrCodeRules::META_KEYS, true));
        $this->check('[SI4Q-CONTRACT] estado ACTIVO de companyqr y DONE del outbox de Compras', QrCodeRules::STATUS_ACTIVE === QrCode::STATUS_ACTIVE
            && Si4Finalizer::OUTBOX_DONE === OutboxEntry::STATUS_DONE);
        $this->check('[SI4Q-CONTRACT] el comando real incluye la etapa QR (y el modo SI4-2 no)', $this->si4qHasQr($this->si4qWorker('st-q-c'))
            && !$this->si4qHasQr($this->si4gWorker('st-q-c2')));
    }

    // ------------------------------------------------------------------ [SI4Q-UPGRADE]

    private function si4qUpgrade(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [SI4Q-UPGRADE] 0.4.0 (SI4-2 con sagas BRIDGED) → install() ×2 (0.5.0) ==');
        $this->applySession(2, [0], ['config' => ALLSTANDARDRIGHT], 1);
        if (!function_exists('plugin_companyintegrations_install')) {
            include_once dirname(__DIR__, 2) . '/hook.php';
        }
        $p = 'glpi_plugin_companyintegrations_';
        // (1) Estado 0.4.0: sin las columnas de SI4-3; con sagas BRIDGED reales de los escenarios SI4-2.
        if (isIndex("{$p}si4_sagas", 'qr_code_id')) {
            $DB->doQuery("ALTER TABLE `{$p}si4_sagas` DROP INDEX `qr_code_id`");
        }
        foreach (self::SI4Q_SAGA_COLS_NEW as $c) {
            if ($DB->fieldExists("{$p}si4_sagas", $c, false)) {
                $DB->doQuery("ALTER TABLE `{$p}si4_sagas` DROP COLUMN `{$c}`");
            }
        }
        $DB->doQuery("DROP TABLE IF EXISTS `{$p}si4_runtime`");
        $DB->clearSchemaCache();
        $bridged = countElementsInTable(Si4Saga::getTable(), ['state' => SagaState::BRIDGED]);
        $fpSagas = $this->si4gFp('si4_sagas', self::SI4Q_SAGA_COLS_040);
        $fpLog = $this->si4gFp('si4_saga_log', ['id', 'receipt_unit_uuid', 'event', 'from_state', 'to_state', 'detail']);
        $fpBridge = $this->si4gFp('asset_bridge', ['id', 'receipt_unit_uuid', 'snipe_asset_id', 'snipe_asset_tag', 'glpi_itemtype', 'glpi_items_id', 'glpi_entity_id']);
        $fpCodes = $this->si4qCodesFp();
        $conf = \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
        $ok = true;
        try {
            plugin_companyintegrations_install();
            plugin_companyintegrations_install();
        } catch (\Throwable $e) {
            $ok = false;
            $this->out->writeln('    ' . $e->getMessage());
        }
        $DB->clearSchemaCache();
        $cols = true;
        foreach (self::SI4Q_SAGA_COLS_NEW as $c) {
            $cols = $cols && $DB->fieldExists("{$p}si4_sagas", $c, false);
        }
        $this->check('[SI4Q-UPGRADE] install() ×2 sobre 0.4.0 no falla', $ok);
        $this->check('[SI4Q-UPGRADE] columnas qr_code_id / qr_public_code / qr_outcome / label_ready_at / completed_at + UNIQUE(qr_code_id)',
            $cols && isIndex("{$p}si4_sagas", 'qr_code_id'));
        $this->check('[SI4Q-UPGRADE] tabla propia si4_runtime con el cursor del finalizador (una fila, en 0)', $DB->tableExists("{$p}si4_runtime", false)
            && countElementsInTable("{$p}si4_runtime", ['name' => DbFinalizerCursor::NAME]) === 1 && (new DbFinalizerCursor())->get() === 0);
        $this->check('[SI4Q-UPGRADE] 🔒 sagas SI4-2 INTACTAS (huella de las columnas 0.4.0 + bitácora) y siguen en BRIDGED', $bridged >= 3
            && $fpSagas === $this->si4gFp('si4_sagas', self::SI4Q_SAGA_COLS_040) && $fpLog === $this->si4gFp('si4_saga_log', ['id', 'receipt_unit_uuid', 'event', 'from_state', 'to_state', 'detail'])
            && countElementsInTable(Si4Saga::getTable(), ['state' => SagaState::BRIDGED]) === $bridged);
        $this->check('[SI4Q-UPGRADE] 🔒 asset_bridge, mapeos y códigos companyqr existentes intactos', $fpBridge === $this->si4gFp('asset_bridge',
            ['id', 'receipt_unit_uuid', 'snipe_asset_id', 'snipe_asset_tag', 'glpi_itemtype', 'glpi_items_id', 'glpi_entity_id']) && $fpCodes === $this->si4qCodesFp());
        $this->check('[SI4Q-UPGRADE] configuración preservada (si4_enabled sigue en 0)', \Config::getConfigurationValues(IntegrationsConfig::CONTEXT) === $conf
            && ($conf['si4_enabled'] ?? '') === '0');
    }

    // ------------------------------------------------------------------ [SI4Q-RESUME]

    private function si4qResume(): void
    {
        $this->out->writeln('== [SI4Q-RESUME] saga BRIDGED de SI4-2 ⇒ comando SI4-3 ⇒ COMPLETED ==');
        [$u] = $this->si4Receive(1);
        $this->si4qUuids[] = $u;
        $this->si4gAsWorker([$this->si4E]);
        $m = $this->si4qWorker('st-q-si42', false)->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->si4gTrack('Computer', (int) ($s['glpi_items_id'] ?? 0));
        $this->check('[SI4Q-RESUME] modo SI4-2: BRIDGED, sin código companyqr, outbox LEASED', $m['bridged'] === 1 && ($s['state'] ?? '') === SagaState::BRIDGED
            && $this->si4qCodes('Computer', (int) $s['glpi_items_id']) === 0 && $this->si4qStatus($u) === 'LEASED');
        $this->si4qReclaimable($u);
        $posts = $this->si4Posts();
        $computers = countElementsInTable(Computer::getTable());
        $m = $this->si4qWorker('st-q-resume')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->check('[SI4Q-RESUME] el comando SI4-3 reanuda desde BRIDGED ⇒ QR_READY ⇒ ack ⇒ COMPLETED', $m['completed'] >= 1 && ($s['state'] ?? '') === SagaState::COMPLETED
            && $this->si4qStatus($u) === 'DONE' && $this->si4qDoneEvents($u) === 1);
        $this->check('[SI4Q-RESUME] 🔒 sin volver a Snipe ni crear otro activo GLPI', $this->si4Posts() === $posts && countElementsInTable(Computer::getTable()) === $computers
            && $this->si4qOnes($u));
    }

    // ------------------------------------------------------------------ [SI4Q-E2E]

    private function si4qE2e(): void
    {
        $this->out->writeln('== [SI4Q-E2E] outbox real ⇒ comando real ⇒ … ⇒ código + etiqueta ⇒ ack ⇒ COMPLETED ==');
        $uuids = $this->si4Receive(2);
        $this->si4gAsWorker([$this->si4E]);
        $m = $this->si4qWorker('st-q-A')->run();
        $this->check('[SI4Q-E2E] 2 unidades en UNA pasada: COMPLETED, códigos creados, sin abortar', $m['claimed'] === 2 && $m['completed'] === 2
            && $m['qr_created'] === 2 && $m['aborted'] === null);
        $all = true;
        $label = true;
        $api = new CompanyQrApi();
        foreach ($uuids as $u) {
            $this->si4qUuids[] = $u;
            $s = (new DbSagaStore())->get($u) ?? [];
            $id = (int) ($s['glpi_items_id'] ?? 0);
            $this->si4gTrack('Computer', $id);
            $tag = AssetTagDeriver::tagFor('ST4-', $u);
            $code = new QrCode();
            $okCode = $code->getFromDB((int) ($s['qr_code_id'] ?? 0));
            $pc = new Computer();
            $pc->getFromDB($id);
            $all = $all && ($s['state'] ?? '') === SagaState::COMPLETED && $okCode && $code->isActive() && (string) $code->fields['itemtype'] === 'Computer'
                && (int) $code->fields['items_id'] === $id && (int) $code->fields['entities_id'] === $this->si4E && (string) $code->fields['public_code'] === $tag
                && (string) $pc->fields['otherserial'] === $tag && ($s['qr_public_code'] ?? '') === $tag && ($s['qr_outcome'] ?? '') === SagaState::OUTCOME_CREATED
                && ($s['label_ready_at'] ?? null) !== null && ($s['completed_at'] ?? null) !== null && $this->si4qStatus($u) === 'DONE'
                && $this->si4qDoneEvents($u) === 1 && $this->si4qOnes($u);
            $pdf = $okCode ? $api->renderLabelPdf((int) $code->getID()) : '';
            $label = $label && QrCodeRules::isPdf($pdf) && str_contains($this->si4qPdfText($pdf), $tag) && !str_contains($pdf, (string) $code->fields['token'])
                && !str_contains($this->si4qPdfText($pdf), (string) $pc->fields['serial']);
        }
        $this->check('[SI4Q-E2E] código companyqr ACTIVO del activo (entidad de la unidad), public_code = número de inventario = tag de Snipe', $all);
        $this->check('[SI4Q-E2E] outbox DONE exactamente una vez (auditoría de Compras), saga COMPLETED con label_ready_at y completed_at', $all);
        $this->check('[SI4Q-E2E] etiqueta real con el renderer de companyqr: PDF válido con el public_code, sin serial ni token', $label);
        $m = $this->si4qWorker('st-q-A')->run();
        $this->check('[SI4Q-E2E] re-ejecutar el comando: nada que reclamar ni cerrar', $m['claimed'] === 0 && $m['finalized'] === 0);
    }

    // ------------------------------------------------------------------ [SI4Q-EXISTING]

    private function si4qExisting(): void
    {
        $this->out->writeln('== [SI4Q-EXISTING] código preexistente: ACTIVO ⇒ se reutiliza; REVOKED/SUSPENDED ⇒ MANUAL_REVIEW ==');
        [$u, $id] = $this->si4qBridgedUnit();
        $human = $this->si4qHumanCode($id);
        $this->si4qReclaimable($u);
        $this->si4qWorker('st-q-ex')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->check('[SI4Q-EXISTING] código ACTIVO generado antes por un humano ⇒ se REUTILIZA (qr_outcome existing), uno solo', ($s['state'] ?? '') === SagaState::COMPLETED
            && (int) ($s['qr_code_id'] ?? 0) === (int) $human->getID() && ($s['qr_outcome'] ?? '') === SagaState::OUTCOME_EXISTING && $this->si4qCodes('Computer', $id) === 1);
        // Código creado ANTES de que SI4-2 reclamara el número de inventario (activo del agente sin otherserial): companyqr
        // generó su propio public_code ⇒ la etiqueta no mostraría el número de inventario de la unidad.
        [$u, $id] = $this->si4qBridgedUnit();
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $pc = new Computer();
        $pc->getFromDB($id);
        $tag = (string) $pc->fields['otherserial'];
        $pc->update(['id' => $id, 'otherserial' => '']);
        $pc->getFromDB($id);
        $early = (new CodeManager())->getOrCreateForItem($pc);
        $pc->update(['id' => $id, 'otherserial' => $tag]);
        $this->si4qReclaimable($u);
        $m = $this->si4qWorker('st-q-ex')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->check('[SI4Q-EXISTING] 🔒 código con otro public_code (' . (string) $early->fields['public_code'] . ') ⇒ MANUAL_REVIEW (qr_public_code) sin ack', $m['manual_review'] === 1
            && (string) $early->fields['public_code'] !== $tag && ($s['last_error_class'] ?? '') === 'qr_public_code' && $this->si4qStatus($u) === 'ERROR'
            && $this->si4qDoneEvents($u) === 0 && $this->si4qCodes('Computer', $id) === 1);
        foreach ([QrCode::STATUS_REVOKED => 'qr_code_revoked', QrCode::STATUS_SUSPENDED => 'qr_code_suspended'] as $status => $class) {
            [$u, $id] = $this->si4qBridgedUnit();
            $c = $this->si4qHumanCode($id);
            $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
            (new CodeManager())->setStatus($c, $status);
            $c->getFromDB($c->getID());
            $token = (string) $c->fields['token'];
            $this->si4qReclaimable($u);
            $m = $this->si4qWorker('st-q-ex')->run();
            $s = (new DbSagaStore())->get($u) ?? [];
            $after = new QrCode();
            $after->getFromDB($c->getID());
            $this->check("[SI4Q-EXISTING] 🔒 código {$status} ⇒ MANUAL_REVIEW sin ack (outbox ERROR); NO se rota ni se reactiva", $m['manual_review'] === 1
                && ($s['last_error_class'] ?? '') === $class && $this->si4qStatus($u) === 'ERROR' && $this->si4qDoneEvents($u) === 0
                && (string) $after->fields['status'] === $status && (string) $after->fields['token'] === $token && $this->si4qCodes('Computer', $id) === 1);
        }
    }

    // ------------------------------------------------------------------ [SI4Q-CRASH]

    private function si4qCrash(): void
    {
        $this->out->writeln('== [SI4Q-CRASH] crash points §8: 1 activo Snipe/GLPI, 1 Infocom, 1 puente, 1 código; DONE una sola vez ==');
        foreach (self::SI4Q_POINTS as $pt) {
            [$u] = $this->si4Receive(1);
            $this->si4qUuids[] = $u;
            $this->si4gAsWorker([$this->si4E]);
            $crashed = false;
            try {
                $this->si4qWorker('st-q-crash', true, $this->si4qCrashAt($pt))->run();
            } catch (SimulatedCrash) {
                $crashed = true;
            }
            $s1 = (new DbSagaStore())->get($u) ?? [];
            $this->si4gTrack('Computer', (int) ($s1['glpi_items_id'] ?? 0));
            $acked = in_array($pt, ['after_ack', 'after_completed'], true);
            $first = (int) ($s1['glpi_items_id'] ?? 0) > 0 ? $this->si4qCodeId('Computer', (int) $s1['glpi_items_id']) : 0;
            $before = $crashed && ($acked ? $this->si4qStatus($u) === 'DONE' : $this->si4qStatus($u) === 'LEASED' && $this->si4qDoneEvents($u) === 0)
                && (($s1['state'] ?? '') === SagaState::COMPLETED) === ($pt === 'after_completed');
            if (!$acked) {
                $this->si4qReclaimable($u);
            }
            $m = $this->si4qWorker('st-q-crash2')->run();
            $s = (new DbSagaStore())->get($u) ?? [];
            $sameCode = $first === 0 || (int) ($s['qr_code_id'] ?? 0) === $first;
            $finalized = $pt !== 'after_ack' || $m['finalized'] >= 1;
            $this->check("[SI4Q-CRASH] «{$pt}» ⇒ " . ($acked ? 'outbox ya DONE' : 'sin DONE antes del ack') . '; el siguiente worker converge a COMPLETED, todo en 1',
                $before && ($s['state'] ?? '') === SagaState::COMPLETED && $this->si4qStatus($u) === 'DONE' && $this->si4qDoneEvents($u) === 1
                && $this->si4qOnes($u) && $sameCode && $finalized);
        }
    }

    // ------------------------------------------------------------------ [SI4Q-WORKERS]

    private function si4qWorkers(): void
    {
        $this->out->writeln('== [SI4Q-WORKERS] dos workers ⇒ un solo código; el dueño viejo no escribe ==');
        [$u] = $this->si4Receive(1);
        $this->si4qUuids[] = $u;
        $this->si4gAsWorker([$this->si4E]);
        try {
            $this->si4qWorker('st-q-w1', true, $this->si4qCrashAt('after_qr_code'))->run();
        } catch (SimulatedCrash) {
        }
        $sA = (new DbSagaStore())->get($u) ?? [];
        $id = (int) ($sA['glpi_items_id'] ?? 0);
        $this->si4gTrack('Computer', $id);
        $first = $this->si4qCodeId('Computer', $id);
        $this->check('[SI4Q-WORKERS] A muere tras crear el código y ANTES de registrarlo (la saga no lo tiene)', $first > 0 && ($sA['qr_code_id'] ?? null) === null);
        $zombie = (string) $sA['lease_token_sha256'];
        $this->si4qReclaimable($u);
        $this->si4qWorker('st-q-w2')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->check('[SI4Q-WORKERS] 🔒 B encuentra el MISMO código (get-or-create por activo): uno solo, COMPLETED', (int) ($s['qr_code_id'] ?? 0) === $first
            && $this->si4qCodes('Computer', $id) === 1 && ($s['state'] ?? '') === SagaState::COMPLETED && ($s['qr_outcome'] ?? '') === SagaState::OUTCOME_EXISTING);
        $fp = $this->si4gFp('si4_sagas', ['state', 'qr_code_id', 'row_version']);
        $stale = $this->si4qStage()->advance($u, $zombie);
        $this->check('[SI4Q-WORKERS] 🔒 el dueño viejo (A) no escribe ni crea otro código', $stale['kind'] === Si4QrStage::O_LEASE_LOST
            && $fp === $this->si4gFp('si4_sagas', ['state', 'qr_code_id', 'row_version']) && $this->si4qCodes('Computer', $id) === 1);
    }

    // ------------------------------------------------------------------ [SI4Q-FENCE]

    private function si4qFence(): void
    {
        $this->out->writeln('== [SI4Q-FENCE] fencing con el reloj de la BD antes de escribir en companyqr ==');
        [$u, $id] = $this->si4qBridgedUnit();
        $s = (new DbSagaStore())->get($u) ?? [];
        $old = (string) $s['lease_token_sha256'];
        $short = $this->si4gTakeover($u, (int) $s['lease_epoch'] + 1, 10);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4qStage()->advance($u, $short);
        $this->check('[SI4Q-FENCE] 🔒 lease de 10 s < presupuesto de ' . Si4QrStage::QR_WRITE_BUDGET_SEC . ' s ⇒ LEASE_LOST sin crear código', $o['kind'] === Si4QrStage::O_LEASE_LOST
            && $this->si4qCodes('Computer', $id) === 0);
        $o = $this->si4qStage()->advance($u, $old);
        $this->check('[SI4Q-FENCE] 🔒 dueño viejo (lease re-tomado) ⇒ LEASE_LOST sin crear código', $o['kind'] === Si4QrStage::O_LEASE_LOST && $this->si4qCodes('Computer', $id) === 0);
        $ok = $this->si4gTakeover($u, (int) $s['lease_epoch'] + 2);
        $o = $this->si4qStage()->advance($u, $ok);
        $this->check('[SI4Q-FENCE] dueño vigente con lease suficiente ⇒ QR_READY con 1 código', $o['kind'] === Si4QrStage::O_QR_READY && $this->si4qCodes('Computer', $id) === 1);
    }

    // ------------------------------------------------------------------ [SI4Q-ACK]

    private function si4qAck(): void
    {
        $this->out->writeln('== [SI4Q-ACK] ack fallido ⇒ QR_READY, outbox sin DONE; el reintento confirma sin rehacer nada ==');
        [$u] = $this->si4Receive(1);
        $this->si4qUuids[] = $u;
        $this->si4gAsWorker([$this->si4E]);
        $this->si4qSrc->failAcks = 1;
        $m = $this->si4qWorker('st-q-ack')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $id = (int) ($s['glpi_items_id'] ?? 0);
        $this->si4gTrack('Computer', $id);
        $this->check('[SI4Q-ACK] ack fallido ⇒ saga QR_READY (last_error_class ack), outbox RETRY (no DONE)', $m['qr_ready'] === 1 && ($s['state'] ?? '') === SagaState::QR_READY
            && ($s['last_error_class'] ?? '') === 'ack' && $this->si4qStatus($u) === 'RETRY' && $this->si4qDoneEvents($u) === 0);
        $f = (new Si4Finalizer(new DbSagaStore(), new PurchasingHandoffSource(), new DbFinalizerCursor()))->finalizeOne($u);
        $this->check('[SI4Q-ACK] 🔒 finalizador con el outbox ≠ DONE ⇒ NO marca COMPLETED', $f === Si4Finalizer::F_PENDING
            && (((new DbSagaStore())->get($u) ?? [])['state'] ?? '') === SagaState::QR_READY);
        $posts = $this->si4Posts();
        $computers = countElementsInTable(Computer::getTable());
        $code = (int) ($s['qr_code_id'] ?? 0);
        $logBefore = countElementsInTable(Si4SagaLog::getTable(), ['receipt_unit_uuid' => $u, 'event' => ['qr_code_linked', 'glpi_resolved', 'infocom_ready', 'bridged']]);
        sleep(2); // backoff del outbox (si4_retry_base_seconds = 1)
        $m = $this->si4qWorker('st-q-ack2')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->check('[SI4Q-ACK] reintento: revalida y confirma ⇒ COMPLETED, DONE una vez', $m['completed'] === 1 && ($s['state'] ?? '') === SagaState::COMPLETED
            && $this->si4qStatus($u) === 'DONE' && $this->si4qDoneEvents($u) === 1);
        $this->check('[SI4Q-ACK] 🔒 sin rehacer pasos anteriores (ni Snipe, ni activo, ni código, ni bitácora de etapas previas)', $this->si4Posts() === $posts
            && countElementsInTable(Computer::getTable()) === $computers && (int) $s['qr_code_id'] === $code && $this->si4qOnes($u)
            && countElementsInTable(Si4SagaLog::getTable(), ['receipt_unit_uuid' => $u, 'event' => ['qr_code_linked', 'glpi_resolved', 'infocom_ready', 'bridged']]) === $logBefore);
    }

    // ------------------------------------------------------------------ [SI4Q-FINALIZER]

    private function si4qFinalizer(): void
    {
        $this->out->writeln('== [SI4Q-FINALIZER] crash tras el ack ⇒ el finalizador cierra en COMPLETED (sin lease) ==');
        [$u] = $this->si4Receive(1);
        $this->si4qUuids[] = $u;
        $this->si4gAsWorker([$this->si4E]);
        try {
            $this->si4qWorker('st-q-fin', true, $this->si4qCrashAt('after_ack'))->run();
        } catch (SimulatedCrash) {
        }
        $store = new DbSagaStore();
        $s = $store->get($u) ?? [];
        $this->si4gTrack('Computer', (int) ($s['glpi_items_id'] ?? 0));
        $this->check('[SI4Q-FINALIZER] tras el crash: saga QR_READY con el outbox DONE', ($s['state'] ?? '') === SagaState::QR_READY && $this->si4qStatus($u) === 'DONE');
        $this->check('[SI4Q-FINALIZER] 🔒 una transición común NO puede escribir COMPLETED (sólo complete())', $this->si4Throws(fn () => $store->transition($u,
            (string) $s['lease_token_sha256'], SagaState::QR_READY, ['state' => SagaState::COMPLETED], 'x')));
        $completed = 0;
        for ($i = 0; $i < 10 && ($store->get($u)['state'] ?? '') !== SagaState::COMPLETED; $i++) {
            $completed += (new Si4Finalizer($store, new PurchasingHandoffSource(), new DbFinalizerCursor()))->run()['completed'];
        }
        $s = $store->get($u) ?? [];
        $this->check('[SI4Q-FINALIZER] finalizador (recorrido con cursor durable) ⇒ COMPLETED con completed_at (reloj de la BD), sin depender del lease viejo',
            $completed >= 1 && ($s['state'] ?? '') === SagaState::COMPLETED && ($s['completed_at'] ?? null) !== null && $this->si4qDoneEvents($u) === 1);
        $this->check('[SI4Q-FINALIZER] idempotente: complete() repetido = false; finalizeOne() sobre la saga cerrada no la toca', !$store->complete($u, 'x')
            && (new Si4Finalizer($store, new PurchasingHandoffSource(), new DbFinalizerCursor()))->finalizeOne($u) === Si4Finalizer::F_COMPLETED
            && $this->si4qDoneEvents($u) === 1);
    }

    // ------------------------------------------------------------------ [SI4Q-FINALIZER-CURSOR]

    /**
     * Unidades REALES en QR_READY con el outbox LEASED (crash justo después de QR_READY). Se crean TODAS antes de tocar
     * su outbox, así ninguna corrida del worker las confirma ni las cierra por su cuenta.
     *
     * @return array<int,string>
     */
    private function si4qQrReadyUnits(int $n, string $tag): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            [$u] = $this->si4Receive(1);
            $this->si4qUuids[] = $u;
            $this->si4gAsWorker([$this->si4E]);
            try {
                $this->si4qWorker('st-q-' . $tag, true, $this->si4qCrashAt('after_qr_ready'))->run();
            } catch (SimulatedCrash) {
            }
            $this->si4gTrack('Computer', (int) (((new DbSagaStore())->get($u) ?? [])['glpi_items_id'] ?? 0));
            $out[] = $u;
        }
        return $out;
    }

    /** Lleva el outbox de una unidad QR_READY a DONE (ack aplicado + crash antes de COMPLETED) o a RETRY, por la API pública. */
    private function si4qSetOutbox(string $u, string $to, string $retryAt = '+1 hour'): void
    {
        $this->si4gAsWorker([$this->si4E]);
        $t = (string) ($this->si4qSrc->tokens[$u] ?? '');
        if ($to === 'DONE') {
            $this->si4qSrc->acknowledgeProcessed($u, $t);
        } elseif ($to === 'RETRY') {
            $this->si4qSrc->markRetry($u, $t, 'selftest: pendiente', new \DateTimeImmutable($retryAt));
        }
    }

    /** Un finalizador NUEVO (objetos nuevos = otro proceso CLI); el cursor sólo vive en la tabla propia. */
    private function si4qNewFinalizer(int $batch): Si4Finalizer
    {
        return new Si4Finalizer(new DbSagaStore(), new PurchasingHandoffSource(), new DbFinalizerCursor(), null, $batch);
    }

    private function si4qSagaId(string $u): int
    {
        return (int) (((new DbSagaStore())->get($u) ?? [])['id'] ?? 0);
    }

    private function si4qSagaState(string $u): string
    {
        return (string) (((new DbSagaStore())->get($u) ?? [])['state'] ?? '');
    }

    private function si4qFinalizerCursor(): void
    {
        $this->out->writeln('== [SI4Q-FINALIZER-CURSOR] round-robin con cursor durable y wrap-around (lote 2) ==');
        [$u1, $u2, $u3, $u4, $u5] = $this->si4qQrReadyUnits(5, 'cur');
        $this->si4qSetOutbox($u1, 'RETRY', '-5 seconds'); // reclamable más tarde (para pasarla a DONE)
        $this->si4qSetOutbox($u3, 'DONE');
        $this->si4qSetOutbox($u4, 'DONE');
        $this->check('[SI4Q-FINALIZER-CURSOR] fixture: 5 sagas QR_READY consecutivas; outbox RETRY, LEASED, DONE, DONE, LEASED',
            array_map(fn (string $u): string => $this->si4qSagaState($u), [$u1, $u2, $u3, $u4, $u5]) === array_fill(0, 5, SagaState::QR_READY)
            && array_map(fn (string $u): string => $this->si4qStatus($u), [$u1, $u2, $u3, $u4, $u5]) === ['RETRY', 'LEASED', 'DONE', 'DONE', 'LEASED']
            && $this->si4qSagaId($u5) - $this->si4qSagaId($u1) === 4);
        // El recorrido arranca justo antes de la saga 1 (las sagas QR_READY de escenarios anteriores quedan antes).
        $c = new DbFinalizerCursor();
        $this->check('[SI4Q-FINALIZER-CURSOR] cursor posicionado antes de la saga 1 (compare-and-set)', $c->advance($c->get(), $this->si4qSagaId($u1) - 1)
            && (new DbFinalizerCursor())->get() === $this->si4qSagaId($u1) - 1);
        $r1 = $this->si4qNewFinalizer(2)->run();
        $this->check('[SI4Q-FINALIZER-CURSOR] corrida 1: inspecciona 1 y 2, completa 0; el cursor durable queda en la saga 2', $r1['scanned'] === 2
            && $r1['completed'] === 0 && (new DbFinalizerCursor())->get() === $this->si4qSagaId($u2));
        $r2 = $this->si4qNewFinalizer(2)->run();
        $this->check('[SI4Q-FINALIZER-CURSOR] 🔒 corrida 2 (finalizador RECONSTRUIDO): llega a 3 y 4 ⇒ ambas COMPLETED', $r2['scanned'] === 2 && $r2['completed'] === 2
            && $this->si4qSagaState($u3) === SagaState::COMPLETED && $this->si4qSagaState($u4) === SagaState::COMPLETED
            && (new DbFinalizerCursor())->get() === $this->si4qSagaId($u4) && $this->si4qDoneEvents($u3) === 1 && $this->si4qDoneEvents($u4) === 1);
        $r3 = $this->si4qNewFinalizer(2)->run();
        $this->check('[SI4Q-FINALIZER-CURSOR] corrida 3: alcanza el fin (saga 5) ⇒ wrap-around (cursor = 0)', $r3['scanned'] === 1 && $r3['completed'] === 0
            && $r3['cursor_to'] === 0 && (new DbFinalizerCursor())->get() === 0);
        // La saga 1 pasa a outbox DONE (re-tomada y confirmada por la API pública).
        $this->si4gAsWorker([$this->si4E]);
        $claimed = $this->si4qSrc->claimPending('st-q-cur-claim', 100, 900);
        $this->si4qSrc->acknowledgeProcessed($u1, (string) ($this->si4qSrc->tokens[$u1] ?? ''));
        $runs = 0;
        while ($this->si4qSagaState($u1) !== SagaState::COMPLETED && $runs < 15) {
            $this->si4qNewFinalizer(2)->run();
            $runs++;
        }
        $this->check('[SI4Q-FINALIZER-CURSOR] 🔒 la saga 1 pasa a DONE ⇒ una ronda siguiente la completa (' . $runs . ' corridas; nunca abandonada)',
            in_array($u1, array_column($claimed, 'receipt_unit_uuid'), true) && $this->si4qSagaState($u1) === SagaState::COMPLETED && $this->si4qDoneEvents($u1) === 1);
        $this->check('[SI4Q-FINALIZER-CURSOR] 🔒 nunca COMPLETED con el outbox ≠ DONE (2 y 5 siguen QR_READY)', $this->si4qSagaState($u2) === SagaState::QR_READY
            && $this->si4qSagaState($u5) === SagaState::QR_READY && $this->si4qStatus($u2) === 'LEASED' && $this->si4qStatus($u5) === 'LEASED');
        // Carrera: un avance obsoleto no pisa al concurrente (el cursor no retrocede ni se corrompe).
        $v = (new DbFinalizerCursor())->get();
        $a = new DbFinalizerCursor();
        $b = new DbFinalizerCursor();
        $okA = $a->advance($v, $v + 7);
        $okB = $b->advance($v, $v + 2);
        $this->check('[SI4Q-FINALIZER-CURSOR] 🔒 compare-and-set: el avance obsoleto falla y el cursor no retrocede', $okA && !$okB && (new DbFinalizerCursor())->get() === $v + 7);
        $a->advance($v + 7, 0);
    }

    private function si4qFinalizerProperty(): void
    {
        $this->out->writeln('== [SI4Q-FINALIZER-CURSOR] propiedad: más sagas que el lote; las DONE al final siempre se completan ==');
        $units = $this->si4qQrReadyUnits(10, 'prop');
        $pending = array_slice($units, 0, 6);
        $done = array_slice($units, 6);
        foreach ($pending as $i => $u) {
            if ($i % 2 === 0) {
                $this->si4qSetOutbox($u, 'RETRY');
            }
        }
        foreach ($done as $u) {
            $this->si4qSetOutbox($u, 'DONE');
        }
        $total = countElementsInTable(Si4Saga::getTable(), ['state' => SagaState::QR_READY]);
        $limit = (int) ceil($total / 2) + 2; // una ronda completa (lote 2) + holgura por el punto de partida del cursor
        $runs = 0;
        $all = fn (): bool => array_filter($done, fn (string $u): bool => $this->si4qSagaState($u) !== SagaState::COMPLETED) === [];
        while (!$all() && $runs < $limit) {
            $this->si4qNewFinalizer(2)->run();
            $runs++;
        }
        $this->check('[SI4Q-FINALIZER-CURSOR] 🔒 ' . $total . ' sagas QR_READY, lote 2: las 4 DONE (al final) ⇒ COMPLETED en ' . $runs . ' corridas (≤ ' . $limit . '), DONE una vez',
            $all() && array_sum(array_map(fn (string $u): int => $this->si4qDoneEvents($u), $done)) === 4);
        $this->check('[SI4Q-FINALIZER-CURSOR] 🔒 las 6 pendientes (primeras) siguen QR_READY: no bloquearon ni se completaron', array_filter($pending,
            fn (string $u): bool => $this->si4qSagaState($u) !== SagaState::QR_READY) === []);
    }

    // ------------------------------------------------------------------ [SI4Q-MISMATCH]

    private function si4qMismatch(): void
    {
        $this->out->writeln('== [SI4Q-MISMATCH] divergencia entre QR_READY y el ack ⇒ MANUAL_REVIEW sin ack ==');
        $cases = [
            'código revocado' => ['qr_code_revoked', function (array $s): void {
                $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
                $c = new QrCode();
                $c->getFromDB((int) $s['qr_code_id']);
                (new CodeManager())->revoke($c, 'selftest si4q');
            }],
            'puente reasignado a otro activo' => ['bridge_diverged', function (array $s): void {
                $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
                $other = (int) (new Computer())->add(['name' => 'si4q-other-' . $this->suffix, 'entities_id' => $this->si4E]);
                $this->si4gTrack('Computer', $other);
                (new AssetBridge())->update(['id' => (int) $s['asset_bridge_id'], 'glpi_items_id' => $other]);
            }],
        ];
        foreach ($cases as $label => [$class, $mutate]) {
            [$u] = $this->si4Receive(1);
            $this->si4qUuids[] = $u;
            $this->si4gAsWorker([$this->si4E]);
            try {
                $this->si4qWorker('st-q-mm', true, $this->si4qCrashAt('after_qr_ready'))->run();
            } catch (SimulatedCrash) {
            }
            $s = (new DbSagaStore())->get($u) ?? [];
            $this->si4gTrack('Computer', (int) ($s['glpi_items_id'] ?? 0));
            $mutate($s);
            $this->si4gAsWorker([$this->si4E]);
            $this->si4qReclaimable($u);
            $m = $this->si4qWorker('st-q-mm2')->run();
            $s = (new DbSagaStore())->get($u) ?? [];
            $this->check("[SI4Q-MISMATCH] 🔒 {$label} ⇒ MANUAL_REVIEW ({$class}), sin ack (outbox ERROR, ningún DONE)", $m['manual_review'] === 1
                && ($s['state'] ?? '') === SagaState::MANUAL_REVIEW && ($s['last_error_class'] ?? '') === $class && $this->si4qStatus($u) === 'ERROR'
                && $this->si4qDoneEvents($u) === 0);
        }
    }

    // ------------------------------------------------------------------ [SI4Q-ACL]

    private function si4qAcl(): void
    {
        $this->out->writeln('== [SI4Q-ACL] derechos de companyqr del usuario técnico (sin bypass) ==');
        foreach (['RIGHT_GENERATE' => QrCode::RIGHT_PRINT, 'RIGHT_PRINT' => QrCode::RIGHT_GENERATE] as $missing => $bits) {
            [$u, $id] = $this->si4qBridgedUnit();
            $this->si4qReclaimable($u);
            $this->si4gAsWorker([$this->si4E], [QrCode::$rightname => $bits]);
            $denied = $this->si4Throws(fn () => WorkerSession::assertRights());
            $m = $this->si4qWorker('st-q-acl')->run();
            $s = (new DbSagaStore())->get($u) ?? [];
            $this->check("[SI4Q-ACL] 🔒 sin {$missing}: la sesión del worker se rechaza ANTES de reclamar (WorkerSession)", $denied);
            $this->check("[SI4Q-ACL] 🔒 sin {$missing}: la etapa ⇒ BLOCKED_CONFIG (resume BRIDGED), sin código, sin ack, outbox RETRY", $m['blocked_config'] === 1
                && ($s['state'] ?? '') === SagaState::BLOCKED_CONFIG && ($s['resume_state'] ?? '') === SagaState::BRIDGED && ($s['last_error_class'] ?? '') === 'qr_acl'
                && $this->si4qCodes('Computer', $id) === 0 && $this->si4qStatus($u) === 'RETRY' && $this->si4qDoneEvents($u) === 0);
            $h = $this->si4gTakeover($u, (int) $s['lease_epoch'] + 1);
            $this->si4gAsWorker([$this->si4E]);
            $o = $this->si4qStage()->advance($u, $h);
            $this->check("[SI4Q-ACL] otorgado {$missing} ⇒ reanuda desde BRIDGED hasta QR_READY con 1 código", $o['kind'] === Si4QrStage::O_QR_READY
                && $this->si4qCodes('Computer', $id) === 1);
        }
    }

    // ------------------------------------------------------------------ [SI4Q-MULTI-ENT]

    private function si4qMultiEntity(): void
    {
        $this->out->writeln('== [SI4Q-MULTI-ENT] el código queda en la entidad de la unidad; ACL de entidad del usuario técnico ==');
        [$u, $h, $payload] = $this->si4gSynthetic($this->si4E2, 'SI4Q-ME-' . $this->suffix, $this->si4gCategory);
        $this->si4gAsWorker([$this->si4E, $this->si4E2]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-q-me');
        $s = (new DbSagaStore())->get($u) ?? [];
        $id = (int) ($s['glpi_items_id'] ?? 0);
        $this->si4gTrack('Computer', $id);
        $this->si4gAsWorker([$this->si4E]);
        $q = $this->si4qStage()->advance($u, $h);
        $this->check('[SI4Q-MULTI-ENT] 🔒 el usuario técnico no ve la entidad del activo ⇒ BLOCKED_CONFIG (qr_acl), sin código', $o['kind'] === 'bridged'
            && $q['kind'] === Si4QrStage::O_BLOCKED && $q['class'] === 'qr_acl' && $this->si4qCodes('Computer', $id) === 0);
        $this->si4gAsWorker([$this->si4E, $this->si4E2]);
        $q = $this->si4qStage()->advance($u, $h);
        $code = new QrCode();
        $code->getFromDB((int) (((new DbSagaStore())->get($u) ?? [])['qr_code_id'] ?? 0));
        $this->check('[SI4Q-MULTI-ENT] con acceso ⇒ QR_READY y el código en la entidad de la unidad (E2)', $q['kind'] === Si4QrStage::O_QR_READY
            && (int) ($code->fields['entities_id'] ?? -1) === $this->si4E2);
    }

    // ------------------------------------------------------------------ [SI4Q-TOKEN]

    private function si4qToken(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [SI4Q-TOKEN] el token del QR nunca sale de companyqr ==');
        $tokens = [];
        foreach ($DB->request(['SELECT' => ['qr_code_id'], 'FROM' => Si4Saga::getTable(), 'WHERE' => ['NOT' => ['qr_code_id' => null]]]) as $r) {
            $c = new QrCode();
            if ($c->getFromDB((int) $r['qr_code_id'])) {
                $tokens[] = (string) $c->fields['token'];
            }
        }
        $blob = '';
        foreach ([Si4Saga::getTable(), Si4SagaLog::getTable(), AssetBridge::getTable()] as $t) {
            foreach ($DB->request(['FROM' => $t]) as $r) {
                $blob .= json_encode($r);
            }
        }
        $api = new PurchasingIntegrationApi();
        $this->si4gAsWorker([$this->si4E]);
        foreach ($this->si4qUuids as $u) {
            $blob .= json_encode($api->getHandoff($u));
        }
        $blob .= implode("\n", $this->si4Logs);
        $leak = array_filter($tokens, static fn (string $t): bool => $t !== '' && str_contains($blob, $t));
        $this->check('[SI4Q-TOKEN] 🔒 ningún token de código (' . count($tokens) . ') en saga, bitácora, puente, outbox ni logs', count($tokens) >= 10 && $leak === []);
        $cols = array_keys($DB->listFields(Si4Saga::getTable()));
        $this->check('[SI4Q-TOKEN] 🔒 la saga no tiene columnas para el token del QR ni para el PDF', preg_grep('/token|pdf|blob/i', array_diff($cols, ['lease_token_sha256'])) === []);
    }

    // ------------------------------------------------------------------ [SI4Q-NO-SIDE-EFFECTS]

    private function si4qNoSideEffects(): void
    {
        $this->out->writeln('== [SI4Q-NO-SIDE-EFFECTS] worker deshabilitado por defecto, sin Acción automática ==');
        $this->check('[SI4Q-NO-SIDE-EFFECTS] si4_enabled = 0 por defecto', (IntegrationsConfig::DEFAULTS['si4_enabled'] ?? '') === '0');
        $this->check('[SI4Q-NO-SIDE-EFFECTS] 🔒 sin Acción automática (CronTask) para SI-4', count((new \CronTask())->find(['itemtype' => ['LIKE', '%Companyintegrations%']])) === 0);
        $this->check('[SI4Q-NO-SIDE-EFFECTS] Snipe sólo recibió GET/POST de hardware y statuslabels', array_filter($this->si4Snipe->requests,
            static fn (array $r): bool => !preg_match('#/api/v1/(hardware|statuslabels)#', (string) parse_url($r['url'], PHP_URL_PATH))) === []);
    }

    // ------------------------------------------------------------------ helpers

    /** Worker cableado por el comando REAL (`buildWorker`), con la fuente que recuerda tokens y una sonda opcional. */
    private function si4qWorker(string $workerId, bool $withQr = true, ?callable $probe = null): Si4Worker
    {
        $cfg = Si4Config::fromArray([
            'si4_enabled' => '1', 'si4_asset_tag_prefix' => 'ST4-', 'si4_snipe_status_id' => '5', 'si4_lease_seconds' => '900',
            'si4_max_units_per_run' => '50', 'si4_retry_base_seconds' => '1', 'si4_retry_max_seconds' => '3600',
            'si4_config_retry_seconds' => '3600', 'si4_auth_retry_seconds' => '900', 'si4_uncertain_cooldown_seconds' => '300',
            'si4_worker_id' => $workerId, 'si4_glpi_infocom_currency' => 'PYG',
        ]);
        $logs = &$this->si4Logs;
        $logger = static function (string $l, string $m, array $c) use (&$logs): void {
            $logs[] = $l . ' ' . $m . ' ' . json_encode($c);
        };
        return Si4RunCommand::buildWorker($cfg, $this->si4Writer($logger), 5000, 1, $logger, $withQr, $this->si4qSrc, $probe);
    }

    private function si4qStage(): Si4QrStage
    {
        return new Si4QrStage(new DbSagaStore(), new DbBridgeStore(), new CoreGlpiAssetGateway(), new CoreQrGateway());
    }

    private function si4qHasQr(Si4Worker $w): bool
    {
        $p = new \ReflectionProperty(Si4Worker::class, 'qr');
        return $p->getValue($w) instanceof Si4QrStage;
    }

    /** `HandoffSource` REAL de Compras que recuerda el último token por unidad y puede hacer fallar acks. */
    private function si4qRecordingSource(): HandoffSource
    {
        return new class (new PurchasingHandoffSource()) implements HandoffSource {
            /** @var array<string,string> */
            public array $tokens = [];
            public int $failAcks = 0;

            public function __construct(private HandoffSource $inner)
            {
            }

            public function claimPending(string $workerId, int $limit, int $leaseSeconds): array
            {
                $rows = $this->inner->claimPending($workerId, $limit, $leaseSeconds);
                foreach ($rows as $r) {
                    $this->tokens[(string) $r['receipt_unit_uuid']] = (string) $r['lease_token'];
                }
                return $rows;
            }

            public function getHandoff(string $receiptUnitUuid): ?array
            {
                return $this->inner->getHandoff($receiptUnitUuid);
            }

            public function acknowledgeProcessed(string $receiptUnitUuid, string $leaseToken): array
            {
                if ($this->failAcks > 0) {
                    $this->failAcks--;
                    throw new \RuntimeException('falla simulada de la BD de Compras (ack no aplicado)');
                }
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
    }

    /** Emula el vencimiento del lease tras un crash: `markRetry(ahora)` con el token vigente, por la API pública de Compras. */
    private function si4qReclaimable(string $u): void
    {
        $this->si4gAsWorker([$this->si4E, $this->si4E2]);
        $token = $this->si4qSrc->tokens[$u] ?? '';
        try {
            if ($token !== '') {
                $this->si4qSrc->markRetry($u, $token, 'selftest: lease vencido (crash simulado)', new \DateTimeImmutable('-5 seconds'));
            }
        } catch (\RuntimeException) {
            // ya no estaba LEASED con ese token (p. ej. RETRY/ERROR): nada que emular
        }
        $this->si4gAsWorker([$this->si4E]);
    }

    /** @return callable(string,string):void */
    private function si4qCrashAt(string $point): callable
    {
        return static function (string $p) use ($point): void {
            if ($p === $point) {
                throw new SimulatedCrash('muere en ' . $point);
            }
        };
    }

    /**
     * Unidad REAL llevada a BRIDGED en modo SI4-2 (sin código) — punto de partida para códigos preexistentes y ACL.
     *
     * @return array{0:string,1:int} [uuid, id del Computer]
     */
    private function si4qBridgedUnit(): array
    {
        [$u] = $this->si4Receive(1);
        $this->si4qUuids[] = $u;
        $this->si4gAsWorker([$this->si4E]);
        $this->si4qWorker('st-q-bridged', false)->run();
        $id = (int) (((new DbSagaStore())->get($u) ?? [])['glpi_items_id'] ?? 0);
        $this->si4gTrack('Computer', $id);
        return [$u, $id];
    }

    /** Código generado por un humano (servicio de companyqr, como el botón de administración). */
    private function si4qHumanCode(int $computerId): QrCode
    {
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $pc = new Computer();
        $pc->getFromDB($computerId);
        return (new CodeManager())->getOrCreateForItem($pc);
    }

    private function si4qStatus(string $u): string
    {
        $this->si4gAsWorker([$this->si4E, $this->si4E2]);
        $st = (string) (((new PurchasingIntegrationApi())->getHandoff($u) ?? [])['status'] ?? '');
        $this->si4gAsWorker([$this->si4E]);
        return $st;
    }

    /** Eventos `handoff.done` de la auditoría de Compras para la unidad (DONE exactamente una vez). */
    private function si4qDoneEvents(string $u): int
    {
        return count((new PurchasingEvent())->find(['event' => PurchasingEvent::EV_HANDOFF_DONE, 'idempotency_key' => ['LIKE', 'handoff-done:' . $u . ':%']]));
    }

    private function si4qCodes(string $itemtype, int $id): int
    {
        return $id > 0 ? countElementsInTable(QrCode::getTable(), ['itemtype' => $itemtype, 'items_id' => $id]) : 0;
    }

    private function si4qCodeId(string $itemtype, int $id): int
    {
        $c = new QrCode();
        return $c->getFromDBByCrit(['itemtype' => $itemtype, 'items_id' => $id]) ? (int) $c->getID() : 0;
    }

    /** 1 activo Snipe, 1 Computer (por número de inventario), 1 Infocom, 1 puente y 1 código para la unidad. */
    private function si4qOnes(string $u): bool
    {
        $s = (new DbSagaStore())->get($u) ?? [];
        $id = (int) ($s['glpi_items_id'] ?? 0);
        $tag = AssetTagDeriver::tagFor('ST4-', $u);
        return count($this->si4Snipe->liveByTag($tag)) === 1 && $this->si4gCount('Computer', $tag) === 1
            && count((new \Infocom())->find(['itemtype' => 'Computer', 'items_id' => $id])) === 1
            && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => $u]) === 1 && $this->si4qCodes('Computer', $id) === 1;
    }

    private function si4qCodesFp(): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $acc = '';
        foreach ($DB->request(['FROM' => QrCode::getTable(), 'ORDER' => 'id ASC']) as $r) {
            $acc .= json_encode($r);
        }
        return hash('sha256', $acc);
    }

    /** Texto de los flujos del PDF (descomprimidos si vienen con FlateDecode). */
    private function si4qPdfText(string $pdf): string
    {
        $out = '';
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
            foreach ($m[1] as $raw) {
                $dec = @gzuncompress($raw);
                $out .= ($dec !== false ? $dec : $raw) . "\n";
            }
        }
        return $out;
    }

    private function si4qCleanup(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
            foreach ($DB->request(['SELECT' => ['glpi_itemtype', 'glpi_items_id'], 'FROM' => Si4Saga::getTable(), 'WHERE' => ['NOT' => ['glpi_items_id' => null]]]) as $r) {
                $c = new QrCode();
                if ($c->getFromDBByCrit(['itemtype' => (string) $r['glpi_itemtype'], 'items_id' => (int) $r['glpi_items_id']])) {
                    $c->delete(['id' => $c->getID()], true);
                }
            }
        } catch (\Throwable) {
            // best-effort; el stack de CI es efímero.
        }
    }
}
