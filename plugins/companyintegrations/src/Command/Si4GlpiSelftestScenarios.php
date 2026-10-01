<?php

/**
 * Escenarios de SELFTEST de SI4-2 (ADR-0021) sobre GLPI 11.0.8 REAL: activo nativo (`CommonDBTM::add/find/update`),
 * Infocom nativo, `asset_bridge` y `DbSagaStore` con el reloj de la BD. Reusa la fixture de Compras de SI4-1
 * (`Si4SelftestScenarios`: solicitud → aprobaciones → compra → recepción por servicios públicos).
 *
 *   [SI4G-UPGRADE]   0.3.0 (SI4-1 con sagas) → install() ×2 (0.4.0): sagas/puentes intactos, columnas y tabla nuevas
 *   [SI4G-RESUME]    una saga SI4-1 en SNIPE_CREATED continúa hasta BRIDGED tras el upgrade
 *   [SI4G-E2E]       outbox real ⇒ worker del COMANDO real ⇒ Snipe ⇒ Computer ⇒ Infocom ⇒ asset_bridge (sin ack)
 *   [SI4G-AGENT]     el GLPI Agent ya creó el Computer con el mismo serial ⇒ se VINCULA (sin otro alta, Lockedfield)
 *   [SI4G-AMBIGUOUS] 2 Computers con el mismo serial ⇒ MANUAL_REVIEW, ninguno se modifica
 *   [SI4G-OTHER-ENT] mismo serial en otra entidad ⇒ no se adopta
 *   [SI4G-MAPPING]   mapeo ausente ⇒ BLOCKED_CONFIG + RETRY; se reanuda al configurar; itemtype/modelo inválidos
 *   [SI4G-CRASH]     crash points §9 con toma por época: 1 activo, 1 Infocom, 1 puente; el dueño viejo no escribe
 *   [SI4G-FENCE]     `holds()` con el reloj real de la BD: lease restante < presupuesto ⇒ ninguna escritura en GLPI
 *   [SI4G-ITEMTYPES] Computer/Monitor/NetworkEquipment/Peripheral/Phone/Printer: crash tras el alta ⇒ un solo activo
 *   [SI4G-MULTI-ENT] entidad de la unidad respetada y ACL de entidad del usuario técnico
 *   [SI4G-ACL]       sin CREATE del itemtype / sin Infocom ⇒ BLOCKED_CONFIG sin escribir
 *   [SI4G-INFOCOM]   Infocom existente distinto / moneda distinta ⇒ MANUAL_REVIEW sin pisar
 *   [SI4G-PIN]       destino GLPI pinneado por saga: el retry usa el modelo pinneado aunque el mapeo cambie; una unidad
 *                    nueva usa el vigente; modelo pinneado eliminado ⇒ MANUAL_REVIEW; el pin no se reescribe
 *   [SI4G-CLAIM-RACE] reclamo del GLPI Agent y otro candidato (mismo serial / mismo tag) justo después ⇒ MANUAL_REVIEW,
 *                    sin vínculo, Infocom ni puente
 *   [SI4G-SUPPLIER]  proveedor movido a otra rama antes de SI4-2 o entre el activo y el Infocom ⇒ MANUAL_REVIEW sin
 *                    Infocom ni puente; proveedor recursivo de un ancestro ⇒ permitido
 *   [SI4G-NO-SIDE-EFFECTS] sin companyqr, sin ack, Snipe sólo hardware/statuslabels
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Command;

use Computer;
use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Model\AssetTagAlias;
use GlpiPlugin\Companyintegrations\Model\MapGlpiAssetType;
use GlpiPlugin\Companyintegrations\Model\Si4Saga;
use GlpiPlugin\Companyintegrations\Service\PluginConfig as IntegrationsConfig;
use GlpiPlugin\Companyintegrations\Si4\AssetTagDeriver;
use GlpiPlugin\Companyintegrations\Si4\CoreGlpiAssetGateway;
use GlpiPlugin\Companyintegrations\Si4\DbBridgeStore;
use GlpiPlugin\Companyintegrations\Si4\DbGlpiMappingResolver;
use GlpiPlugin\Companyintegrations\Si4\DbSagaStore;
use GlpiPlugin\Companyintegrations\Si4\GlpiMappingRules;
use GlpiPlugin\Companyintegrations\Si4\SagaState;
use GlpiPlugin\Companyintegrations\Si4\Si4Config;
use GlpiPlugin\Companyintegrations\Si4\Si4GlpiStage;
use GlpiPlugin\Companyintegrations\Si4\Si4Worker;
use GlpiPlugin\Companyintegrations\Si4\SimulatedCrash;
use GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi;
use GlpiPlugin\Companypurchasing\Model\Request as PurchaseRequest;

trait Si4GlpiSelftestScenarios
{
    /** Columnas de `si4_sagas` en 0.3.0 (SI4-1): deben quedar intactas tras el upgrade. */
    private const SI4G_SAGA_COLS_030 = [
        'id', 'receipt_unit_uuid', 'entities_id', 'requests_id', 'items_id', 'payload_sha256', 'correlation_id', 'state',
        'snipe_asset_id', 'snipe_asset_tag', 'snipe_outcome', 'snipe_company_id', 'snipe_model_id', 'snipe_status_id',
        'lease_token_sha256', 'lease_until', 'lease_epoch', 'worker_id', 'attempts', 'remote_create_calls', 'row_version',
        'last_error', 'last_error_class', 'date_creation', 'date_mod',
    ];
    private const SI4G_SAGA_COLS_NEW = [
        'glpi_itemtype', 'glpi_items_id', 'glpi_entity_id', 'glpi_outcome', 'glpi_create_calls', 'glpi_infocom_id',
        'infocom_outcome', 'asset_bridge_id', 'resume_state', 'glpi_mapping_id', 'glpi_model_id', 'glpi_mapping_hash',
    ];
    private const SI4G_BRIDGE_COLS_030 = [
        'id', 'snipe_asset_id', 'snipe_asset_tag', 'glpi_itemtype', 'glpi_items_id', 'glpi_entity_id', 'serial', 'sync_status',
        'source_version', 'correlation_id', 'last_sync_at', 'last_reconciled_at', 'last_error', 'date_creation', 'date_mod',
    ];

    private int $si4gModel = 0;
    private int $si4gMapId = 0;
    /** @var array<int,int> modelos y proveedores extra creados por los escenarios (para limpiar) */
    private array $si4gModels = [];
    private array $si4gSuppliers = [];
    /** Proveedor de la entidad E2 (el de la compra está en E1, sin recursividad) */
    private int $si4gSupplierE2 = 0;
    private string $si4gCategory = '';
    private int $si4gSnipeSeq = 0;
    /** @var array<int,array{0:string,1:int}> activos GLPI creados por los escenarios (para limpiar) */
    private array $si4gItems = [];
    /** @var array<int,string> unidades procesadas por SI4-2 */
    private array $si4gUuids = [];

    private function runSi4gScenarios(): void
    {
        if (!class_exists(PurchasingIntegrationApi::class) || $this->si4Req <= 0 || $this->si4Snipe === null) {
            $this->check('[SI4G-E2E] fixture de SI4-1 (Compras + Snipe fake) disponible', false);
            return;
        }
        $this->si4gSnipeSeq = 7_700_000 + random_int(0, 99_999) * 10;
        try {
            $this->si4gUpgrade();
            $this->si4gFixture();
            $this->si4gResume();
            $this->si4gE2e();
            $this->si4gAgent();
            $this->si4gAmbiguous();
            $this->si4gOtherEntity();
            $this->si4gMapping();
            $this->si4gCrash();
            $this->si4gFence();
            $this->si4gItemtypes();
            $this->si4gMultiEntity();
            $this->si4gAcl();
            $this->si4gInfocom();
            $this->si4gPin();
            $this->si4gClaimRace();
            $this->si4gSupplier();
            $this->si4gNoSideEffects();
        } catch (\Throwable $e) {
            $this->check('[SI4G-E2E] sin excepciones: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
        } finally {
            $this->si4gCleanup();
            $this->si4Restore();
        }
    }

    // ------------------------------------------------------------------ [SI4G-UPGRADE]

    private function si4gUpgrade(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [SI4G-UPGRADE] 0.3.0 (SI4-1 con sagas) → install() ×2 (0.4.0) ==');
        $this->applySession(2, [0], ['config' => ALLSTANDARDRIGHT], 1);
        if (!function_exists('plugin_companyintegrations_install')) {
            include_once dirname(__DIR__, 2) . '/hook.php';
        }
        $p = 'glpi_plugin_companyintegrations_';
        $saved = \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
        try {
            // (1) Estado 0.3.0: sin lo de SI4-2 (columnas, índices, tabla, clave de config) y con sagas SI4-1 reales.
            foreach (self::SI4G_SAGA_COLS_NEW as $c) {
                if ($DB->fieldExists("{$p}si4_sagas", $c, false)) {
                    if ($c === 'glpi_itemtype' && isIndex("{$p}si4_sagas", 'glpi_item')) {
                        $DB->doQuery("ALTER TABLE `{$p}si4_sagas` DROP INDEX `glpi_item`");
                    }
                    $DB->doQuery("ALTER TABLE `{$p}si4_sagas` DROP COLUMN `{$c}`");
                }
            }
            if (isIndex("{$p}asset_bridge", 'receipt_unit_uuid')) {
                $DB->doQuery("ALTER TABLE `{$p}asset_bridge` DROP INDEX `receipt_unit_uuid`");
            }
            if ($DB->fieldExists("{$p}asset_bridge", 'receipt_unit_uuid', false)) {
                $DB->doQuery("ALTER TABLE `{$p}asset_bridge` DROP COLUMN `receipt_unit_uuid`");
            }
            $DB->doQuery("DROP TABLE IF EXISTS `{$p}map_glpi_assettypes`");
            $DB->clearSchemaCache();
            \Config::deleteConfigurationValues(IntegrationsConfig::CONTEXT, ['si4_glpi_infocom_currency']);
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, ['si4_asset_tag_prefix' => 'KEEP-', 'si4_lease_seconds' => '1234']);
            $bridge = (int) (new AssetBridge())->add(['snipe_asset_id' => 990002, 'snipe_asset_tag' => 'UPG2-' . $this->suffix, 'glpi_itemtype' => 'Computer',
                'glpi_items_id' => 990002, 'glpi_entity_id' => 0, 'sync_status' => AssetBridge::STATUS_MATCHED]);
            $sagas030 = countElementsInTable(Si4Saga::getTable(), ['state' => SagaState::SNIPE_CREATED]);
            $fpSagas = $this->si4gFp('si4_sagas', self::SI4G_SAGA_COLS_030);
            $fpLog = $this->si4gFp('si4_saga_log', ['id', 'receipt_unit_uuid', 'event', 'from_state', 'to_state', 'detail']);
            $fpBridge = $this->si4gFp('asset_bridge', self::SI4G_BRIDGE_COLS_030);
            $rightsBefore = $this->si4gRights();

            // (2) Upgrade: install() dos veces.
            $ok = true;
            try {
                plugin_companyintegrations_install();
                plugin_companyintegrations_install();
            } catch (\Throwable $e) {
                $ok = false;
                $this->out->writeln('    ' . $e->getMessage());
            }
            $DB->clearSchemaCache();
            $this->check('[SI4G-UPGRADE] install() ×2 sobre 0.3.0 no falla', $ok);
            $cols = true;
            foreach (self::SI4G_SAGA_COLS_NEW as $c) {
                $cols = $cols && $DB->fieldExists("{$p}si4_sagas", $c, false);
            }
            $this->check('[SI4G-UPGRADE] columnas nuevas de la saga + UNIQUE(glpi_itemtype, glpi_items_id)', $cols && isIndex("{$p}si4_sagas", 'glpi_item'));
            $this->check('[SI4G-UPGRADE] asset_bridge.receipt_unit_uuid (UNIQUE) y tabla map_glpi_assettypes',
                $DB->fieldExists("{$p}asset_bridge", 'receipt_unit_uuid', false) && isIndex("{$p}asset_bridge", 'receipt_unit_uuid')
                && $DB->tableExists("{$p}map_glpi_assettypes", false));
            $this->check('[SI4G-UPGRADE] 🔒 sagas SI4-1 INTACTAS (huella de las columnas 0.3.0 + bitácora) y siguen en SNIPE_CREATED',
                $fpSagas === $this->si4gFp('si4_sagas', self::SI4G_SAGA_COLS_030) && $fpLog === $this->si4gFp('si4_saga_log', ['id', 'receipt_unit_uuid', 'event', 'from_state', 'to_state', 'detail'])
                && $sagas030 >= 3 && countElementsInTable(Si4Saga::getTable(), ['state' => SagaState::SNIPE_CREATED]) === $sagas030);
            $this->check('[SI4G-UPGRADE] 🔒 asset_bridge existente intacto (uuid NULL para los puentes SI-1)', $fpBridge === $this->si4gFp('asset_bridge', self::SI4G_BRIDGE_COLS_030)
                && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => null]) === countElementsInTable(AssetBridge::getTable()));
            $conf = \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
            $this->check('[SI4G-UPGRADE] config existente preservada; default nuevo sólo si falta (si4_glpi_infocom_currency = PYG)',
                ($conf['si4_asset_tag_prefix'] ?? '') === 'KEEP-' && ($conf['si4_lease_seconds'] ?? '') === '1234' && ($conf['si4_glpi_infocom_currency'] ?? '') === 'PYG'
                && ($conf['si4_enabled'] ?? '') === '0');
            $this->check('[SI4G-UPGRADE] derechos existentes preservados (sin filas nuevas ni bits perdidos)', $this->si4gRights() === $rightsBefore);
            (new AssetBridge())->delete(['id' => $bridge], true);
        } finally {
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, is_array($saved) ? $saved : []);
        }
    }

    // ------------------------------------------------------------------ fixture SI4-2

    private function si4gFixture(): void
    {
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $this->si4gModel = (int) (new \ComputerModel())->add(['name' => 'SI4G-NB-' . $this->suffix]);
        $this->si4gCategory = $this->si4Category; // misma categoría de la línea de compra que SI4-1
        $this->si4gMapId = (int) (new MapGlpiAssetType())->add(['category_key' => $this->si4gCategory, 'glpi_itemtype' => 'Computer', 'glpi_model_id' => $this->si4gModel, 'is_approved' => 1]);
        $this->si4gSupplierE2 = $this->si4gMakeSupplier('SI4G-SUP-E2-', $this->si4E2, false);
        $this->check('[SI4G-E2E] fixture: modelo GLPI, mapeo aprobado categoría ⇒ Computer y proveedor de E2', $this->si4gModel > 0 && $this->si4gMapId > 0
            && $this->si4gSupplierE2 > 0 && countElementsInTable(MapGlpiAssetType::getTable(), ['category_key' => $this->si4gCategory, 'is_approved' => 1]) === 1);
    }

    // ------------------------------------------------------------------ [SI4G-RESUME]

    private function si4gResume(): void
    {
        $this->out->writeln('== [SI4G-RESUME] saga SI4-1 en SNIPE_CREATED continúa tras el upgrade ==');
        /** @var \DBmysql $DB */
        global $DB;
        // La saga SI4-1 MÁS ANTIGUA con handoff real: en un upgrade real es una que creó el código 0.3.0.
        $api = new PurchasingIntegrationApi();
        $row = null;
        $handoff = null;
        foreach ($DB->request(['FROM' => Si4Saga::getTable(), 'WHERE' => ['state' => SagaState::SNIPE_CREATED], 'ORDER' => 'id ASC']) as $r) {
            $handoff = $api->getHandoff((string) $r['receipt_unit_uuid']);
            if ($handoff !== null) {
                $row = $r;
                break;
            }
        }
        if ($row === null || $handoff === null) {
            $this->check('[SI4G-RESUME] hay una saga SI4-1 en SNIPE_CREATED', false);
            return;
        }
        $u = (string) $row['receipt_unit_uuid'];
        $payload = (array) $handoff['payload'];
        $entity = (int) $payload['entity_id'];
        $category = (string) $payload['category'];
        if (countElementsInTable(MapGlpiAssetType::getTable(), ['category_key' => $category]) === 0) {
            $this->si4AsAdmin([0, $entity]);
            (new MapGlpiAssetType())->add(['category_key' => $category, 'glpi_itemtype' => 'Computer', 'glpi_model_id' => $this->si4gModel, 'is_approved' => 1]);
        }
        $h = $this->si4gTakeover($u, (int) $row['lease_epoch'] + 1);
        $this->si4gAsWorker([$entity]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-g-resume');
        $s = (new DbSagaStore())->get($u);
        $this->si4gTrack('Computer', (int) ($s['glpi_items_id'] ?? 0));
        $this->out->writeln('    saga #' . (int) $row['id'] . ' creada ' . (string) $row['date_creation'] . ' (entidad ' . $entity . ')');
        $this->check('[SI4G-RESUME] la saga SI4-1 llega a BRIDGED (activo GLPI + Infocom + puente) sin tocar Snipe', $o['kind'] === Si4GlpiStage::O_BRIDGED
            && ($s['state'] ?? '') === SagaState::BRIDGED && (int) $s['snipe_asset_id'] === (int) $row['snipe_asset_id'] && $this->si4gCount('Computer', (string) $row['snipe_asset_tag']) === 1);
    }

    // ------------------------------------------------------------------ [SI4G-E2E]

    private function si4gE2e(): void
    {
        $this->out->writeln('== [SI4G-E2E] outbox real ⇒ worker del comando real ⇒ Snipe ⇒ Computer ⇒ Infocom ⇒ asset_bridge ==');
        // Unidades que SI4-1 dejó PENDING (p. ej. la de [SI4-CMD]) se procesan antes para que las métricas sean exactas.
        $this->si4gAsWorker([$this->si4E]);
        $this->si4gWorker('st-g-drain')->run();
        $uuids = $this->si4Receive(2);
        $this->si4gAsWorker([$this->si4E]);
        $posts = $this->si4Posts();
        $m = $this->si4gWorker('st-g-A')->run();
        $this->check('[SI4G-E2E] 2 unidades: Snipe creado y GLPI creado en UNA pasada (BRIDGED)', $m['claimed'] === 2 && $m['bridged'] === 2
            && $m['snipe_created'] === 2 && $m['glpi_created'] === 2 && $this->si4Posts() === $posts + 2 && $m['aborted'] === null);
        $req = new PurchaseRequest();
        $req->getFromDB($this->si4Req);
        $number = (string) ($req->fields['number'] ?? '');
        $api = new PurchasingIntegrationApi();
        $asset = $infocom = $bridge = $leased = $noQr = true;
        foreach ($uuids as $u) {
            $this->si4gUuids[] = $u;
            $s = (new DbSagaStore())->get($u) ?? [];
            $tag = AssetTagDeriver::tagFor('ST4-', $u);
            $payload = (array) ($api->getHandoff($u)['payload'] ?? []);
            $pc = new Computer();
            $okPc = $pc->getFromDB((int) ($s['glpi_items_id'] ?? 0));
            $this->si4gTrack('Computer', (int) ($s['glpi_items_id'] ?? 0));
            $asset = $asset && $okPc && ($s['state'] ?? '') === SagaState::BRIDGED && (int) $pc->fields['entities_id'] === $this->si4E
                && (int) ($s['glpi_mapping_id'] ?? 0) === $this->si4gMapId && (int) ($s['glpi_model_id'] ?? -1) === $this->si4gModel
                && ($s['glpi_mapping_hash'] ?? '') === GlpiMappingRules::pinHash($this->si4gMapId, $this->si4gCategory, 'Computer', $this->si4gModel)
                && (string) $pc->fields['serial'] === (string) $payload['serial'] && (string) $pc->fields['otherserial'] === $tag
                && (int) $pc->fields['computermodels_id'] === $this->si4gModel && (string) $pc->fields['name'] === 'Notebook SI4'
                && (int) $pc->fields['is_recursive'] === 0 && $this->si4gCount('Computer', $tag) === 1;
            $ic = new \Infocom();
            $infocom = $infocom && $ic->getFromDBforDevice('Computer', (int) $pc->getID())
                && (string) $ic->fields['value'] === '2500.0000' && (int) $ic->fields['suppliers_id'] === (int) $this->si4Suppliers[0]
                && (string) $ic->fields['order_number'] === $number && (string) $ic->fields['delivery_date'] === substr((string) $payload['received_at'], 0, 10)
                && $ic->fields['buy_date'] === null && (int) ($s['glpi_infocom_id'] ?? 0) === (int) $ic->getID();
            $b = new AssetBridge();
            $bridge = $bridge && $b->getFromDB((int) ($s['asset_bridge_id'] ?? 0)) && (string) $b->fields['receipt_unit_uuid'] === $u
                && (int) $b->fields['snipe_asset_id'] === (int) $s['snipe_asset_id'] && (string) $b->fields['snipe_asset_tag'] === $tag
                && (string) $b->fields['glpi_itemtype'] === 'Computer' && (int) $b->fields['glpi_items_id'] === (int) $pc->getID()
                && (int) $b->fields['glpi_entity_id'] === $this->si4E && (string) $b->fields['sync_status'] === AssetBridge::STATUS_MATCHED
                && countElementsInTable(AssetTagAlias::getTable(), ['asset_tag' => $tag, 'asset_bridge_id' => (int) $b->getID(), 'is_current' => 1]) === 1;
            $leased = $leased && ($api->getHandoff($u)['status'] ?? '') === 'LEASED';
            $noQr = $noQr && $this->si4gQrCode($pc) === null;
        }
        $this->check('[SI4G-E2E] Computer nativo: entidad de la unidad, serial, número de inventario = tag, modelo del mapeo (pinneado en la saga), nombre de la línea', $asset);
        $this->check('[SI4G-E2E] Infocom nativo: costo EXACTO de la unidad (2500.0000), proveedor de la compra, n.º de solicitud, fecha de recepción', $infocom);
        $this->check('[SI4G-E2E] asset_bridge 1:1 (uuid, Snipe id/tag, activo GLPI, entidad) + alias vigente', $bridge);
        $this->check('[SI4G-E2E] 🔒 NO acknowledgeProcessed: el outbox sigue LEASED (SI4-3 pendiente)', $leased);
        $this->check('[SI4G-E2E] 🔒 sin código companyqr para los activos creados', $noQr);
        $before = countElementsInTable(Computer::getTable());
        $m = $this->si4gWorker('st-g-A')->run();
        $this->check('[SI4G-E2E] re-ejecutar el worker no crea nada (leases vigentes)', $m['claimed'] === 0 && countElementsInTable(Computer::getTable()) === $before);
    }

    // ------------------------------------------------------------------ [SI4G-AGENT] / [SI4G-AMBIGUOUS] / [SI4G-OTHER-ENT]

    private function si4gAgent(): void
    {
        $this->out->writeln('== [SI4G-AGENT] el GLPI Agent ya creó el Computer con el mismo serial ⇒ VINCULAR ==');
        $serial = $this->si4gNextSerial();
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $agent = (int) (new Computer())->add(['name' => 'agent-' . $this->suffix, 'entities_id' => $this->si4E, 'serial' => $serial, 'is_dynamic' => 1]);
        $this->si4gTrack('Computer', $agent);
        [$u] = $this->si4Receive(1);
        $this->si4gUuids[] = $u;
        $this->si4gAsWorker([$this->si4E]);
        $m = $this->si4gWorker('st-g-A')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $pc = new Computer();
        $pc->getFromDB($agent);
        $tag = AssetTagDeriver::tagFor('ST4-', $u);
        $this->check('[SI4G-AGENT] encuentra exactamente 1 y lo VINCULA', $m['bridged'] === 1 && $m['glpi_linked'] === 1 && (int) ($s['glpi_items_id'] ?? 0) === $agent
            && ($s['glpi_outcome'] ?? '') === SagaState::OUTCOME_LINKED);
        $this->check('[SI4G-AGENT] 🔒 no crea otro Computer', count((new Computer())->find(['serial' => $serial])) === 1 && $m['glpi_created'] === 0);
        $this->check('[SI4G-AGENT] reclama el número de inventario y GLPI lo BLOQUEA frente al agente (Lockedfield nativo)', (string) $pc->fields['otherserial'] === $tag
            && in_array('otherserial', (new \Lockedfield())->getLockedNames('Computer', $agent), true));
        $ic = new \Infocom();
        $this->check('[SI4G-AGENT] Infocom y puente sobre el activo del agente', $ic->getFromDBforDevice('Computer', $agent) && (string) $ic->fields['value'] === '2500.0000'
            && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => $u, 'glpi_items_id' => $agent]) === 1);
    }

    private function si4gAmbiguous(): void
    {
        $this->out->writeln('== [SI4G-AMBIGUOUS] 2 Computers con el mismo serial ⇒ MANUAL_REVIEW ==');
        $serial = $this->si4gNextSerial();
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $a = (int) (new Computer())->add(['name' => 'dup-a-' . $this->suffix, 'entities_id' => $this->si4E, 'serial' => $serial, 'is_dynamic' => 1]);
        $b = (int) (new Computer())->add(['name' => 'dup-b-' . $this->suffix, 'entities_id' => $this->si4E, 'serial' => $serial, 'is_dynamic' => 1]);
        $this->si4gTrack('Computer', $a);
        $this->si4gTrack('Computer', $b);
        $fp = $this->si4gItemFp('Computer', [$a, $b]);
        [$u] = $this->si4Receive(1);
        $this->si4gUuids[] = $u;
        $this->si4gAsWorker([$this->si4E]);
        $m = $this->si4gWorker('st-g-A')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->check('[SI4G-AMBIGUOUS] 🔒 AMBIGUOUS ⇒ MANUAL_REVIEW y outbox ERROR', $m['manual_review'] === 1 && ($s['last_error_class'] ?? '') === 'glpi_ambiguous'
            && ((new PurchasingIntegrationApi())->getHandoff($u)['status'] ?? '') === 'ERROR');
        $this->check('[SI4G-AMBIGUOUS] 🔒 ninguno se modifica y no se crea un tercero', $this->si4gItemFp('Computer', [$a, $b]) === $fp
            && count((new Computer())->find(['serial' => $serial])) === 2 && $this->si4gCount('Computer', AssetTagDeriver::tagFor('ST4-', $u)) === 0);
    }

    private function si4gOtherEntity(): void
    {
        $this->out->writeln('== [SI4G-OTHER-ENT] mismo serial en OTRA entidad ⇒ no se adopta ==');
        $serial = $this->si4gNextSerial();
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $other = (int) (new Computer())->add(['name' => 'e2-' . $this->suffix, 'entities_id' => $this->si4E2, 'serial' => $serial, 'is_dynamic' => 1]);
        $this->si4gTrack('Computer', $other);
        $fp = $this->si4gItemFp('Computer', [$other]);
        [$u] = $this->si4Receive(1);
        $this->si4gUuids[] = $u;
        $this->si4gAsWorker([$this->si4E, $this->si4E2]);
        $this->si4gWorker('st-g-A')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->check('[SI4G-OTHER-ENT] 🔒 MANUAL_REVIEW (entity_mismatch): ni se adopta ni se crea otro', ($s['last_error_class'] ?? '') === 'glpi_entity_mismatch'
            && $this->si4gItemFp('Computer', [$other]) === $fp && count((new Computer())->find(['serial' => $serial])) === 1 && ($s['glpi_items_id'] ?? null) === null);
    }

    // ------------------------------------------------------------------ [SI4G-MAPPING]

    private function si4gMapping(): void
    {
        $this->out->writeln('== [SI4G-MAPPING] mapeo GLPI ausente ⇒ BLOCKED_CONFIG + RETRY; se reanuda al configurar ==');
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $map = new MapGlpiAssetType();
        $map->getFromDBByCrit(['category_key' => $this->si4gCategory]);
        $map->update(['id' => $map->getID(), 'is_approved' => 0]);
        [$u] = $this->si4Receive(1);
        $this->si4gUuids[] = $u;
        $this->si4gAsWorker([$this->si4E]);
        $m = $this->si4gWorker('st-g-A')->run();
        $s = (new DbSagaStore())->get($u) ?? [];
        $h = (new PurchasingIntegrationApi())->getHandoff($u) ?? [];
        $tag = AssetTagDeriver::tagFor('ST4-', $u);
        $this->check('[SI4G-MAPPING] BLOCKED_CONFIG con resume_state = SNIPE_CREATED (Snipe ya hecho)', $m['blocked_config'] === 1 && ($s['state'] ?? '') === SagaState::BLOCKED_CONFIG
            && ($s['resume_state'] ?? '') === SagaState::SNIPE_CREATED && (int) ($s['snipe_asset_id'] ?? 0) > 0);
        $this->check('[SI4G-MAPPING] outbox RETRY (no ERROR) y ningún activo GLPI', ($h['status'] ?? '') === 'RETRY' && str_contains((string) ($h['last_error'] ?? ''), 'mapping')
            && $this->si4gCount('Computer', $tag) === 0);
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $map->update(['id' => $map->getID(), 'is_approved' => 1]);
        $posts = $this->si4Posts();
        $hTok = $this->si4gTakeover($u, (int) $s['lease_epoch'] + 1);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u, $hTok, (array) ($h['payload'] ?? []), 'st-g-map');
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->si4gTrack('Computer', (int) ($s['glpi_items_id'] ?? 0));
        $this->check('[SI4G-MAPPING] configurado el mapeo ⇒ reanuda desde SNIPE_CREATED hasta BRIDGED sin volver a Snipe', $o['kind'] === Si4GlpiStage::O_BRIDGED
            && ($s['state'] ?? '') === SagaState::BRIDGED && array_key_exists('resume_state', $s) && $s['resume_state'] === null && $this->si4Posts() === $posts && $this->si4gCount('Computer', $tag) === 1);

        foreach ([['Software', 0, 'itemtype no soportado'], ['Computer', 999999999, 'modelo GLPI inexistente'], ['Glpi\\Asset\\Asset', 0, 'activo personalizado (fuera de SI4-2)']] as [$type, $model, $label]) {
            $cat = 'SI4G-BAD-' . substr(md5($type . $model), 0, 6) . '-' . $this->suffix;
            $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
            (new MapGlpiAssetType())->add(['category_key' => $cat, 'glpi_itemtype' => $type, 'glpi_model_id' => $model, 'is_approved' => 1]);
            [$su, $sh, $payload] = $this->si4gSynthetic($this->si4E, null, $cat);
            $this->si4gAsWorker([$this->si4E]);
            $o = $this->si4gStage()->advance($su, $sh, $payload, 'st-g-bad');
            $this->check("[SI4G-MAPPING] {$label} ⇒ BLOCKED_CONFIG sin escribir", $o['kind'] === Si4GlpiStage::O_BLOCKED && $o['class'] === 'glpi_mapping'
                && $this->si4gCount('Computer', AssetTagDeriver::tagFor('ST4-', $su)) === 0);
        }
    }

    // ------------------------------------------------------------------ [SI4G-CRASH]

    private function si4gCrash(): void
    {
        $this->out->writeln('== [SI4G-CRASH] crash points §9 + toma por época: nunca un segundo activo, Infocom ni puente ==');
        $points = ['before_glpi_search', 'before_glpi_create', 'after_glpi_create', 'after_glpi_recorded_unverified', 'after_glpi_recorded',
            'after_infocom_write', 'after_infocom_ready', 'after_bridge_write', 'after_bridged'];
        foreach ($points as $pt) {
            [$u, $hA, $payload, $tag] = $this->si4gSynthetic($this->si4E, 'SI4G-CR-' . $this->suffix . '-' . $pt, $this->si4gCategory);
            $this->si4gAsWorker([$this->si4E]);
            $crashed = false;
            try {
                $this->si4gStage(static function (string $p) use ($pt): void {
                    if ($p === $pt) {
                        throw new SimulatedCrash('muere en ' . $pt);
                    }
                })->advance($u, $hA, $payload, 'st-g-crash');
            } catch (SimulatedCrash) {
                $crashed = true;
            }
            $hB = $this->si4gTakeover($u, 2);
            $fp = $this->si4gFp('si4_sagas', ['state', 'glpi_items_id', 'glpi_infocom_id', 'asset_bridge_id']);
            $stale = $this->si4gStage()->advance($u, $hA, $payload, 'st-g-zombie');
            $expectStale = $pt === 'after_bridged' ? Si4GlpiStage::O_BRIDGED : Si4GlpiStage::O_LEASE_LOST; // BRIDGED ya es un no-op
            $staleOk = $stale['kind'] === $expectStale && $fp === $this->si4gFp('si4_sagas', ['state', 'glpi_items_id', 'glpi_infocom_id', 'asset_bridge_id']);
            $o = $this->si4gStage()->advance($u, $hB, $payload, 'st-g-retry');
            $o2 = $this->si4gStage()->advance($u, $hB, $payload, 'st-g-retry2');
            $s = (new DbSagaStore())->get($u) ?? [];
            $id = (int) ($s['glpi_items_id'] ?? 0);
            $this->si4gTrack('Computer', $id);
            $this->check("[SI4G-CRASH] «{$pt}» ⇒ el dueño viejo no escribe; el nuevo converge: 1 Computer, 1 Infocom, 1 puente",
                $crashed && $staleOk && $o['kind'] === Si4GlpiStage::O_BRIDGED && $o2['kind'] === Si4GlpiStage::O_BRIDGED && ($s['state'] ?? '') === SagaState::BRIDGED
                && $this->si4gCount('Computer', $tag) === 1 && count((new Computer())->find(['serial' => (string) $payload['serial']])) === 1
                && count((new \Infocom())->find(['itemtype' => 'Computer', 'items_id' => $id])) === 1
                && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => $u]) === 1);
        }
    }

    // ------------------------------------------------------------------ [SI4G-FENCE]

    private function si4gFence(): void
    {
        $this->out->writeln('== [SI4G-FENCE] holds() con el reloj real de la BD: lease corto ⇒ ninguna escritura en GLPI ==');
        $store = new DbSagaStore();
        [$u, $h] = $this->si4gSynthetic($this->si4E, null, $this->si4gCategory);
        $this->check('[SI4G-FENCE] dueño con lease amplio ⇒ holds() con y sin presupuesto', $store->holds($u, $h, 0) && $store->holds($u, $h, Si4GlpiStage::GLPI_WRITE_BUDGET_SEC));
        $this->check('[SI4G-FENCE] 🔒 presupuesto mayor que el lease restante (reloj de la BD) ⇒ holds() falso', !$store->holds($u, $h, 3600));
        $this->check('[SI4G-FENCE] 🔒 otro token ⇒ holds() falso', !$store->holds($u, hash('sha256', 'otro'), 0));
        $h2 = $this->si4gTakeover($u, 2, 10);
        $this->check('[SI4G-FENCE] 🔒 lease de 10 s < presupuesto de ' . Si4GlpiStage::GLPI_WRITE_BUDGET_SEC . ' s ⇒ holds() falso; sin presupuesto sí',
            !$store->holds($u, $h2, Si4GlpiStage::GLPI_WRITE_BUDGET_SEC) && $store->holds($u, $h2, 0));

        // Camino que depende SÓLO de holds(): vincular un activo del agente reclamando su número de inventario.
        $serial = 'SI4G-FENCE-' . $this->suffix;
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $agent = (int) (new Computer())->add(['name' => 'fence-' . $this->suffix, 'entities_id' => $this->si4E, 'serial' => $serial, 'is_dynamic' => 1]);
        $this->si4gTrack('Computer', $agent);
        [$u3, $h3, $payload3] = $this->si4gSynthetic($this->si4E, $serial, $this->si4gCategory, 10);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u3, $h3, $payload3, 'st-g-fence');
        $pc = new Computer();
        $pc->getFromDB($agent);
        $this->check('[SI4G-FENCE] 🔒 lease insuficiente ⇒ LEASE_LOST: no reclama el número de inventario, sin Infocom ni puente', $o['kind'] === Si4GlpiStage::O_LEASE_LOST
            && ($pc->fields['otherserial'] ?? null) === null && count((new \Infocom())->find(['itemtype' => 'Computer', 'items_id' => $agent])) === 0
            && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => $u3]) === 0 && (($store->get($u3) ?? [])['state'] ?? '') === SagaState::SNIPE_CREATED);
    }

    // ------------------------------------------------------------------ [SI4G-ITEMTYPES]

    private function si4gItemtypes(): void
    {
        $this->out->writeln('== [SI4G-ITEMTYPES] itemtypes soportados: crash tras el alta ⇒ un solo activo ==');
        foreach (CoreGlpiAssetGateway::SUPPORTED_ITEMTYPES as $type) {
            $cat = 'SI4G-T-' . $type . '-' . $this->suffix;
            $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
            (new MapGlpiAssetType())->add(['category_key' => $cat, 'glpi_itemtype' => $type, 'glpi_model_id' => 0, 'is_approved' => 1]);
            [$u, $hA, $payload, $tag] = $this->si4gSynthetic($this->si4E, 'SI4G-T-' . $this->suffix . '-' . $type, $cat);
            $this->si4gAsWorker([$this->si4E]);
            try {
                $this->si4gStage(static function (string $p): void {
                    if ($p === 'after_glpi_create') {
                        throw new SimulatedCrash('muere tras add()');
                    }
                })->advance($u, $hA, $payload, 'st-g-type');
            } catch (SimulatedCrash) {
            }
            $hB = $this->si4gTakeover($u, 2);
            $o = $this->si4gStage()->advance($u, $hB, $payload, 'st-g-type');
            $s = (new DbSagaStore())->get($u) ?? [];
            $this->si4gTrack($type, (int) ($s['glpi_items_id'] ?? 0));
            $this->check("[SI4G-ITEMTYPES] {$type}: BRIDGED con 1 activo (número de inventario = tag) y 1 Infocom", $o['kind'] === Si4GlpiStage::O_BRIDGED
                && ($s['glpi_itemtype'] ?? '') === $type && $this->si4gCount($type, $tag) === 1 && ($s['glpi_outcome'] ?? '') === SagaState::OUTCOME_CREATED
                && count((new \Infocom())->find(['itemtype' => $type, 'items_id' => (int) ($s['glpi_items_id'] ?? 0)])) === 1);
        }
    }

    // ------------------------------------------------------------------ [SI4G-MULTI-ENT] / [SI4G-ACL]

    private function si4gMultiEntity(): void
    {
        $this->out->writeln('== [SI4G-MULTI-ENT] entidad de la unidad + ACL de entidad del usuario técnico ==');
        [$u, $h, $payload, $tag] = $this->si4gSynthetic($this->si4E2, 'SI4G-ME-' . $this->suffix, $this->si4gCategory);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-g-me');
        $this->check('[SI4G-MULTI-ENT] 🔒 usuario técnico sin acceso a la entidad de la unidad ⇒ BLOCKED_CONFIG (acl), sin alta', $o['kind'] === Si4GlpiStage::O_BLOCKED
            && $o['class'] === 'acl' && $this->si4gCount('Computer', $tag) === 0);
        $this->si4gAsWorker([$this->si4E, $this->si4E2]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-g-me');
        $s = (new DbSagaStore())->get($u) ?? [];
        $pc = new Computer();
        $pc->getFromDB((int) ($s['glpi_items_id'] ?? 0));
        $this->si4gTrack('Computer', (int) $pc->getID());
        $this->check('[SI4G-MULTI-ENT] con acceso ⇒ el activo y el puente quedan en la entidad de la unidad', $o['kind'] === Si4GlpiStage::O_BRIDGED
            && (int) ($pc->fields['entities_id'] ?? -1) === $this->si4E2 && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => $u, 'glpi_entity_id' => $this->si4E2]) === 1);
    }

    private function si4gAcl(): void
    {
        $this->out->writeln('== [SI4G-ACL] derechos nativos del usuario técnico ==');
        [$u, $h, $payload, $tag] = $this->si4gSynthetic($this->si4E, 'SI4G-ACL-' . $this->suffix, $this->si4gCategory);
        $this->si4gAsWorker([$this->si4E], ['computer' => READ]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-g-acl');
        $this->check('[SI4G-ACL] 🔒 sin CREATE de Computer ⇒ BLOCKED_CONFIG (acl), sin alta', $o['kind'] === Si4GlpiStage::O_BLOCKED && $o['class'] === 'acl'
            && $o['resume'] === SagaState::SNIPE_CREATED && $this->si4gCount('Computer', $tag) === 0);
        $this->si4gAsWorker([$this->si4E], ['infocom' => READ]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-g-acl');
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->si4gTrack('Computer', (int) ($s['glpi_items_id'] ?? 0));
        $this->check('[SI4G-ACL] 🔒 sin CREATE de Infocom ⇒ BLOCKED_CONFIG tras el activo (resume GLPI_RESOLVED_OR_CREATED), sin Infocom', $o['kind'] === Si4GlpiStage::O_BLOCKED
            && $o['resume'] === SagaState::GLPI_RESOLVED_OR_CREATED && $this->si4gCount('Computer', $tag) === 1
            && count((new \Infocom())->find(['itemtype' => 'Computer', 'items_id' => (int) ($s['glpi_items_id'] ?? 0)])) === 0);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-g-acl');
        $this->check('[SI4G-ACL] otorgado ⇒ reanuda sin un segundo activo', $o['kind'] === Si4GlpiStage::O_BRIDGED && $this->si4gCount('Computer', $tag) === 1);
    }

    // ------------------------------------------------------------------ [SI4G-INFOCOM]

    private function si4gInfocom(): void
    {
        $this->out->writeln('== [SI4G-INFOCOM] Infocom existente distinto / moneda distinta ⇒ MANUAL_REVIEW sin pisar ==');
        $serial = 'SI4G-IC-' . $this->suffix;
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $pcId = (int) (new Computer())->add(['name' => 'ic-' . $this->suffix, 'entities_id' => $this->si4E, 'serial' => $serial, 'is_dynamic' => 1]);
        $this->si4gTrack('Computer', $pcId);
        $this->applySession(2, [0, $this->si4E], ['infocom' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        $icId = (int) (new \Infocom())->add(['itemtype' => 'Computer', 'items_id' => $pcId, 'value' => '999', 'suppliers_id' => 0]);
        [$u, $h, $payload] = $this->si4gSynthetic($this->si4E, $serial, $this->si4gCategory);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-g-ic');
        $ic = new \Infocom();
        $ic->getFromDB($icId);
        $this->check('[SI4G-INFOCOM] 🔒 Infocom con otro costo ⇒ MANUAL_REVIEW (infocom_conflict), el valor no se pisa', $o['kind'] === Si4GlpiStage::O_MANUAL
            && $o['class'] === 'infocom_conflict' && (string) $ic->fields['value'] === '999.0000');
        [$u2, $h2, $payload2, $tag2] = $this->si4gSynthetic($this->si4E, null, $this->si4gCategory);
        $payload2['currency'] = 'USD';
        $payload2['currency_scale'] = 2;
        $payload2['unit_cost'] = '11.00';
        $o = $this->si4gStage()->advance($u2, $h2, $payload2, 'st-g-usd');
        $this->check('[SI4G-INFOCOM] 🔒 moneda distinta de la del Infocom ⇒ MANUAL_REVIEW ANTES de crear el activo', $o['kind'] === Si4GlpiStage::O_MANUAL
            && $o['class'] === 'infocom_currency' && $this->si4gCount('Computer', $tag2) === 0);
    }

    // ------------------------------------------------------------------ [SI4G-PIN]

    private function si4gPin(): void
    {
        $this->out->writeln('== [SI4G-PIN] destino GLPI pinneado por saga: el retry nunca relee el mapeo vivo ==');
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $modelA = $this->si4gModel;
        $modelB = (int) (new \ComputerModel())->add(['name' => 'SI4G-NB-B-' . $this->suffix]);
        $modelC = (int) (new \ComputerModel())->add(['name' => 'SI4G-NB-C-' . $this->suffix]);
        array_push($this->si4gModels, $modelB, $modelC);
        $cat = 'SI4G-PIN-' . $this->suffix;
        $map = new MapGlpiAssetType();
        $mapId = (int) $map->add(['category_key' => $cat, 'glpi_itemtype' => 'Computer', 'glpi_model_id' => $modelA, 'is_approved' => 1]);
        $crash = static function (string $p): void {
            if ($p === 'before_glpi_search') {
                throw new SimulatedCrash('muere después del pin');
            }
        };

        // (1) primer intento: pin NOTEBOOK ⇒ Computer + modelo A; muere antes de buscar/crear.
        [$u, $hA, $payload, $tag] = $this->si4gSynthetic($this->si4E, 'SI4G-PIN-' . $this->suffix . '-1', $cat);
        $this->si4gAsWorker([$this->si4E]);
        try {
            $this->si4gStage($crash)->advance($u, $hA, $payload, 'st-g-pin');
        } catch (SimulatedCrash) {
        }
        $s = (new DbSagaStore())->get($u) ?? [];
        $this->check('[SI4G-PIN] primer uso ⇒ saga con mapeo, itemtype, modelo A y huella pinneados; ningún activo aún', (int) ($s['glpi_mapping_id'] ?? 0) === $mapId
            && ($s['glpi_itemtype'] ?? '') === 'Computer' && (int) ($s['glpi_model_id'] ?? -1) === $modelA
            && ($s['glpi_mapping_hash'] ?? '') === GlpiMappingRules::pinHash($mapId, $cat, 'Computer', $modelA) && $this->si4gCount('Computer', $tag) === 0);

        // (2) el administrador cambia la MISMA fila del mapeo al modelo B; retry de la misma saga ⇒ sigue en A.
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $map->update(['id' => $mapId, 'glpi_model_id' => $modelB]);
        $hB = $this->si4gTakeover($u, 2);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u, $hB, $payload, 'st-g-pin');
        $s = (new DbSagaStore())->get($u) ?? [];
        $pc = new Computer();
        $pc->getFromDB((int) ($s['glpi_items_id'] ?? 0));
        $this->si4gTrack('Computer', (int) $pc->getID());
        $this->check('[SI4G-PIN] 🔒 mapeo cambiado a B ⇒ el retry de la MISMA saga crea con el modelo A pinneado', $o['kind'] === Si4GlpiStage::O_BRIDGED
            && (int) ($pc->fields['computermodels_id'] ?? 0) === $modelA && (int) ($s['glpi_model_id'] ?? 0) === $modelA);

        // (3) una unidad NUEVA con la misma categoría usa el mapeo vigente (B).
        [$u2, $h2, $payload2] = $this->si4gSynthetic($this->si4E, 'SI4G-PIN-' . $this->suffix . '-2', $cat);
        $o = $this->si4gStage()->advance($u2, $h2, $payload2, 'st-g-pin');
        $s2 = (new DbSagaStore())->get($u2) ?? [];
        $pc2 = new Computer();
        $pc2->getFromDB((int) ($s2['glpi_items_id'] ?? 0));
        $this->si4gTrack('Computer', (int) $pc2->getID());
        $this->check('[SI4G-PIN] unidad nueva ⇒ modelo B del mapeo vigente', $o['kind'] === Si4GlpiStage::O_BRIDGED
            && (int) ($pc2->fields['computermodels_id'] ?? 0) === $modelB && (int) ($s2['glpi_model_id'] ?? 0) === $modelB);
        $this->check('[SI4G-PIN] 🔒 el pin es inmutable en la BD: re-pinnear ⇒ false y nada cambia', !(new DbSagaStore())->transition($u2, $h2, SagaState::BRIDGED,
            ['glpi_mapping_id' => $mapId, 'glpi_itemtype' => 'Monitor', 'glpi_model_id' => 0, 'glpi_mapping_hash' => str_repeat('a', 64)], 'st-repin')
            && (((new DbSagaStore())->get($u2) ?? [])['glpi_itemtype'] ?? '') === 'Computer' && (int) (((new DbSagaStore())->get($u2) ?? [])['glpi_model_id'] ?? 0) === $modelB);

        // (4) el modelo pinneado se elimina ⇒ MANUAL_REVIEW, sin alta y sin pasar en silencio al modelo del mapeo vigente.
        $cat3 = 'SI4G-PIN3-' . $this->suffix;
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $map3 = new MapGlpiAssetType();
        $map3Id = (int) $map3->add(['category_key' => $cat3, 'glpi_itemtype' => 'Computer', 'glpi_model_id' => $modelC, 'is_approved' => 1]);
        [$u3, $h3a, $payload3, $tag3] = $this->si4gSynthetic($this->si4E, 'SI4G-PIN-' . $this->suffix . '-3', $cat3);
        $this->si4gAsWorker([$this->si4E]);
        try {
            $this->si4gStage($crash)->advance($u3, $h3a, $payload3, 'st-g-pin');
        } catch (SimulatedCrash) {
        }
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $purged = (new \ComputerModel())->delete(['id' => $modelC], true) && !(new \ComputerModel())->getFromDB($modelC);
        $map3->update(['id' => $map3Id, 'glpi_model_id' => $modelB]);
        $h3 = $this->si4gTakeover($u3, 2);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u3, $h3, $payload3, 'st-g-pin');
        $s3 = (new DbSagaStore())->get($u3) ?? [];
        $this->check('[SI4G-PIN] 🔒 modelo pinneado eliminado ⇒ MANUAL_REVIEW (glpi_pin_invalid), sin alta ni cambio de modelo', $purged
            && $o['kind'] === Si4GlpiStage::O_MANUAL && $o['class'] === 'glpi_pin_invalid' && $this->si4gCount('Computer', $tag3) === 0
            && (int) ($s3['glpi_model_id'] ?? 0) === $modelC && ($s3['glpi_items_id'] ?? null) === null);
    }

    // ------------------------------------------------------------------ [SI4G-CLAIM-RACE]

    private function si4gClaimRace(): void
    {
        $this->out->writeln('== [SI4G-CLAIM-RACE] reclamo del GLPI Agent + otro candidato justo después ⇒ MANUAL_REVIEW ==');
        foreach (['serial' => 'mismo serial', 'tag' => 'mismo número de inventario'] as $variant => $label) {
            $serial = 'SI4G-RACE-' . $variant . '-' . $this->suffix;
            $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
            $agent = (int) (new Computer())->add(['name' => 'race-' . $this->suffix, 'entities_id' => $this->si4E, 'serial' => $serial, 'is_dynamic' => 1]);
            $this->si4gTrack('Computer', $agent);
            [$u, $h, $payload, $tag] = $this->si4gSynthetic($this->si4E, $serial, $this->si4gCategory);
            $this->si4gAsWorker([$this->si4E]);
            $other = 0;
            $o = $this->si4gStage(function (string $p) use ($variant, $serial, $tag, &$other): void {
                if ($p === 'after_glpi_claim') {
                    // Carrera determinista: justo después del reclamo aparece otro Computer (alta nativa).
                    $other = (int) (new Computer())->add(['name' => 'race2-' . $this->suffix, 'entities_id' => $this->si4E, 'is_dynamic' => 1]
                        + ($variant === 'serial' ? ['serial' => $serial] : ['otherserial' => $tag]));
                }
            })->advance($u, $h, $payload, 'st-g-race');
            $this->si4gTrack('Computer', $other);
            $s = (new DbSagaStore())->get($u) ?? [];
            $this->check("[SI4G-CLAIM-RACE] 🔒 {$label} tras el reclamo ⇒ AMBIGUOUS ⇒ MANUAL_REVIEW (glpi_claim_verify)", $other > 0
                && $o['kind'] === Si4GlpiStage::O_MANUAL && $o['class'] === 'glpi_claim_verify' && str_contains($o['why'], 'ambiguous'));
            $this->check("[SI4G-CLAIM-RACE] 🔒 {$label}: vínculo NO consolidado (sin glpi_items_id), sin Infocom ni puente", ($s['glpi_items_id'] ?? null) === null
                && ($s['state'] ?? '') === SagaState::SNIPE_CREATED
                && count((new \Infocom())->find(['itemtype' => 'Computer', 'items_id' => [$agent, $other]])) === 0
                && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => $u]) === 0);
        }
    }

    // ------------------------------------------------------------------ [SI4G-SUPPLIER]

    private function si4gSupplier(): void
    {
        $this->out->writeln('== [SI4G-SUPPLIER] el proveedor del Infocom debe seguir siendo aplicable a la entidad de la unidad ==');
        // (1) válido al comprar/recibir (misma entidad); se mueve a otra rama ANTES de SI4-2.
        $moved = $this->si4gMakeSupplier('SI4G-SUP-MV1-', $this->si4E, false);
        [$u, $h, $payload, $tag] = $this->si4gSynthetic($this->si4E, null, $this->si4gCategory);
        $payload['supplier_id'] = $moved;
        $okMove = $this->si4gMoveSupplier($moved, $this->si4E2);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u, $h, $payload, 'st-g-sup');
        $this->check('[SI4G-SUPPLIER] 🔒 proveedor movido a otra rama antes de SI4-2 ⇒ MANUAL_REVIEW (infocom_supplier), sin activo, Infocom ni puente', $okMove
            && $o['kind'] === Si4GlpiStage::O_MANUAL && $o['class'] === 'infocom_supplier' && $this->si4gCount('Computer', $tag) === 0
            && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => $u]) === 0);

        // (2) se mueve DESPUÉS del activo y antes del Infocom (bloqueado por ACL) ⇒ al reanudar no escribe el Infocom.
        $moved2 = $this->si4gMakeSupplier('SI4G-SUP-MV2-', $this->si4E, false);
        [$u2, $h2, $payload2, $tag2] = $this->si4gSynthetic($this->si4E, 'SI4G-SUP-' . $this->suffix . '-2', $this->si4gCategory);
        $payload2['supplier_id'] = $moved2;
        $this->si4gAsWorker([$this->si4E], ['infocom' => READ]);
        $o1 = $this->si4gStage()->advance($u2, $h2, $payload2, 'st-g-sup');
        $s2 = (new DbSagaStore())->get($u2) ?? [];
        $this->si4gTrack('Computer', (int) ($s2['glpi_items_id'] ?? 0));
        $okMove2 = $this->si4gMoveSupplier($moved2, $this->si4E2);
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u2, $h2, $payload2, 'st-g-sup');
        $this->check('[SI4G-SUPPLIER] 🔒 proveedor movido entre el activo y el Infocom ⇒ MANUAL_REVIEW, sin Infocom ni puente', $okMove2
            && $o1['kind'] === Si4GlpiStage::O_BLOCKED && $this->si4gCount('Computer', $tag2) === 1
            && $o['kind'] === Si4GlpiStage::O_MANUAL && $o['class'] === 'infocom_supplier'
            && count((new \Infocom())->find(['itemtype' => 'Computer', 'items_id' => (int) ($s2['glpi_items_id'] ?? 0)])) === 0
            && countElementsInTable(AssetBridge::getTable(), ['receipt_unit_uuid' => $u2]) === 0);

        // (3) positivo: proveedor RECURSIVO de un ancestro (raíz) ⇒ permitido.
        $rec = $this->si4gMakeSupplier('SI4G-SUP-REC-', 0, true);
        [$u3, $h3, $payload3] = $this->si4gSynthetic($this->si4E, 'SI4G-SUP-' . $this->suffix . '-3', $this->si4gCategory);
        $payload3['supplier_id'] = $rec;
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u3, $h3, $payload3, 'st-g-sup');
        $s3 = (new DbSagaStore())->get($u3) ?? [];
        $this->si4gTrack('Computer', (int) ($s3['glpi_items_id'] ?? 0));
        $ic = new \Infocom();
        $this->check('[SI4G-SUPPLIER] proveedor recursivo de un ancestro ⇒ permitido (Infocom con ese proveedor)', $o['kind'] === Si4GlpiStage::O_BRIDGED
            && $ic->getFromDBforDevice('Computer', (int) ($s3['glpi_items_id'] ?? 0)) && (int) $ic->fields['suppliers_id'] === $rec);

        // (4) negativo: proveedor de un ancestro SIN recursividad ⇒ MANUAL_REVIEW.
        $flat = $this->si4gMakeSupplier('SI4G-SUP-FLAT-', 0, false);
        [$u4, $h4, $payload4, $tag4] = $this->si4gSynthetic($this->si4E, null, $this->si4gCategory);
        $payload4['supplier_id'] = $flat;
        $this->si4gAsWorker([$this->si4E]);
        $o = $this->si4gStage()->advance($u4, $h4, $payload4, 'st-g-sup');
        $this->check('[SI4G-SUPPLIER] 🔒 proveedor de un ancestro NO recursivo ⇒ MANUAL_REVIEW, sin activo', $o['kind'] === Si4GlpiStage::O_MANUAL
            && $o['class'] === 'infocom_supplier' && $this->si4gCount('Computer', $tag4) === 0);
    }

    /** Proveedor fixture con el `is_recursive` EXACTO pedido (se corrige si `add()` no lo honra). */
    private function si4gMakeSupplier(string $prefix, int $entity, bool $recursive): int
    {
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $want = $recursive ? 1 : 0;
        $sup = new \Supplier();
        $id = (int) $sup->add(['name' => $prefix . $this->suffix, 'entities_id' => $entity, 'is_recursive' => $want]);
        if ($id > 0 && $sup->getFromDB($id) && (int) ($sup->fields['is_recursive'] ?? 0) !== $want) {
            $sup->update(['id' => $id, 'is_recursive' => $want]);
        }
        $this->si4gSuppliers[] = $id;
        return $id;
    }

    /** Mueve un proveedor a otra entidad con la API nativa y confirma el cambio releyendo. */
    private function si4gMoveSupplier(int $id, int $entity): bool
    {
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $sup = new \Supplier();
        $sup->update(['id' => $id, 'entities_id' => $entity]);
        return $sup->getFromDB($id) && (int) $sup->fields['entities_id'] === $entity;
    }

    // ------------------------------------------------------------------ [SI4G-NO-SIDE-EFFECTS]

    private function si4gNoSideEffects(): void
    {
        $this->out->writeln('== [SI4G-NO-SIDE-EFFECTS] sin companyqr, sin ack, Snipe sólo hardware/statuslabels ==');
        $noQr = true;
        foreach ($this->si4gItems as [$type, $id]) {
            $item = new $type();
            if ($id > 0 && $item->getFromDB($id)) {
                $noQr = $noQr && $this->si4gQrCode($item) === null;
            }
        }
        $this->check('[SI4G-NO-SIDE-EFFECTS] 🔒 ningún código companyqr para los activos de SI4-2', $noQr);
        $done = 0;
        $api = new PurchasingIntegrationApi();
        foreach ($this->si4gUuids as $u) {
            $done += ($api->getHandoff($u)['status'] ?? '') === 'DONE' ? 1 : 0;
        }
        $this->check('[SI4G-NO-SIDE-EFFECTS] 🔒 ninguna fila del outbox en DONE (sin acknowledgeProcessed)', $done === 0 && count($this->si4gUuids) >= 5);
        $this->check('[SI4G-NO-SIDE-EFFECTS] Snipe sólo recibió GET/POST de hardware y statuslabels', array_filter($this->si4Snipe->requests,
            static fn (array $r): bool => !preg_match('#/api/v1/(hardware|statuslabels)#', (string) parse_url($r['url'], PHP_URL_PATH))) === []);
    }

    // ------------------------------------------------------------------ helpers

    /** Worker SI4-2 cableado EXACTAMENTE como el comando real (`Si4RunCommand::buildWorker`). */
    private function si4gWorker(string $workerId): Si4Worker
    {
        $cfg = Si4Config::fromArray([
            'si4_enabled' => '1', 'si4_asset_tag_prefix' => 'ST4-', 'si4_snipe_status_id' => '5', 'si4_lease_seconds' => '900',
            'si4_max_units_per_run' => '50', 'si4_retry_base_seconds' => '60', 'si4_retry_max_seconds' => '3600',
            'si4_config_retry_seconds' => '3600', 'si4_auth_retry_seconds' => '900', 'si4_uncertain_cooldown_seconds' => '300',
            'si4_worker_id' => $workerId, 'si4_glpi_infocom_currency' => 'PYG',
        ]);
        $logs = &$this->si4Logs;
        $logger = static function (string $l, string $m, array $c) use (&$logs): void {
            $logs[] = $l . ' ' . $m . ' ' . json_encode($c);
        };
        return Si4RunCommand::buildWorker($cfg, $this->si4Writer($logger), 5000, 1, $logger);
    }

    /** @param callable(string,string):void|null $probe */
    private function si4gStage(?callable $probe = null): Si4GlpiStage
    {
        return new Si4GlpiStage(new DbSagaStore(), new CoreGlpiAssetGateway(), new DbGlpiMappingResolver(), new DbBridgeStore(), 'PYG', $probe);
    }

    /**
     * Sesión del usuario técnico: los dos bits de SI4-1 + derechos NATIVOS de alta de activos e Infocom (mínimo
     * privilegio: READ/CREATE/UPDATE, sin DELETE/PURGE). `$override` reemplaza derechos puntuales.
     *
     * @param array<int> $entities @param array<string,int> $override
     */
    private function si4gAsWorker(array $entities, array $override = []): void
    {
        $rights = [
            'plugin_companyintegrations' => AssetBridge::RIGHT_SI4,
            'plugin_companypurchasing'   => PurchaseRequest::RIGHT_INTEGRATION,
            \Infocom::$rightname         => READ | CREATE | UPDATE,
        ];
        foreach (CoreGlpiAssetGateway::SUPPORTED_ITEMTYPES as $type) {
            $rights[$type::$rightname] = READ | CREATE | UPDATE;
        }
        $this->applySession(2, $entities, $override + $rights);
        unset($_SESSION['glpicronuserrunning']);
    }

    /**
     * Saga SINTÉTICA en SNIPE_CREATED (sin outbox): prueba la etapa GLPI real con cualquier entidad/itemtype.
     *
     * @return array{0:string,1:string,2:array<string,mixed>,3:string} [uuid, sha256(token), payload, tag]
     */
    private function si4gSynthetic(int $entity, ?string $serial, string $category, int $leaseSeconds = 600): array
    {
        $u = $this->si4Uuid();
        $h = hash('sha256', 'st-g-' . bin2hex(random_bytes(8)));
        $store = new DbSagaStore();
        $store->acquire($u, ['entities_id' => $entity, 'requests_id' => $this->si4Req, 'items_id' => $this->si4Line,
            'payload_sha256' => str_repeat('b', 64), 'correlation_id' => 'st-g'], $h, $this->si4DbTime($leaseSeconds), 1, 'st-g-A');
        $tag = AssetTagDeriver::tagFor('ST4-', $u);
        $this->si4gSnipeSeq++;
        $store->transition($u, $h, SagaState::PENDING, ['state' => SagaState::SNIPE_CREATED, 'snipe_asset_id' => $this->si4gSnipeSeq,
            'snipe_asset_tag' => $tag, 'snipe_outcome' => SagaState::OUTCOME_CREATED, 'snipe_company_id' => self::SI4_CO1, 'snipe_model_id' => 31,
            'snipe_status_id' => 5, 'remote_create_calls' => 1], 'st');
        $payload = ['schema_version' => 1, 'receipt_unit_uuid' => $u, 'request_id' => $this->si4Req, 'request_number' => 'SC-ST-G-' . $this->suffix,
            'item_id' => $this->si4Line, 'entity_id' => $entity, 'serial' => $serial, 'description' => 'SI4-2 selftest', 'category' => $category,
            'supplier_id' => $entity === $this->si4E2 ? $this->si4gSupplierE2 : (int) $this->si4Suppliers[0], 'currency' => 'PYG', 'currency_scale' => 0, 'unit_cost' => '2500',
            'received_at' => date('c'), 'correlation_id' => 'st-g'];
        return [$u, $h, $payload, $tag];
    }

    /** Toma la saga con una época MAYOR (nuevo dueño) y devuelve la huella de su token. */
    private function si4gTakeover(string $u, int $epoch, int $leaseSeconds = 600): string
    {
        $h = hash('sha256', 'st-g-take-' . bin2hex(random_bytes(8)));
        (new DbSagaStore())->acquire($u, ['entities_id' => 0, 'requests_id' => 0, 'items_id' => 0, 'payload_sha256' => '', 'correlation_id' => ''],
            $h, $this->si4DbTime($leaseSeconds), $epoch, 'st-g-B');
        return $h;
    }

    /** Serial que `si4Receive(1)` asignará a la PRÓXIMA unidad. */
    private function si4gNextSerial(): string
    {
        return 'SI4-' . $this->suffix . '-' . ($this->si4Batch + 1) . '-1';
    }

    /** Activos NO plantilla del itemtype con ese número de inventario (API soportada `find()`). */
    private function si4gCount(string $itemtype, string $otherserial): int
    {
        return count((new $itemtype())->find(['otherserial' => $otherserial, 'is_template' => 0]));
    }

    /** @param array<int,int> $ids */
    private function si4gItemFp(string $itemtype, array $ids): string
    {
        $acc = '';
        foreach ($ids as $id) {
            $item = new $itemtype();
            $item->getFromDB($id);
            $acc .= json_encode(array_diff_key($item->fields, ['date_mod' => 1]));
        }
        return hash('sha256', $acc . json_encode((new \Lockedfield())->find(['itemtype' => $itemtype, 'items_id' => $ids])));
    }

    /** @param array<int,string> $cols */
    private function si4gFp(string $table, array $cols): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $acc = '';
        foreach ($DB->request(['SELECT' => $cols, 'FROM' => 'glpi_plugin_companyintegrations_' . $table, 'ORDER' => 'id ASC']) as $r) {
            $acc .= json_encode($r);
        }
        return hash('sha256', $acc);
    }

    private function si4gRights(): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $acc = '';
        foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['name' => AssetBridge::$rightname], 'ORDER' => 'id ASC']) as $r) {
            $acc .= $r['profiles_id'] . ':' . $r['rights'] . ';';
        }
        return $acc;
    }

    private function si4gQrCode(\CommonDBTM $item): mixed
    {
        $cls = '\\GlpiPlugin\\Companyqr\\Service\\CodeManager';
        return class_exists($cls) ? (new $cls())->findForItem($item) : null;
    }

    private function si4gTrack(string $itemtype, int $id): void
    {
        if ($id > 0) {
            $this->si4gItems[] = [$itemtype, $id];
        }
    }

    private function si4gCleanup(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            // Todo activo GLPI ligado a una saga (incluidos los de unidades drenadas) se purga junto con los rastreados.
            foreach ($DB->request(['SELECT' => ['glpi_itemtype', 'glpi_items_id'], 'FROM' => Si4Saga::getTable(),
                'WHERE' => ['NOT' => ['glpi_items_id' => null]]]) as $r) {
                if (in_array((string) $r['glpi_itemtype'], CoreGlpiAssetGateway::SUPPORTED_ITEMTYPES, true)) {
                    $this->si4gTrack((string) $r['glpi_itemtype'], (int) $r['glpi_items_id']);
                }
            }
            $this->si4gItems = array_values(array_unique($this->si4gItems, SORT_REGULAR));
            $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
            $rights = ['infocom' => ALLSTANDARDRIGHT];
            foreach (CoreGlpiAssetGateway::SUPPORTED_ITEMTYPES as $type) {
                $rights[$type::$rightname] = ALLSTANDARDRIGHT;
            }
            $this->applySession(2, [0, $this->si4E, $this->si4E2], $rights, 1);
            foreach ($this->si4gItems as [$type, $id]) {
                foreach ((new \Infocom())->find(['itemtype' => $type, 'items_id' => $id]) as $ic) {
                    (new \Infocom())->delete(['id' => (int) $ic['id']], true);
                }
                (new $type())->delete(['id' => $id], true);
            }
            foreach (array_merge([$this->si4gModel], $this->si4gModels) as $model) {
                if ($model > 0 && (new \ComputerModel())->getFromDB($model)) {
                    (new \ComputerModel())->delete(['id' => $model], true);
                }
            }
            foreach (array_merge([$this->si4gSupplierE2], $this->si4gSuppliers) as $sup) {
                if ($sup > 0) {
                    (new \Supplier())->delete(['id' => $sup], true);
                }
            }
        } catch (\Throwable) {
            // best-effort; el stack de CI es efímero.
        }
    }
}
