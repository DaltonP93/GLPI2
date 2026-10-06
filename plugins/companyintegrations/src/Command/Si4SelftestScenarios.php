<?php

/**
 * Escenarios de INTEGRACIÓN + E2E de SI4-1 (ADR-0020) dentro de GLPI, usados por `plugins:companyintegrations:selftest`.
 *
 *   [SI4-PERSIST]  tablas, columnas de fencing, derecho RIGHT_SI4 y configuración sembrada (deshabilitado).
 *   [SI4-UPGRADE]  0.2.0 (SI-1 con datos y config ajustada) → install() ×2: SI-1 intacto, config NO pisada, bits
 *                  sumados, sin filas duplicadas de derechos.
 *   [SI4-FENCE]    DbSagaStore con el reloj REAL de la BD: token, época monótona, presupuesto de escritura, lease
 *                  vencido, UNIQUE(snipe_asset_id), bitácora append-only.
 *   [SI4-E2E]      Compras REAL (solicitud → aprobaciones → compra → recepción) ⇒ outbox ⇒ worker por
 *                  `PurchasingIntegrationApi` ⇒ Snipe fake ⇒ saga SNIPE_CREATED ⇒ outbox SIN ack.
 *   [SI4-CRASH]    worker A crea en Snipe y muere antes de persistir; su lease (2 s) vence ⇒ A no puede escribir la
 *                  saga ni finalizar el outbox; worker B toma la saga (época 2) y RECONCILIA sin un segundo POST.
 *   [SI4-MAPPING]  mapeo de modelo no aprobado ⇒ BLOCKED_CONFIG + outbox RETRY (sin POST).
 *   [SI4-IDENTITY] tag determinista preexistente (misma compañía, otro modelo, sin marca) ⇒ MANUAL_REVIEW sin POST.
 *   [SI4-SECRETS]  error de transporte con el header Authorization ⇒ token ausente de saga, bitácora y outbox.
 *   [SI4-MULTI-ENT] / [SI4-ACL]  el worker de otra entidad no toma nada; sin RIGHT_SI4 / RIGHT_INTEGRATION ⇒ fail-closed.
 *   [SI4-CMD]      comando real `si4-run` con usuario técnico REAL (Session::init): deshabilitado ⇒ nada; Snipe
 *                  inalcanzable ⇒ preflight aborta sin reclamar; sin token / sin derecho ⇒ exit 1.
 *   [SI4-NO-SIDE-EFFECTS]  sin activos GLPI, sin Infocom, sin puentes: SI4-1 sólo toca Snipe (fake) y tablas propias.
 *
 * Los datos de Compras se crean y se leen SÓLO con sus servicios/API públicos (nunca SQL contra sus tablas).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Command;

use Computer;
use Entity;
use GlpiPlugin\Companyintegrations\Client\FakeSnipeServer;
use GlpiPlugin\Companyintegrations\Client\SnipeAssetWriter;
use GlpiPlugin\Companyintegrations\Client\SnipeClientConfig;
use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Model\MapCompany;
use GlpiPlugin\Companyintegrations\Model\MapModel;
use GlpiPlugin\Companyintegrations\Model\Si4Saga;
use GlpiPlugin\Companyintegrations\Model\Si4SagaLog;
use GlpiPlugin\Companyintegrations\Service\PluginConfig as IntegrationsConfig;
use GlpiPlugin\Companyintegrations\Si4\AssetTagDeriver;
use GlpiPlugin\Companyintegrations\Si4\DbMappingResolver;
use GlpiPlugin\Companyintegrations\Si4\DbSagaStore;
use GlpiPlugin\Companyintegrations\Si4\PurchasingHandoffSource;
use GlpiPlugin\Companyintegrations\Si4\SagaState;
use GlpiPlugin\Companyintegrations\Si4\Si4Config;
use GlpiPlugin\Companyintegrations\Si4\Si4Worker;
use GlpiPlugin\Companyintegrations\Si4\WorkerSession;
use GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi;
use GlpiPlugin\Companypurchasing\Model\Request as PurchaseRequest;
use GlpiPlugin\Companypurchasing\Service\ApprovalOrchestrator;
use GlpiPlugin\Companypurchasing\Service\PluginConfig as PurchasingConfig;
use GlpiPlugin\Companypurchasing\Service\ReceivingService;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use GlpiPlugin\Companypurchasing\Service\WorkflowGateway;
use GlpiPlugin\Companyqr\Model\Code as QrCode;

trait Si4SelftestScenarios
{
    private const SI4_TOKEN = 'si4-selftest-token-NEVER-LOGGED-9f8e7d';
    /** Compañías Snipe (fake) de la fixture: ids propios para no chocar con los escenarios SI-1. */
    private const SI4_CO1 = 88801;
    private const SI4_CO2 = 88802;
    private const SI4_CONFIG_KEYS = [
        'si4_enabled', 'si4_asset_tag_prefix', 'si4_snipe_status_id', 'si4_lease_seconds', 'si4_max_units_per_run',
        'si4_retry_base_seconds', 'si4_retry_max_seconds', 'si4_config_retry_seconds', 'si4_auth_retry_seconds',
        'si4_uncertain_cooldown_seconds', 'si4_worker_id',
    ];
    private const SI4_PURCHASING_KEYS = [
        'workflow_code', 'approver_group_area_head', 'approver_group_purchasing', 'approver_group_finance',
        'quorum_area_head', 'quorum_purchasing', 'quorum_finance', 'sync_on_workflow_events',
    ];

    private int $si4E = 0;
    private int $si4E2 = 0;
    private int $si4Owner = 0;
    private int $si4Head = 0;
    private int $si4Buyer = 0;
    private int $si4Fin = 0;
    private int $si4Req = 0;
    private int $si4Line = 0;
    private int $si4Batch = 0;
    private string $si4Category = '';
    /** @var array<string,mixed> */
    private array $si4SavedPurchasing = [];
    /** @var array<string,mixed> */
    private array $si4SavedIntegrations = [];
    /** @var array<int,int> */
    private array $si4Users = [];
    /** @var array<int,int> */
    private array $si4Groups = [];
    /** @var array<int,int> */
    private array $si4Suppliers = [];
    private ?FakeSnipeServer $si4Snipe = null;
    /** @var array<int,string> */
    private array $si4Logs = [];

    private function runSi4Scenarios(): void
    {
        $this->si4Persist();
        $this->si4Upgrade();
        $this->si4Fence();
        if (!class_exists(PurchasingIntegrationApi::class)) {
            $this->check('[SI4-E2E] companypurchasing (PurchasingIntegrationApi) disponible', false);
            return;
        }
        $before = $this->si4SideEffectCounts();
        try {
            $this->si4Fixture();
            $this->si4E2e();
            $this->si4Crash();
            $this->si4Mapping();
            $this->si4Identity();
            $this->si4Secrets();
            $this->si4Acl();
            $this->si4Command();
        } catch (\Throwable $e) {
            $this->check('[SI4-E2E] sin excepciones: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
        } finally {
            $this->si4NoSideEffects($before);
            $this->si4Restore();
        }
    }

    // ------------------------------------------------------------------ [SI4-PERSIST]

    private function si4Persist(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [SI4-PERSIST] tablas SI4-1, derecho y configuración ==');
        foreach (['si4_sagas', 'si4_saga_log', 'map_models'] as $t) {
            $this->check("[SI4-PERSIST] tabla glpi_plugin_companyintegrations_{$t} existe", $DB->tableExists("glpi_plugin_companyintegrations_{$t}"));
        }
        foreach (['lease_token_sha256', 'lease_until', 'lease_epoch', 'row_version', 'snipe_asset_id'] as $c) {
            $this->check("[SI4-PERSIST] columna de fencing/vínculo {$c}", $DB->fieldExists(Si4Saga::getTable(), $c));
        }
        $rights = 0;
        foreach ($DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => 4, 'name' => AssetBridge::$rightname]]) as $r) {
            $rights = (int) $r['rights'];
        }
        $this->check('[SI4-PERSIST] Super-Admin tiene RIGHT_SI4', ($rights & AssetBridge::RIGHT_SI4) === AssetBridge::RIGHT_SI4);
        $this->check('[SI4-PERSIST] worker deshabilitado por defecto (si4_enabled = 0)', (string) IntegrationsConfig::get('si4_enabled') === '0');
        $this->check('[SI4-PERSIST] status Snipe sin valor literal por defecto (0 ⇒ debe configurarse)', (string) IntegrationsConfig::get('si4_snipe_status_id') === '0');
    }

    // ------------------------------------------------------------------ [SI4-UPGRADE]

    private function si4Upgrade(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [SI4-UPGRADE] 0.2.0 (SI-1 con datos) → install() ×2 (0.3.0) ==');
        $this->applySession(2, [0], ['config' => ALLSTANDARDRIGHT], 1);
        $hook = dirname(__DIR__, 2) . '/hook.php';
        if (!function_exists('plugin_companyintegrations_install')) {
            include_once $hook;
        }
        $saved = \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
        $p = 'glpi_plugin_companyintegrations_';
        $superOrig = -1;
        foreach ($DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => 4, 'name' => AssetBridge::$rightname]]) as $r) {
            $superOrig = (int) $r['rights'];
        }
        try {
            // (1) Estado 0.2.0: sin tablas/config SI-4, derechos de SI-1, config ajustada por un admin + datos SI-1.
            foreach (['si4_saga_log', 'si4_sagas', 'map_models'] as $t) {
                $DB->doQuery("DROP TABLE IF EXISTS `{$p}{$t}`");
            }
            // Un upgrade real corre en un proceso NUEVO: sin la caché de tablas de este proceso (API soportada).
            $DB->clearSchemaCache();
            \Config::deleteConfigurationValues(IntegrationsConfig::CONTEXT, self::SI4_CONFIG_KEYS);
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, ['snipe_base_url' => 'https://snipe.keep.example', 'match_itemtypes' => 'Computer,Monitor']);
            $si1Bits = READ | AssetBridge::RIGHT_RECONCILE | AssetBridge::RIGHT_MAP | AssetBridge::RIGHT_CONFIG;
            $DB->update('glpi_profilerights', ['rights' => $si1Bits], ['profiles_id' => 4, 'name' => AssetBridge::$rightname]);
            $bridge = (int) (new AssetBridge())->add(['snipe_asset_id' => 990001, 'snipe_asset_tag' => 'UPG-' . $this->suffix, 'glpi_itemtype' => 'Computer',
                'glpi_items_id' => 990001, 'glpi_entity_id' => 0, 'sync_status' => AssetBridge::STATUS_MATCHED]);
            $company = (int) (new MapCompany())->add(['snipe_company_id' => 990001, 'snipe_name' => 'UPG', 'glpi_entity_id' => 0, 'is_approved' => 1]);
            $fp = $this->si4Fingerprint(['asset_bridge', 'asset_tag_aliases', 'map_companies', 'map_users', 'recon']);
            $rightsRows = countElementsInTable('glpi_profilerights', ['name' => AssetBridge::$rightname]);

            // (2) Upgrade: install() dos veces (GLPI lo re-ejecuta al actualizar).
            $ok = true;
            try {
                plugin_companyintegrations_install();
                plugin_companyintegrations_install();
            } catch (\Throwable $e) {
                $ok = false;
                $this->out->writeln('    ' . $e->getMessage());
            }
            $this->check('[SI4-UPGRADE] install() ×2 sobre 0.2.0 no falla (sin Duplicate entry del derecho)', $ok);
            $DB->clearSchemaCache();
            foreach (['si4_sagas', 'si4_saga_log', 'map_models'] as $t) {
                $this->check("[SI4-UPGRADE] tabla {$t} creada", $DB->tableExists("{$p}{$t}", false));
            }
            $this->check('[SI4-UPGRADE] datos SI-1 intactos (huella de las 5 tablas)', $fp === $this->si4Fingerprint(['asset_bridge', 'asset_tag_aliases', 'map_companies', 'map_users', 'recon'])
                && $bridge > 0 && $company > 0);
            $conf = \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
            $this->check('[SI4-UPGRADE] config ajustada por el admin NO se pisa', ($conf['snipe_base_url'] ?? '') === 'https://snipe.keep.example' && ($conf['match_itemtypes'] ?? '') === 'Computer,Monitor');
            $this->check('[SI4-UPGRADE] claves SI-4 sembradas (deshabilitado)', ($conf['si4_enabled'] ?? null) === '0' && ($conf['si4_asset_tag_prefix'] ?? '') === 'GP2-');
            $rights = 0;
            foreach ($DB->request(['SELECT' => ['rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => 4, 'name' => AssetBridge::$rightname]]) as $r) {
                $rights = (int) $r['rights'];
            }
            // 0.6.1: un upgrade NO toca derechos existentes, tampoco suma RIGHT_SI4 a Super-Admin (el administrador decide).
            $this->check('[SI4-UPGRADE] Super-Admin conserva EXACTAMENTE los bits SI-1 (RIGHT_SI4 no se inyecta en un perfil ya administrado)', $rights === $si1Bits);
            $this->check('[SI4-UPGRADE] sin filas duplicadas del derecho', countElementsInTable('glpi_profilerights', ['name' => AssetBridge::$rightname]) === $rightsRows);
            (new AssetBridge())->delete(['id' => $bridge], true);
            (new MapCompany())->delete(['id' => $company], true);
        } finally {
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, is_array($saved) ? $saved : []);
            if ($superOrig >= 0) {
                $DB->update('glpi_profilerights', ['rights' => $superOrig], ['profiles_id' => 4, 'name' => AssetBridge::$rightname]);
            }
        }
    }

    // ------------------------------------------------------------------ [SI4-FENCE]

    private function si4Fence(): void
    {
        $this->out->writeln('== [SI4-FENCE] DbSagaStore con el reloj real de la BD ==');
        $store = new DbSagaStore();
        $u1 = $this->si4Uuid();
        $u2 = $this->si4Uuid();
        $u3 = $this->si4Uuid();
        $meta = ['entities_id' => 0, 'requests_id' => 1, 'items_id' => 1, 'payload_sha256' => str_repeat('a', 64), 'correlation_id' => 'st'];
        $h1 = hash('sha256', 'tok-1');
        $h2 = hash('sha256', 'tok-2');
        $h3 = hash('sha256', 'tok-3');
        $row = $store->acquire($u1, $meta, $h1, $this->si4DbTime(60), 1, 'st-1');
        $this->check('[SI4-FENCE] acquire crea la saga en PENDING (época 1)', ($row['state'] ?? '') === SagaState::PENDING && (int) $row['lease_epoch'] === 1);
        $this->check('[SI4-FENCE] el dueño escribe', $store->transition($u1, $h1, SagaState::PENDING, ['state' => SagaState::SNIPE_CREATING], 'st'));
        $this->check('[SI4-FENCE] escribir lo mismo otra vez también (row_version siempre cambia)', $store->transition($u1, $h1, SagaState::SNIPE_CREATING, ['state' => SagaState::SNIPE_CREATING], 'st'));
        $this->check('[SI4-FENCE] 🔒 otro token NO escribe', !$store->transition($u1, $h2, SagaState::SNIPE_CREATING, ['last_error' => 'x'], 'st'));
        $this->check('[SI4-FENCE] 🔒 presupuesto de escritura mayor que el lease restante ⇒ no escribe', !$store->transition($u1, $h1, SagaState::SNIPE_CREATING, ['last_error' => 'x'], 'st', '', 3600));
        $this->check('[SI4-FENCE] 🔒 claim con época NO mayor no toma la saga', $store->acquire($u1, $meta, $h2, $this->si4DbTime(60), 1, 'st-2') === null);
        $row = $store->acquire($u1, $meta, $h3, $this->si4DbTime(60), 2, 'st-3');
        $this->check('[SI4-FENCE] época mayor TOMA la saga (misma fila)', (int) ($row['lease_epoch'] ?? 0) === 2 && ($row['worker_id'] ?? '') === 'st-3' && (int) $row['attempts'] === 2);
        $this->check('[SI4-FENCE] 🔒 el dueño anterior ya no escribe', !$store->transition($u1, $h1, SagaState::SNIPE_CREATING, ['last_error' => 'x'], 'st'));
        $this->check('[SI4-FENCE] el nuevo dueño escribe', $store->transition($u1, $h3, SagaState::SNIPE_CREATING, ['snipe_asset_id' => 424242, 'state' => SagaState::SNIPE_CREATED], 'st'));
        $store->acquire($u2, $meta, $h2, $this->si4DbTime(-10), 1, 'st-exp');
        $this->check('[SI4-FENCE] 🔒 lease vencido (reloj de la BD) ⇒ no escribe', !$store->transition($u2, $h2, SagaState::PENDING, ['last_error' => 'x'], 'st'));
        $store->acquire($u3, $meta, $h3, $this->si4DbTime(60), 1, 'st-4');
        $threw = false;
        try {
            $store->transition($u3, $h3, SagaState::PENDING, ['snipe_asset_id' => 424242], 'st');
        } catch (\RuntimeException) {
            $threw = true;
        }
        $this->check('[SI4-FENCE] 🔒 UNIQUE(snipe_asset_id): un activo remoto no se liga a dos unidades', $threw);
        $this->check('[SI4-FENCE] bitácora append-only (acquire, transiciones, takeover)', countElementsInTable(Si4SagaLog::getTable(), ['receipt_unit_uuid' => $u1]) >= 5
            && countElementsInTable(Si4SagaLog::getTable(), ['receipt_unit_uuid' => $u1, 'event' => 'takeover']) === 1);
        $this->check('[SI4-FENCE] una sola saga por unidad', countElementsInTable(Si4Saga::getTable(), ['receipt_unit_uuid' => $u1]) === 1);
    }

    // ------------------------------------------------------------------ fixture de Compras (servicios públicos)

    private function si4Fixture(): void
    {
        $this->out->writeln('== [SI4-E2E] fixture: solicitud real de Compras hasta la recepción ==');
        $this->si4AsAdmin([0]);
        $this->si4E  = (int) (new Entity())->add(['name' => 'SI4-E1-' . $this->suffix, 'entities_id' => 0]);
        $this->si4E2 = (int) (new Entity())->add(['name' => 'SI4-E2-' . $this->suffix, 'entities_id' => 0]);
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        foreach (self::SI4_PURCHASING_KEYS as $k) {
            $this->si4SavedPurchasing[$k] = PurchasingConfig::get($k);
        }
        foreach (self::SI4_CONFIG_KEYS as $k) {
            $this->si4SavedIntegrations[$k] = IntegrationsConfig::get($k);
        }
        $this->si4Owner = $this->si4Actor('owner');
        $this->si4Head  = $this->si4Actor('head');
        $this->si4Buyer = $this->si4Actor('buyer');
        $this->si4Fin   = $this->si4Actor('fin');
        $gHead = $this->si4Group('head');
        $gBuy  = $this->si4Group('buy');
        $gFin  = $this->si4Group('fin');
        foreach ([[$gHead, $this->si4Head], [$gBuy, $this->si4Buyer], [$gFin, $this->si4Fin]] as [$g, $u]) {
            (new \Group_User())->add(['groups_id' => $g, 'users_id' => $u]);
        }
        $sup = (int) (new \Supplier())->add(['name' => 'SI4-SUP-' . $this->suffix, 'entities_id' => $this->si4E, 'is_recursive' => 0]);
        $this->si4Suppliers[] = $sup;
        \Config::setConfigurationValues(PurchasingConfig::CONTEXT, [
            'workflow_code' => 'si4_st_' . $this->suffix, 'approver_group_area_head' => (string) $gHead,
            'approver_group_purchasing' => (string) $gBuy, 'approver_group_finance' => (string) $gFin,
            'quorum_area_head' => '1', 'quorum_purchasing' => '1', 'quorum_finance' => '1', 'sync_on_workflow_events' => '1',
        ]);
        (new ApprovalOrchestrator())->publishDefinition();

        // Solicitud: 1 línea inventariable de 90 unidades (SI4-1 recibe en lotes 3 + 1 + 1 + 1 + 1 + 1; SI4-2 recibe
        // otras 6 en [SI4G-E2E]/[SI4G-AGENT]/[SI4G-AMBIGUOUS]/[SI4G-OTHER-ENT]/[SI4G-MAPPING]; SI4-3 ~38 en [SI4Q-*]).
        $this->si4Category = 'SI4-NB-' . $this->suffix;
        $this->si4AsPurchasing($this->si4Owner, READ | PurchaseRequest::RIGHT_CREATE_REQUEST | PurchaseRequest::RIGHT_VIEW_OWN | PurchaseRequest::RIGHT_EDIT_DRAFT, READ);
        $rm = new RequestManager();
        $this->si4Req = $rm->createDraft(['entities_id' => $this->si4E, 'reason' => 'si4-' . $this->suffix, 'category' => 'IT', 'currency_code' => 'PYG']);
        $this->si4Line = (int) $rm->addLine($this->si4Req, ['description' => 'Notebook SI4', 'quantity' => '90', 'estimated_unit_price' => '1000',
            'is_inventoriable' => 1, 'category' => $this->si4Category]);
        $orch = new ApprovalOrchestrator();
        $orch->submit($this->si4Req, 'envío si4');
        $this->si4Decide($this->si4Head, false);
        $this->si4AsPurchasing($this->si4Buyer, READ | PurchaseRequest::RIGHT_VIEW_ENTITY | PurchaseRequest::RIGHT_MANAGE_PURCHASING, READ | 2);
        $lineIds = array_map(static fn ($it): int => (int) $it->getID(), $rm->loadItems($this->si4Req));
        $this->si4Line = $lineIds[0] ?? $this->si4Line;
        $q = $orch->createQuote($this->si4Req, ['suppliers_id' => $sup, 'reference' => 'Q-SI4-' . $this->suffix, 'discounts' => '0', 'taxes' => '0',
            'freight' => '0', 'lines' => [['items_id' => $this->si4Line, 'final_unit_price' => '2500']]]);
        $orch->selectQuote($this->si4Req, $q);
        $this->si4Decide($this->si4Buyer, true);
        $this->si4Decide($this->si4Fin, false);
        $this->si4AsPurchasing($this->si4Buyer, READ | PurchaseRequest::RIGHT_VIEW_ENTITY | PurchaseRequest::RIGHT_MANAGE_PURCHASING, READ | 2);
        (new ReceivingService())->startPurchase($this->si4Req, 'inicio si4');
        $this->check('[SI4-E2E] fixture: compra iniciada por los servicios públicos de Compras', $this->si4Req > 0 && $this->si4Line > 0);

        // Mapeos validados (tablas propias) y Snipe fake con el contrato real v8.7.2.
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        (new MapCompany())->add(['snipe_company_id' => self::SI4_CO1, 'snipe_name' => 'SI4 Co E1', 'glpi_entity_id' => $this->si4E, 'is_approved' => 1]);
        (new MapCompany())->add(['snipe_company_id' => self::SI4_CO2, 'snipe_name' => 'SI4 Co E2', 'glpi_entity_id' => $this->si4E2, 'is_approved' => 1]);
        (new MapModel())->add(['category_key' => $this->si4Category, 'snipe_model_id' => 31, 'snipe_name' => 'NB 14', 'is_approved' => 1]);
        $this->si4Snipe = new FakeSnipeServer(self::SI4_TOKEN);
        $this->si4Snipe->statusLabels = [5 => true];
        $this->si4Snipe->models = [31 => true];
        $this->si4Snipe->companies = [self::SI4_CO1 => true, self::SI4_CO2 => true];
    }

    // ------------------------------------------------------------------ [SI4-E2E]

    private function si4E2e(): void
    {
        $this->out->writeln('== [SI4-E2E] outbox real ⇒ PurchasingIntegrationApi ⇒ Snipe ⇒ saga (sin ack) ==');
        $uuids = $this->si4Receive(3);
        $this->check('[SI4-E2E] recepción real generó 3 handoffs', count($uuids) === 3);

        // Multi-entidad: el worker de la entidad 2 no ve las unidades de la entidad 1 (ACL de Compras).
        $this->si4AsWorker([$this->si4E2]);
        $m = $this->si4Worker('st-E2')->run();
        $this->check('[SI4-MULTI-ENT] worker de otra entidad no reclama nada', $m['claimed'] === 0 && $m['aborted'] === null);

        $this->si4AsWorker([$this->si4E]);
        $m = $this->si4Worker('st-A')->run();
        $this->check('[SI4-E2E] 3 unidades reclamadas por lease y creadas en Snipe', $m['claimed'] === 3 && $m['created'] === 3 && $m['aborted'] === null);
        $api = new PurchasingIntegrationApi();
        $store = new DbSagaStore();
        $allOk = true;
        $leased = true;
        foreach ($uuids as $u) {
            $s = $store->get($u);
            $tag = AssetTagDeriver::tagFor('ST4-', $u);
            $live = $this->si4Snipe->liveByTag($tag);
            $allOk = $allOk && ($s['state'] ?? '') === SagaState::SNIPE_CREATED && (int) $s['snipe_asset_id'] === (int) ($live[0]['id'] ?? -1)
                && count($live) === 1 && $live[0]['company_id'] === self::SI4_CO1 && $live[0]['model_id'] === 31 && $live[0]['status_id'] === 5 && $live[0]['serial'] !== null;
            $leased = $leased && ($api->getHandoff($u)['status'] ?? '') === 'LEASED';
        }
        $this->check('[SI4-E2E] sagas SNIPE_CREATED con snipe_asset_id = activo remoto (compañía/modelo/estado mapeados, serial)', $allOk);
        $markers = true;
        foreach ($uuids as $u) {
            $live = $this->si4Snipe->liveByTag(AssetTagDeriver::tagFor('ST4-', $u));
            $markers = $markers && str_ends_with((string) ($live[0]['notes'] ?? ''), 'receipt_unit_uuid=' . $u);
        }
        $this->check('[SI4-E2E] cada activo creado lleva su marca de procedencia receipt_unit_uuid=<uuid> (verificada tras el POST)', $markers);
        $this->check('[SI4-E2E] 🔒 NO acknowledgeProcessed: el outbox sigue LEASED (SI-4 incompleto)', $leased);
        $posts = $this->si4Posts();
        $m = $this->si4Worker('st-A')->run();
        $this->check('[SI4-E2E] re-ejecutar el worker no crea nada (leases vigentes)', $m['claimed'] === 0 && $this->si4Posts() === $posts && count($this->si4Snipe->assets) === 3);
    }

    // ------------------------------------------------------------------ [SI4-CRASH]

    private function si4Crash(): void
    {
        $this->out->writeln('== [SI4-CRASH] crash tras el POST + lease vencido + dos workers ==');
        [$u] = $this->si4Receive(1);
        $this->si4AsWorker([$this->si4E]);
        $src = new PurchasingHandoffSource();
        $store = new DbSagaStore();
        // Worker A: toma con un lease de 2 s, persiste la intención, CREA en Snipe y "muere" antes de persistir el vínculo.
        $claim = $src->claimPending('st-A-crash', 1, 2);
        $this->check('[SI4-CRASH] A reclama la unidad (lease 2 s)', ($claim[0]['receipt_unit_uuid'] ?? '') === $u);
        $tokenA = (string) ($claim[0]['lease_token'] ?? '');
        $hA = hash('sha256', $tokenA);
        $tag = AssetTagDeriver::tagFor('ST4-', $u);
        $store->acquire($u, ['entities_id' => $this->si4E, 'requests_id' => $this->si4Req, 'items_id' => $this->si4Line,
            'payload_sha256' => (string) $claim[0]['payload_sha256'], 'correlation_id' => 'st'], $hA, (string) $claim[0]['leased_until'], (int) $claim[0]['attempts'], 'st-A-crash');
        $store->transition($u, $hA, SagaState::PENDING, ['state' => SagaState::SNIPE_CREATING, 'snipe_asset_tag' => $tag, 'remote_create_calls' => 1], 'create_intent');
        // Mismos campos (incluida la marca de procedencia en notes) que enviaría el worker real.
        $res = $this->si4Writer()->createAsset(Si4Worker::assetFields($tag, ['ok' => true, 'company_id' => self::SI4_CO1, 'model_id' => 31, 'status_id' => 5, 'reason' => ''],
            (array) $claim[0]['payload'], $u, 'st-crash'), 'st-crash');
        $this->check('[SI4-CRASH] el POST de A creó el activo (y A muere sin persistirlo)', $res->kind === 'created' && $store->get($u)['snipe_asset_id'] === null);
        sleep(3);
        $this->check('[SI4-CRASH] 🔒 lease vencido ⇒ A no puede escribir la saga', !$store->transition($u, $hA, SagaState::SNIPE_CREATING, ['last_error' => 'A tarde'], 'st'));
        $this->check('[SI4-CRASH] 🔒 lease vencido ⇒ A no puede finalizar el outbox (ack/retry/error)', $this->si4Throws(fn () => $src->acknowledgeProcessed($u, $tokenA))
            && $this->si4Throws(fn () => $src->markRetry($u, $tokenA, 'x', new \DateTimeImmutable('+1 hour')))
            && $this->si4Throws(fn () => $src->markError($u, $tokenA, 'x')));
        $posts = $this->si4Posts();
        $m = $this->si4Worker('st-B')->run();
        $s = $store->get($u);
        $this->check('[SI4-CRASH] B re-toma el lease y RECONCILIA el activo creado por A', $m['reconciled'] === 1 && ($s['state'] ?? '') === SagaState::SNIPE_CREATED
            && ($s['snipe_outcome'] ?? '') === SagaState::OUTCOME_RECONCILED);
        $this->check('[SI4-CRASH] 🔒 sin segundo POST y UN solo activo remoto', $this->si4Posts() === $posts && count($this->si4Snipe->liveByTag($tag)) === 1);
        $this->check('[SI4-CRASH] 🔒 una sola saga para la unidad (época 2, dueño B)', countElementsInTable(Si4Saga::getTable(), ['receipt_unit_uuid' => $u]) === 1
            && (int) $s['lease_epoch'] === 2 && str_starts_with((string) $s['worker_id'], 'st-B'));
        $this->check('[SI4-CRASH] 🔒 A sigue sin poder escribir tras la toma de B', !$store->transition($u, $hA, SagaState::SNIPE_CREATED, ['last_error' => 'A zombi'], 'st'));
        $this->check('[SI4-CRASH] 🔒 outbox sin ack (LEASED por B)', ((new PurchasingIntegrationApi())->getHandoff($u)['status'] ?? '') === 'LEASED');
    }

    // ------------------------------------------------------------------ [SI4-MAPPING]

    private function si4Mapping(): void
    {
        $this->out->writeln('== [SI4-MAPPING] mapeo no aprobado ⇒ BLOCKED_CONFIG + RETRY ==');
        [$u] = $this->si4Receive(1);
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $mm = new MapModel();
        $mm->getFromDBByCrit(['category_key' => $this->si4Category]);
        $mm->update(['id' => $mm->getID(), 'is_approved' => 0]);
        $this->si4AsWorker([$this->si4E]);
        $posts = $this->si4Posts();
        $m = $this->si4Worker('st-A')->run();
        $h = (new PurchasingIntegrationApi())->getHandoff($u);
        $this->check('[SI4-MAPPING] BLOCKED_CONFIG (fail-closed, sin inferir modelo)', $m['blocked_config'] === 1 && ((new DbSagaStore())->get($u)['state'] ?? '') === SagaState::BLOCKED_CONFIG);
        $this->check('[SI4-MAPPING] outbox RETRY (no ERROR) con motivo saneado', ($h['status'] ?? '') === 'RETRY' && str_contains((string) ($h['last_error'] ?? ''), 'mapping'));
        $this->check('[SI4-MAPPING] sin POST a Snipe', $this->si4Posts() === $posts);
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $mm->update(['id' => $mm->getID(), 'is_approved' => 1]);
    }

    // ------------------------------------------------------------------ [SI4-IDENTITY]

    /** Un tag determinista que YA existe en Snipe (misma compañía, otro modelo, sin marca) nunca se adopta. */
    private function si4Identity(): void
    {
        $this->out->writeln('== [SI4-IDENTITY] tag preexistente con otro modelo / sin marca ⇒ MANUAL_REVIEW ==');
        [$u] = $this->si4Receive(1);
        $tag = AssetTagDeriver::tagFor('ST4-', $u);
        $this->si4Snipe->seed(['asset_tag' => $tag, 'company_id' => self::SI4_CO1, 'model_id' => 32, 'status_id' => 5, 'notes' => 'alta manual']);
        $this->si4AsWorker([$this->si4E]);
        $posts = $this->si4Posts();
        $m = $this->si4Worker('st-A')->run();
        $s = (new DbSagaStore())->get($u);
        $this->check('[SI4-IDENTITY] 🔒 no se adopta: MANUAL_REVIEW sin snipe_asset_id', $m['manual_review'] === 1 && ($s['state'] ?? '') === SagaState::MANUAL_REVIEW
            && ($s['snipe_asset_id'] ?? null) === null && ($s['last_error_class'] ?? '') === 'preexisting');
        $this->check('[SI4-IDENTITY] 🔒 sin POST y outbox en ERROR (revisión humana)', $this->si4Posts() === $posts
            && ((new PurchasingIntegrationApi())->getHandoff($u)['status'] ?? '') === 'ERROR');
    }

    // ------------------------------------------------------------------ [SI4-SECRETS]

    private function si4Secrets(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [SI4-SECRETS] el token nunca queda en saga, bitácora, outbox ni logs ==');
        [$u] = $this->si4Receive(1);
        $this->si4Snipe->failNext('POST', '/api/v1/hardware', FakeSnipeServer::LEAKY_ERROR);
        $this->si4AsWorker([$this->si4E]);
        $m = $this->si4Worker('st-A')->run();
        $this->check('[SI4-SECRETS] error de transporte en POST ⇒ incierto (se buscará antes de reintentar)', $m['uncertain'] === 1);
        $blob = json_encode((new DbSagaStore())->get($u));
        foreach ($DB->request(['FROM' => Si4SagaLog::getTable(), 'WHERE' => ['receipt_unit_uuid' => $u]]) as $r) {
            $blob .= json_encode($r);
        }
        $blob .= json_encode((new PurchasingIntegrationApi())->getHandoff($u)) . implode("\n", $this->si4Logs);
        $this->check('[SI4-SECRETS] 🔒 token ausente de saga, bitácora, last_error del outbox y logs', !str_contains($blob, self::SI4_TOKEN) && $this->si4Logs !== []);
    }

    // ------------------------------------------------------------------ [SI4-ACL]

    private function si4Acl(): void
    {
        $this->out->writeln('== [SI4-ACL] mínimo privilegio (fail-closed) ==');
        $this->si4AsWorker([$this->si4E], 0);
        $this->check('[SI4-ACL] sin RIGHT_SI4 ⇒ la sesión del worker se rechaza', $this->si4Throws(fn () => WorkerSession::assertRights()));
        $this->si4AsWorker([$this->si4E], null, 0);
        $this->check('[SI4-ACL] sin RIGHT_INTEGRATION de Compras ⇒ la sesión del worker se rechaza', $this->si4Throws(fn () => WorkerSession::assertRights()));
        $m = $this->si4Worker('st-noacl')->run();
        $this->check('[SI4-ACL] sin RIGHT_INTEGRATION ⇒ claimPending denegado ⇒ corrida abortada', $m['aborted'] === 'source' && $m['claimed'] === 0);
        $this->si4AsWorker([$this->si4E], null, null, QrCode::RIGHT_PRINT);
        $this->check('[SI4-ACL] sin RIGHT_GENERATE de companyqr ⇒ la sesión del worker se rechaza (SI4-3)', $this->si4Throws(fn () => WorkerSession::assertRights()));
        $this->si4AsWorker([$this->si4E], null, null, QrCode::RIGHT_GENERATE);
        $this->check('[SI4-ACL] sin RIGHT_PRINT de companyqr ⇒ la sesión del worker se rechaza (SI4-3)', $this->si4Throws(fn () => WorkerSession::assertRights()));
        $this->si4AsWorker([$this->si4E]);
        $this->check('[SI4-ACL] con los bits de mínimo privilegio (y sin Super-Admin) la sesión es válida', !$this->si4Throws(fn () => WorkerSession::assertRights()));
    }

    // ------------------------------------------------------------------ [SI4-CMD]

    /**
     * El comando real `si4-run` con un usuario técnico REAL (perfil con SÓLO los dos bits) abierto por `Session::init`:
     * deshabilitado ⇒ no hace nada; habilitado con un Snipe inalcanzable ⇒ el preflight aborta ANTES de reclamar.
     */
    private function si4Command(): void
    {
        $this->out->writeln('== [SI4-CMD] comando si4-run + sesión real del usuario técnico ==');
        $this->si4AsAdmin([0, $this->si4E, $this->si4E2]);
        $user = $this->si4Actor('worker');
        $profile = (int) (new \Profile())->add(['name' => 'SI4-worker-' . $this->suffix, 'interface' => 'central']);
        \ProfileRight::updateProfileRights($profile, [
            AssetBridge::$rightname => AssetBridge::RIGHT_SI4,
            'plugin_companypurchasing' => PurchaseRequest::RIGHT_INTEGRATION,
            QrCode::$rightname => QrCode::RIGHT_GENERATE | QrCode::RIGHT_PRINT,
        ]);
        (new \Profile_User())->add(['users_id' => $user, 'profiles_id' => $profile, 'entities_id' => $this->si4E, 'is_recursive' => 0]);
        [$u] = $this->si4Receive(1);
        $saved = \Config::getConfigurationValues(IntegrationsConfig::CONTEXT);
        $env = getenv(IntegrationsConfig::TOKEN_ENV);
        $cmd = new Si4RunCommand();
        $run = function () use ($cmd, $user, $profile): array {
            $buf = new \Symfony\Component\Console\Output\BufferedOutput();
            $rc = $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--user' => (string) $user, '--profile' => (string) $profile]), $buf);
            return [$rc, $buf->fetch()];
        };
        try {
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, ['si4_enabled' => '0']);
            [$rc] = $run();
            $this->check('[SI4-CMD] deshabilitado ⇒ exit 0 sin hacer nada', $rc === 0 && ((new PurchasingIntegrationApi())->getHandoff($u)['status'] ?? '') === 'PENDING');
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, [
                'si4_enabled' => '1', 'si4_snipe_status_id' => '5', 'snipe_base_url' => 'https://snipe.invalid',
                'timeout_ms' => '2000', 'max_retries' => '0', 'si4_worker_id' => 'st-cmd',
            ]);
            putenv(IntegrationsConfig::TOKEN_ENV . '=' . self::SI4_TOKEN);
            [$rc, $out] = $run();
            $this->si4AsWorker([$this->si4E]);
            $this->check('[SI4-CMD] sesión técnica real + Snipe inalcanzable ⇒ preflight aborta (exit 1)', $rc === 1 && str_contains($out, 'snipe_unavailable'));
            $this->check('[SI4-CMD] 🔒 nada reclamado: la unidad sigue PENDING y sin saga', ((new PurchasingIntegrationApi())->getHandoff($u)['status'] ?? '') === 'PENDING'
                && (new DbSagaStore())->get($u) === null);
            $this->check('[SI4-CMD] 🔒 el token no aparece en la salida del comando', !str_contains($out, self::SI4_TOKEN));
            putenv(IntegrationsConfig::TOKEN_ENV);
            [$rc, $out] = $run();
            $this->check('[SI4-CMD] sin token en el entorno ⇒ exit 1 (fail-closed)', $rc === 1 && str_contains($out, IntegrationsConfig::TOKEN_ENV));
            \ProfileRight::updateProfileRights($profile, [QrCode::$rightname => QrCode::RIGHT_GENERATE]);
            putenv(IntegrationsConfig::TOKEN_ENV . '=' . self::SI4_TOKEN);
            [$rc, $out] = $run();
            $this->check('[SI4-CMD] usuario técnico sin RIGHT_PRINT de companyqr ⇒ exit 1 ANTES de reclamar (SI4-3)', $rc === 1 && str_contains($out, 'RIGHT_PRINT')
                && ((new PurchasingIntegrationApi())->getHandoff($u)['status'] ?? '') === 'PENDING');
            \ProfileRight::updateProfileRights($profile, [AssetBridge::$rightname => 0, QrCode::$rightname => QrCode::RIGHT_GENERATE | QrCode::RIGHT_PRINT]);
            [$rc, $out] = $run();
            $this->check('[SI4-CMD] usuario técnico sin RIGHT_SI4 ⇒ exit 1 (permiso denegado)', $rc === 1 && str_contains($out, 'RIGHT_SI4'));
        } finally {
            if ($env === false) {
                putenv(IntegrationsConfig::TOKEN_ENV);
            } else {
                putenv(IntegrationsConfig::TOKEN_ENV . '=' . $env);
            }
            $this->si4AsAdmin([0]);
            \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, is_array($saved) ? $saved : []);
        }
    }

    // ------------------------------------------------------------------ [SI4-NO-SIDE-EFFECTS]

    /** @return array<string,int> */
    private function si4SideEffectCounts(): array
    {
        return [
            'computers' => countElementsInTable(Computer::getTable()),
            'infocoms'  => countElementsInTable(\Infocom::getTable()),
            'bridges'   => countElementsInTable(AssetBridge::getTable()),
        ];
    }

    /** @param array<string,int> $before */
    private function si4NoSideEffects(array $before): void
    {
        $this->out->writeln('== [SI4-NO-SIDE-EFFECTS] SI4-1 no crea activos GLPI, Infocom ni puentes ==');
        $after = $this->si4SideEffectCounts();
        $this->check('[SI4-NO-SIDE-EFFECTS] sin activos GLPI nuevos', $after['computers'] === $before['computers']);
        $this->check('[SI4-NO-SIDE-EFFECTS] sin Infocom nuevos', $after['infocoms'] === $before['infocoms']);
        $this->check('[SI4-NO-SIDE-EFFECTS] sin asset_bridge nuevos (el puente es de un incremento posterior)', $after['bridges'] === $before['bridges']);
        $this->check('[SI4-NO-SIDE-EFFECTS] Snipe sólo recibió GET/POST de hardware y statuslabels', $this->si4Snipe === null || array_filter($this->si4Snipe->requests,
            static fn (array $r): bool => !preg_match('#/api/v1/(hardware|statuslabels)#', (string) parse_url($r['url'], PHP_URL_PATH))) === []);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Recibe `$qty` unidades (con serial) en un lote nuevo con el servicio PÚBLICO de Compras y devuelve sus
     * receipt_unit_uuid (los que `receive()` informa), confirmando por `getHandoff()` que cada uno tiene handoff.
     *
     * @return array<int,string>
     */
    private function si4Receive(int $qty): array
    {
        $this->si4Batch++;
        $serials = [];
        for ($i = 1; $i <= $qty; $i++) {
            $serials[] = 'SI4-' . $this->suffix . '-' . $this->si4Batch . '-' . $i;
        }
        $this->si4AsPurchasing($this->si4Buyer, READ | PurchaseRequest::RIGHT_VIEW_ENTITY | PurchaseRequest::RIGHT_RECEIVE, READ);
        $rc = (new ReceivingService())->receive($this->si4Req, 'si4-' . $this->suffix . '-b' . $this->si4Batch,
            [['items_id' => $this->si4Line, 'quantity' => (string) $qty, 'serials' => $serials]]);
        $this->si4AsWorker([$this->si4E]);
        $api = new PurchasingIntegrationApi();
        $out = [];
        foreach ((array) ($rc['units'] ?? []) as $uuid) {
            if ($api->getHandoff((string) $uuid) !== null) {
                $out[] = (string) $uuid;
            }
        }
        return $out;
    }

    private function si4Worker(string $workerId): Si4Worker
    {
        $cfg = Si4Config::fromArray([
            'si4_enabled' => '1', 'si4_asset_tag_prefix' => 'ST4-', 'si4_snipe_status_id' => '5', 'si4_lease_seconds' => '900',
            'si4_max_units_per_run' => '50', 'si4_retry_base_seconds' => '60', 'si4_retry_max_seconds' => '3600',
            'si4_config_retry_seconds' => '3600', 'si4_auth_retry_seconds' => '900', 'si4_uncertain_cooldown_seconds' => '300',
            'si4_worker_id' => $workerId,
        ]);
        $logs = &$this->si4Logs;
        $logger = static function (string $l, string $m, array $c) use (&$logs): void {
            $logs[] = $l . ' ' . $m . ' ' . json_encode($c);
        };
        return new Si4Worker(new PurchasingHandoffSource(), new DbSagaStore(), new DbMappingResolver(5), $this->si4Writer($logger), $cfg, 5000, 1, $logger);
    }

    private function si4Writer(?callable $logger = null): SnipeAssetWriter
    {
        return new SnipeAssetWriter($this->si4Snipe, new SnipeClientConfig('https://snipe.test', self::SI4_TOKEN, 5000, 1, 1, 50, 60), $logger, false);
    }

    private function si4Posts(): int
    {
        return $this->si4Snipe === null ? 0 : count(array_filter($this->si4Snipe->requests, static fn (array $r): bool => $r['method'] === 'POST'));
    }

    private function si4Decide(int $user, bool $buyer): void
    {
        $this->si4AsPurchasing($user, READ | PurchaseRequest::RIGHT_VIEW_ENTITY | ($buyer ? PurchaseRequest::RIGHT_MANAGE_PURCHASING : 0), READ | 2);
        $req = new PurchaseRequest();
        $req->getFromDB($this->si4Req);
        $gw = new WorkflowGateway();
        $inst = $gw->loadInstance((int) $req->fields['workflow_instances_id']);
        (new ApprovalOrchestrator())->decide($this->si4Req, 'approve', $inst !== null ? $gw->stateCode($inst) : '', 'ok si4');
    }

    /**
     * Sesión del worker: por defecto SÓLO los bits de mínimo privilegio (sin Super-Admin, sin ver solicitudes):
     * RIGHT_SI4, RIGHT_INTEGRATION de Compras y (SI4-3) companyqr generate + print.
     *
     * @param array<int> $entities
     */
    private function si4AsWorker(array $entities, ?int $integrationsBits = null, ?int $purchasingBits = null, ?int $qrBits = null): void
    {
        $this->applySession(2, $entities, [
            'plugin_companyintegrations' => $integrationsBits ?? AssetBridge::RIGHT_SI4,
            'plugin_companypurchasing'   => $purchasingBits ?? PurchaseRequest::RIGHT_INTEGRATION,
            // SI4-3 (ADR-0022): companyqr RIGHT_GENERATE | RIGHT_PRINT (bits del contrato público de companyqr)
            QrCode::$rightname           => $qrBits ?? (QrCode::RIGHT_GENERATE | QrCode::RIGHT_PRINT),
        ]);
        unset($_SESSION['glpicronuserrunning']);
    }

    /** @param array<int> $entities */
    private function si4AsAdmin(array $entities): void
    {
        $this->applySession(2, $entities, [
            'plugin_companypurchasing'   => ALLSTANDARDRIGHT | 0xFFFF,
            'plugin_companyworkflow'     => ALLSTANDARDRIGHT,
            'plugin_companysignature'    => ALLSTANDARDRIGHT,
            'plugin_companyintegrations' => ALLSTANDARDRIGHT | AssetBridge::RIGHT_SI4,
            'entity' => ALLSTANDARDRIGHT, 'user' => ALLSTANDARDRIGHT, 'group' => ALLSTANDARDRIGHT, 'supplier' => ALLSTANDARDRIGHT,
            'document' => ALLSTANDARDRIGHT, 'config' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT,
        ], 1);
        unset($_SESSION['glpicronuserrunning']);
    }

    private function si4AsPurchasing(int $user, int $purchasingBits, int $workflowBits): void
    {
        $this->applySession($user, [$this->si4E], [
            'plugin_companypurchasing' => $purchasingBits,
            'plugin_companyworkflow'   => $workflowBits,
            'plugin_companysignature'  => ALLSTANDARDRIGHT,
        ]);
        unset($_SESSION['glpicronuserrunning']);
    }

    private function si4Actor(string $tag): int
    {
        $id = (int) (new \User())->add(['name' => 'si4_' . $tag . '_' . $this->suffix, 'realname' => 'SI4 ' . $tag, '_no_history' => true]);
        if ($id > 0) {
            $this->si4Users[] = $id;
            (new \Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => $this->si4E, 'is_recursive' => 0]);
        }
        return $id;
    }

    private function si4Group(string $tag): int
    {
        $id = (int) (new \Group())->add(['name' => 'SI4-GRP-' . $tag . '-' . $this->suffix, 'entities_id' => $this->si4E, 'is_recursive' => 0]);
        if ($id > 0) {
            $this->si4Groups[] = $id;
        }
        return $id;
    }

    private function si4Uuid(): string
    {
        $h = bin2hex(random_bytes(16));
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-4' . substr($h, 13, 3) . '-a' . substr($h, 17, 3) . '-' . substr($h, 20, 12);
    }

    /** Fecha de la BD desplazada `$seconds` (mismo reloj que usa el fencing). */
    private function si4DbTime(int $seconds): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $res = $DB->doQuery('SELECT DATE_FORMAT(DATE_ADD(NOW(), INTERVAL ' . $seconds . " SECOND), '%Y-%m-%d %H:%i:%s') AS t");
        $row = $res !== false ? $DB->fetchAssoc($res) : null;
        return (string) ($row['t'] ?? '');
    }

    /** @param array<int,string> $tables */
    private function si4Fingerprint(array $tables): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $acc = '';
        foreach ($tables as $t) {
            foreach ($DB->request(['FROM' => 'glpi_plugin_companyintegrations_' . $t, 'ORDER' => 'id ASC']) as $r) {
                $acc .= $t . json_encode($r);
            }
        }
        return hash('sha256', $acc);
    }

    private function si4Throws(callable $fn): bool
    {
        try {
            $fn();
        } catch (\Throwable) {
            return true;
        }
        return false;
    }

    private function si4Restore(): void
    {
        try {
            $this->si4AsAdmin([0]);
            if ($this->si4SavedPurchasing !== []) {
                \Config::setConfigurationValues(PurchasingConfig::CONTEXT, array_map('strval', $this->si4SavedPurchasing));
            }
            if ($this->si4SavedIntegrations !== []) {
                \Config::setConfigurationValues(IntegrationsConfig::CONTEXT, array_map('strval', $this->si4SavedIntegrations));
            }
            // Entidades/usuarios/grupos/proveedores de la fixture se CONSERVAN: los referencian datos de Compras
            // (historial del motor, cotización) que el selftest de Compras limpia por sus propios medios.
        } catch (\Throwable) {
            // best-effort; el stack de CI es efímero.
        }
    }
}
