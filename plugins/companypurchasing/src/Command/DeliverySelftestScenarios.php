<?php

/**
 * Escenarios P2D-4 (entrega física, cierre, bandejas, notificaciones NATIVAS, métricas y E2E) del selftest
 * OBLIGATORIO `plugins:companypurchasing:selftest` (ADR-0023).
 *
 * TRAIT del mismo `SelftestCommand` (no un selftest paralelo que pueda omitirse): reutiliza los fixtures P2D-2/P2D-3.
 *
 * Cobertura:
 *   [UPGRADE-P2D4]        0.4.0 con datos → install() ×2 (0.5.0): tabla/columnas/claves/notificaciones sólo si faltan,
 *                         datos P2D-1/2/3 intactos (huella), personalizaciones preservadas, notificación ajustada intacta.
 *   [P2D4-PERSIST]        versión, esquema, literales compartidos, definición con fase de entrega/cierre.
 *   [DELIVERY-46]         10 unidades: entrega 4 + entrega 6 ⇒ EXACTAMENTE 10 DELIVERED, sin duplicados; RECEIVED hasta
 *                         la última; luego DELIVERED en el motor.
 *   [DELIVERY-DUP]        unidad ya entregada ⇒ rechazada; mezcla entregada + no entregada ⇒ ROLLBACK total.
 *   [DELIVERY-IDEMPOTENT] misma clave + misma entrada ⇒ replay; misma clave + otra entrada ⇒ conflicto.
 *   [DELIVERY-GATE]       inventariable con outbox PENDING/LEASED/RETRY/ERROR ⇒ no se entrega; DONE ⇒ sí;
 *                         no inventariable ⇒ sin outbox, entregable.
 *   [DELIVERY-CONC]       procesos REALES: misma unidad (lock) ⇒ una entrega, la otra ve la unidad entregada;
 *                         misma clave ⇒ un lote; 4 + 6 concurrentes ⇒ 10.
 *   [DELIVERY-CRASH-SYNC] COMMIT de la entrega → caída antes del motor ⇒ RECEIVED + pendiente ⇒ la Acción automática
 *                         nativa converge a DELIVERED; nunca se deshace la entrega.
 *   [DELIVERY-ACL]        DELIVER, entidad, destinatario aplicable, estado.
 *   [CLOSE]               cierre prematuro rechazado; MANAGE_PURCHASING; CLOSED final; reintento sin 2.ª transición;
 *                         caída tras la transición ⇒ idempotente.
 *   [LEGACY-DELIVERY]     instancia de una versión SIN fase de entrega: no se entrega, se reporta, no se muta.
 *   [P2D4-RIGHTS]         VIEW_OWN / VIEW_ENTITY / MANAGE_PURCHASING / RECEIVE / DELIVER / VIEW_METRICS en servicios de UI.
 *   [INBOX]               bandejas derivadas del motor (aprobaciones/compras/recepción/entrega), multi-entidad.
 *   [NOTIFY]              notificaciones NATIVAS en la cola `QueuedNotification` (destinatarios correctos), una vez por
 *                         hecho (listener + recorrido del ledger), el fallo de envío NO revierte el negocio.
 *   [METRICS]             conteos y montos EXACTOS (deltas), multi-moneda separada, aislamiento por entidad, 403.
 *   [E2E-FULL]            solicitante → aprobaciones → cotización → compra → recepción parcial/final → SI-4 (contrato
 *                         público) → entrega parcial/final → cierre ⇒ CLOSED con evidencia y auditoría completas.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Command;

use Profile_User;
use UserEmail;
use GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi;
use GlpiPlugin\Companypurchasing\Model\DeliveryBatch;
use GlpiPlugin\Companypurchasing\Model\OutboxEntry;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\ReceiptUnit;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Service\DeliveryException;
use GlpiPlugin\Companypurchasing\Service\DeliveryRules;
use GlpiPlugin\Companypurchasing\Service\DeliveryService;
use GlpiPlugin\Companypurchasing\Service\InboxService;
use GlpiPlugin\Companypurchasing\Service\MetricsMath;
use GlpiPlugin\Companypurchasing\Service\MetricsService;
use GlpiPlugin\Companypurchasing\Service\NotificationDispatcher;
use GlpiPlugin\Companypurchasing\Service\NotificationRules;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;
use GlpiPlugin\Companypurchasing\Service\PurchasingWorkflow;
use GlpiPlugin\Companypurchasing\Service\RequestDetailBuilder;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use GlpiPlugin\Companypurchasing\Service\RequestQuery;
use GlpiPlugin\Companypurchasing\Service\WorkflowGateway;
use GlpiPlugin\Companyworkflow\Model\HistoryEvent;
use GlpiPlugin\Companyworkflow\Model\Instance;

trait DeliverySelftestScenarios
{
    private int $uDeliverer = 0;
    private int $uRecipient = 0;
    private int $uMetrics = 0;

    /** Columnas / tablas / claves que AGREGA P2D-4 (para simular una instalación 0.4.0 y comparar huellas). */
    private const P2D4_REQUEST_COLS = ['delivery_seq', 'delivery_synced_seq'];
    private const P2D4_UNIT_COLS    = ['delivery_batches_id', 'delivered_at', 'delivered_to_users_id'];
    private const P2D4_CONFIG_KEYS  = ['delivery_max_units_per_batch', 'inbox_scan_cap', 'metrics_max_requests', 'notifications_enabled', 'notify_cursor'];

    // ================================================================ orquestación

    private function runDeliveryScenarios(): void
    {
        $this->out->writeln('== [P2D-4] entrega física + cierre + UI + notificaciones nativas + métricas ==');
        $this->p2d4Setup();
        $before = $this->sideEffectCounts();
        $this->scenarioP2d4Persist();
        $this->scenarioDelivery46();
        $this->scenarioDeliveryDuplicate();
        $this->scenarioDeliveryIdempotent();
        $this->scenarioDeliveryGate();
        $this->scenarioDeliveryConcurrency();
        $this->scenarioDeliveryCrashSync();
        $this->scenarioDeliveryAcl();
        $this->scenarioClose();
        $this->scenarioLegacyDelivery();
        $this->scenarioP2d4Rights();
        $this->scenarioInbox();
        $this->scenarioMetrics();
        $this->scenarioNotify();
        $this->scenarioE2eFull();
        $this->check('[NO-SIDE-EFFECTS] P2D-4: ningún Computer/Infocom/companyqr/companyintegrations creado (SI-4 simulado por su contrato público)',
            $this->sideEffectCounts() === $before);
    }

    private function p2d4Setup(): void
    {
        $this->asAdmin();
        foreach (array_merge(self::P2D4_CONFIG_KEYS, ['currency_scale_overrides']) as $k) {
            if (!array_key_exists($k, $this->savedConfig)) {
                $this->savedConfig[$k] = PluginConfig::get($k);
            }
        }
        $this->uDeliverer = $this->makeActor('deliverer');
        $this->uRecipient = $this->makeActor('recipient');
        $this->uMetrics   = $this->makeActor('metrics');
        $this->check('[P2D-4 SETUP] entregador / destinatario / analista de métricas', min($this->uDeliverer, $this->uRecipient, $this->uMetrics) > 0);
    }

    // ---- sesiones (mínimo privilegio) ----

    /** @param array<int>|null $entities */
    private function asDeliverer(int $user, ?array $entities = null, int $extra = 0): void
    {
        $this->applySession($user, $entities ?? [$this->entityA], [
            'plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY | Request::RIGHT_DELIVER | $extra,
            'plugin_companyworkflow'   => READ,
        ]);
    }

    /** Compras con MANAGE_PURCHASING (cierre) — mínimo privilegio en el motor (READ). @param array<int>|null $entities */
    private function asCloser(int $user, ?array $entities = null, bool $manage = true): void
    {
        $this->applySession($user, $entities ?? [$this->entityA], [
            'plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY | ($manage ? Request::RIGHT_MANAGE_PURCHASING : 0),
            'plugin_companyworkflow'   => READ,
        ]);
    }

    private function dlv(): DeliveryService
    {
        return new DeliveryService();
    }

    /**
     * Solicitud COMPRADA y RECIBIDA por completo (motor en RECEIVED).
     *
     * @param array<int,array{0:string,1:int,2:int}> $lines @param array<int,string> $prices
     */
    private function receivedRequest(string $tag, array $lines, array $prices): int
    {
        $req = $this->startedRequest($tag, $lines, $prices);
        $this->asReceiver($this->uReceiver);
        $in = [];
        foreach ($this->lineIds($req) as $i => $l) {
            $in[] = ['items_id' => $l, 'quantity' => (string) $lines[$i][1]];
        }
        $this->recv()->receive($req, 'p2d4-rcv-' . $tag . '-' . $this->suffix, $in);
        return $req;
    }

    /** @return array<int,string> uuids de las unidades de la solicitud (orden de id; opcionalmente de una línea) */
    private function uuidsOf(int $req, ?int $itemsId = null): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $where = ['requests_id' => $req];
        if ($itemsId !== null) {
            $where['items_id'] = $itemsId;
        }
        $out = [];
        foreach ($DB->request(['SELECT' => 'receipt_unit_uuid', 'FROM' => ReceiptUnit::getTable(), 'WHERE' => $where, 'ORDER' => 'id ASC']) as $r) {
            $out[] = (string) $r['receipt_unit_uuid'];
        }
        return $out;
    }

    /**
     * SI-4 SIMULADO por el contrato PÚBLICO (`claimPending` + `acknowledgeProcessed`) hasta dejar DONE las unidades
     * pedidas (lo demás que se tome queda LEASED, como haría otro worker). @param array<int,string> $uuids
     */
    private function ackOutbox(array $uuids): int
    {
        $want = array_fill_keys($uuids, true);
        $this->asIntegration($this->uIntegr);
        $acked = 0;
        for ($i = 0; $i < 30 && $want !== []; $i++) {
            $got = $this->api()->claimPending('p2d4-' . $this->suffix, PurchasingIntegrationApi::MAX_CLAIM, 3600);
            if ($got === []) {
                break;
            }
            foreach ($got as $c) {
                if (isset($want[$c['receipt_unit_uuid']])) {
                    $this->api()->acknowledgeProcessed($c['receipt_unit_uuid'], $c['lease_token']);
                    unset($want[$c['receipt_unit_uuid']]);
                    $acked++;
                }
            }
        }
        return $acked;
    }

    private function physicalOf(string $uuid): string
    {
        $u = new ReceiptUnit();
        return $u->getFromDBByCrit(['receipt_unit_uuid' => $uuid]) ? (string) $u->fields['physical_state'] : '';
    }

    /** @return array<string,mixed> */
    private function unitRow(string $uuid): array
    {
        $u = new ReceiptUnit();
        return $u->getFromDBByCrit(['receipt_unit_uuid' => $uuid]) ? $u->fields : [];
    }

    /** Lanza y devuelve el `kind` de un rechazo controlado (o '' si no lanzó / otra excepción). */
    private function kindOf(callable $fn): string
    {
        try {
            $fn();
        } catch (DeliveryException $e) {
            return $e->kind;
        } catch (\Throwable $e) {
            return 'other:' . substr($e->getMessage(), 0, 60);
        }
        return '';
    }

    // ================================================================ [UPGRADE-P2D4]

    /**
     * Instalación P2D-3 (0.4.0) con datos reales (recepciones, unidades y outbox de los escenarios anteriores) →
     * upgrade a P2D-4 (0.5.0) con install() ×2.
     */
    private function scenarioUpgradeP2d4(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [UPGRADE-P2D4] P2D-3 (0.4.0) con datos → install() ×2 (0.5.0) ==');
        $this->asAdmin();
        foreach (self::P2D4_CONFIG_KEYS as $k) {
            if (!array_key_exists($k, $this->savedConfig)) {
                $this->savedConfig[$k] = PluginConfig::get($k);
            }
        }
        if (!function_exists('plugin_companypurchasing_install')) {
            include_once dirname(__DIR__, 2) . '/hook.php';
        }
        // (1) Esquema/config/notificaciones como 0.4.0 (los datos P2D-1/2/3 ya existen de los escenarios previos).
        $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_companypurchasing_delivery_batches`');
        foreach (self::P2D4_REQUEST_COLS as $c) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_companypurchasing_requests` DROP COLUMN `{$c}`");
        }
        $DB->doQuery('ALTER TABLE `glpi_plugin_companypurchasing_receipt_units` DROP INDEX `delivery_batches_id`');
        foreach (self::P2D4_UNIT_COLS as $c) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_companypurchasing_receipt_units` DROP COLUMN `{$c}`");
        }
        \Config::deleteConfigurationValues(PluginConfig::CONTEXT, self::P2D4_CONFIG_KEYS);
        \GlpiPlugin\Companypurchasing\Service\NotificationSeeder::purge();
        $this->check('[UPGRADE-P2D4] esquema 0.4.0 simulado (sin tabla/columnas/claves/notificaciones de P2D-4)',
            !$this->tableExistsLive('glpi_plugin_companypurchasing_delivery_batches')
            && !$this->columnExistsLive('glpi_plugin_companypurchasing_receipt_units', 'delivery_batches_id')
            && !$this->columnExistsLive('glpi_plugin_companypurchasing_requests', 'delivery_seq')
            && countElementsInTable(\Notification::getTable(), ['itemtype' => Request::class]) === 0
            && !array_key_exists('notify_cursor', (array) \Config::getConfigurationValues(PluginConfig::CONTEXT)));

        // (2) Personalizaciones de administrador y huella de los datos existentes.
        $profileId = 0;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => \Profile::getTable(), 'WHERE' => ['NOT' => ['id' => 4]], 'ORDER' => 'id ASC', 'LIMIT' => 1]) as $row) {
            $profileId = (int) $row['id'];
        }
        $origRight = $this->profileRightValue($profileId);
        $custom = READ | Request::RIGHT_VIEW_OWN | Request::RIGHT_DELIVER;
        $DB->update('glpi_profilerights', ['rights' => $custom], ['profiles_id' => $profileId, 'name' => Request::$rightname]);
        $configBefore = (array) \Config::getConfigurationValues(PluginConfig::CONTEXT);
        $fp = $this->p2d3Fingerprint();
        $units = countElementsInTable(ReceiptUnit::getTable());
        $outbox = countElementsInTable(OutboxEntry::getTable());
        $lastHid = (new \GlpiPlugin\Companyworkflow\Api\WorkflowApi())->lastHistoryId();

        // (3) Upgrade: install() ×2 (+ un ajuste del administrador ENTRE ambos: no debe pisarse).
        $threw = $this->throws(function (): void {
            plugin_companypurchasing_install();
        });
        $disabled = 0;
        foreach ((new \Notification())->find(['itemtype' => Request::class, 'event' => NotificationRules::EV_PURCHASE_STARTED]) as $n) {
            (new \Notification())->update(['id' => (int) $n['id'], 'is_active' => 0]);
            $disabled = (int) $n['id'];
        }
        $threw = $threw || $this->throws(function (): void {
            plugin_companypurchasing_install();
        });
        $this->check('[UPGRADE-P2D4] install() ×2 sin excepción', !$threw);
        $this->check('[UPGRADE-P2D4] crea delivery_batches y agrega columnas de receipt_units / requests',
            $this->tableExistsLive('glpi_plugin_companypurchasing_delivery_batches')
            && array_reduce(self::P2D4_UNIT_COLS, fn (bool $c, string $col): bool => $c && $this->columnExistsLive('glpi_plugin_companypurchasing_receipt_units', $col), true)
            && array_reduce(self::P2D4_REQUEST_COLS, fn (bool $c, string $col): bool => $c && $this->columnExistsLive('glpi_plugin_companypurchasing_requests', $col), true));
        $this->check('[UPGRADE-P2D4] datos P2D-1/2/3 INTACTOS (solicitudes/líneas/cotizaciones/eventos/recepciones/unidades/outbox + motor + Firma: conteo + sha256)',
            $this->p2d3Fingerprint() === $fp && countElementsInTable(ReceiptUnit::getTable()) === $units && countElementsInTable(OutboxEntry::getTable()) === $outbox);
        $this->check('[UPGRADE-P2D4] unidades existentes con defaults neutros (RECEIVED, sin lote de entrega)',
            countElementsInTable(ReceiptUnit::getTable(), ['NOT' => ['delivery_batches_id' => null]]) === 0
            && countElementsInTable(ReceiptUnit::getTable(), ['physical_state' => ReceiptUnit::PHYSICAL_DELIVERED]) === 0);
        $after = (array) \Config::getConfigurationValues(PluginConfig::CONTEXT);
        $preserved = true;
        foreach ($configBefore as $k => $v) {
            $preserved = $preserved && array_key_exists($k, $after) && (string) $after[$k] === (string) $v;
        }
        $this->check('[UPGRADE-P2D4] configuración existente preservada; nuevas claves sólo si faltaban', $preserved
            && (string) ($after['delivery_max_units_per_batch'] ?? '') === PluginConfig::DEFAULTS['delivery_max_units_per_batch']
            && (string) ($after['inbox_scan_cap'] ?? '') === PluginConfig::DEFAULTS['inbox_scan_cap']);
        $this->check('[UPGRADE-P2D4] cursor de notificaciones arranca en "ahora" (jamás notifica hechos históricos)',
            (int) ($after['notify_cursor'] ?? -1) === $lastHid && $lastHid > 0);
        $this->check('[UPGRADE-P2D4] 10 notificaciones NATIVAS sembradas UNA vez (sin duplicar en el 2.º install) con plantilla y destinos',
            countElementsInTable(\Notification::getTable(), ['itemtype' => Request::class]) === 10
            && countElementsInTable(\NotificationTemplate::getTable(), ['itemtype' => Request::class]) === 1);
        $n = new \Notification();
        $this->check('[UPGRADE-P2D4] una notificación ajustada por el administrador entre installs NO se pisa', $disabled > 0
            && $n->getFromDB($disabled) && (int) $n->fields['is_active'] === 0);
        $this->check('[UPGRADE-P2D4] derecho personalizado de un perfil preservado (y Super-Admin con DELIVER + VIEW_METRICS)',
            $this->profileRightValue($profileId) === $custom
            && ($this->profileRightValue(4) & (Request::RIGHT_DELIVER | Request::RIGHT_VIEW_METRICS)) === (Request::RIGHT_DELIVER | Request::RIGHT_VIEW_METRICS));
        $this->check('[UPGRADE-P2D4] Acción automática única', countElementsInTable(\CronTask::getTable(), ['itemtype' => \GlpiPlugin\Companypurchasing\Model\ProjectionTask::class]) === 1);
        // Restaurar.
        (new \Notification())->update(['id' => $disabled, 'is_active' => 1]);
        if ($origRight >= 0) {
            $DB->update('glpi_profilerights', ['rights' => $origRight], ['profiles_id' => $profileId, 'name' => Request::$rightname]);
        }
    }

    /** Huella de los datos P2D-1/2/3 (sin las columnas que agrega P2D-4). @return array<string,string> */
    private function p3dFingerprintTables(): array
    {
        return ['requests', 'items', 'numbering', 'events', 'scope_defs', 'quotes', 'quote_items', 'doc_versions', 'docseq', 'policies',
                'integrity', 'receipt_batches', 'receipt_units', 'inventory_outbox', 'cost_policies'];
    }

    /** @return array<string,string> */
    private function p2d3Fingerprint(): array
    {
        $skip = [
            'glpi_plugin_companypurchasing_requests'      => self::P2D4_REQUEST_COLS,
            'glpi_plugin_companypurchasing_receipt_units' => self::P2D4_UNIT_COLS,
        ];
        $out = [];
        foreach ($this->p3dFingerprintTables() as $t) {
            $table = 'glpi_plugin_companypurchasing_' . $t;
            $out[$t] = $this->tableHash($table, [], $skip[$table] ?? []);
        }
        $out['wf_instances'] = $this->tableHash(Instance::getTable(), ['itemtype' => Request::class], []);
        $out['wf_history'] = $this->tableHash(HistoryEvent::getTable(), [], []);
        return $out;
    }

    // ================================================================ [P2D4-PERSIST]

    private function scenarioP2d4Persist(): void
    {
        $this->out->writeln('== [P2D4-PERSIST] versión, esquema, literales y definición ==');
        $this->check('[P2D4-PERSIST] versión del plugin 0.5.0', defined('PLUGIN_COMPANYPURCHASING_VERSION') && PLUGIN_COMPANYPURCHASING_VERSION === '0.5.0');
        $this->check('[P2D4-PERSIST] tabla delivery_batches (idempotency_key UNIQUE) + columnas de entrega en receipt_units',
            $this->tableExistsLive('glpi_plugin_companypurchasing_delivery_batches') && $this->columnExistsLive('glpi_plugin_companypurchasing_receipt_units', 'delivered_to_users_id'));
        $this->check('[P2D4-PERSIST] literales compartidos = constantes reales (unidad, outbox, ledger)',
            DeliveryRules::PHYSICAL_RECEIVED === ReceiptUnit::PHYSICAL_RECEIVED && DeliveryRules::PHYSICAL_DELIVERED === ReceiptUnit::PHYSICAL_DELIVERED
            && DeliveryRules::OUTBOX_DONE === OutboxEntry::STATUS_DONE && NotificationRules::LEDGER_TRANSITIONED === HistoryEvent::EVENT_TRANSITIONED
            && in_array(HistoryEvent::EVENT_STARTED, MetricsMath::ENTRY_EVENTS, true) && in_array(HistoryEvent::EVENT_APPROVAL_INVALIDATED, MetricsMath::ENTRY_EVENTS, true));
        $this->asAdmin();
        $def = (new WorkflowGateway())->activeDefinition(PluginConfig::workflowCode());
        $codes = [];
        if ($def !== null) {
            foreach ((new \GlpiPlugin\Companyworkflow\Model\StateDef())->find(['workflowdefs_id' => (int) $def->getID()]) as $s) {
                $codes[(string) $s['code']] = (string) $s['kind'];
            }
        }
        $this->check('[P2D4-PERSIST] la definición publicada (nueva versión) conoce DELIVERED (intermedio) y CLOSED (final)',
            ($codes['DELIVERED'] ?? '') === 'intermediate' && ($codes['CLOSED'] ?? '') === 'final' && !isset($codes['PARTIALLY_DELIVERED']));
        $this->check('[P2D4-PERSIST] notificaciones NATIVAS: 10 eventos del tipo + NotificationTargetRequest resuelta por GLPI',
            countElementsInTable(\Notification::getTable(), ['itemtype' => Request::class]) === 10
            && \NotificationTarget::getInstanceClass(Request::class) === \GlpiPlugin\Companypurchasing\Model\NotificationTargetRequest::class);
    }

    // ================================================================ [DELIVERY-46]

    private function scenarioDelivery46(): void
    {
        $this->out->writeln('== [DELIVERY-46] 10 unidades: entrega 4 + entrega 6 ⇒ exactamente 10 ==');
        $req = $this->receivedRequest('d46', [['Monitor', 10, 1]], ['700']);
        $uuids = $this->uuidsOf($req);
        $this->check('[DELIVERY-46] fixture: RECEIVED con 10 unidades inventariables', $this->wfState($req) === PurchasingWorkflow::S_RECEIVED && count($uuids) === 10);
        $this->check('[DELIVERY-46] SI-4 (contrato público): 10 handoffs DONE', $this->ackOutbox($uuids) === 10);
        $this->asDeliverer($this->uDeliverer);
        $r1 = $this->dlv()->deliver($req, array_slice($uuids, 0, 4), $this->uRecipient, 'd46-a-' . $this->suffix, 'primer lote');
        $this->check('[DELIVERY-46] entrega PARCIAL (4): lote creado, 4 DELIVERED, solicitud sigue RECEIVED (sin PARTIALLY_DELIVERED)',
            $r1['status'] === 'recorded' && count($r1['units']) === 4 && $this->wfState($req) === PurchasingWorkflow::S_RECEIVED
            && countElementsInTable(ReceiptUnit::getTable(), ['requests_id' => $req, 'physical_state' => ReceiptUnit::PHYSICAL_DELIVERED]) === 4);
        $r2 = $this->dlv()->deliver($req, array_slice($uuids, 4), $this->uRecipient, 'd46-b-' . $this->suffix);
        $rows = [];
        foreach ($uuids as $u) {
            $rows[] = $this->unitRow($u);
        }
        $owners = array_map(static fn (array $r): int => (int) $r['delivery_batches_id'], $rows);
        $this->check('[DELIVERY-46] entrega 6: EXACTAMENTE 10 DELIVERED, cada una con UN lote, destinatario y fecha',
            $r2['status'] === 'recorded' && count($r2['units']) === 6
            && count(array_filter($rows, static fn (array $r): bool => $r['physical_state'] === ReceiptUnit::PHYSICAL_DELIVERED)) === 10
            && count(array_filter($owners, static fn (int $b): bool => $b > 0)) === 10
            && array_count_values($owners) === [$r1['batch_id'] => 4, $r2['batch_id'] => 6]
            && array_filter($rows, fn (array $r): bool => (int) $r['delivered_to_users_id'] !== $this->uRecipient || empty($r['delivered_at'])) === []);
        $sum = 0;
        foreach ((new DeliveryBatch())->find(['requests_id' => $req]) as $b) {
            $sum += (int) $b['units_count'];
        }
        $r = $this->reqRow($req);
        $this->check('[DELIVERY-46] 2 lotes (Σ units_count = 10), sin duplicados; el motor convergió a DELIVERED (marcador al día)',
            $this->countRows(DeliveryBatch::getTable(), ['requests_id' => $req]) === 2 && $sum === 10
            && $this->wfState($req) === PurchasingWorkflow::S_DELIVERED && (int) $r['delivery_seq'] === 2 && (int) $r['delivery_synced_seq'] === 2
            && $this->countTransitionsTo($req, PurchasingWorkflow::S_DELIVERED) === 1);
        $this->check('[DELIVERY-46] auditoría: 2 delivery.recorded + 1 delivery.synced', $this->countEvents($req, PurchasingEvent::EV_DELIVERY_RECORDED) === 2
            && $this->countEvents($req, PurchasingEvent::EV_DELIVERY_SYNCED) === 1);
    }

    // ================================================================ [DELIVERY-DUP]

    private function scenarioDeliveryDuplicate(): void
    {
        $this->out->writeln('== [DELIVERY-DUP] unidad ya entregada ⇒ rechazada; ROLLBACK total ==');
        $req = $this->receivedRequest('dup', [['Mouse', 3, 0]], ['50']);
        $u = $this->uuidsOf($req);
        $this->asDeliverer($this->uDeliverer);
        $this->dlv()->deliver($req, [$u[0]], $this->uRecipient, 'dup-a-' . $this->suffix);
        $batches = $this->countRows(DeliveryBatch::getTable(), ['requests_id' => $req]);
        $k = $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[0]], $this->uRecipient, 'dup-b-' . $this->suffix));
        $this->check('[DELIVERY-DUP] entregar de nuevo una unidad entregada ⇒ unit_not_deliverable, sin lote nuevo',
            $k === DeliveryException::UNIT_NOT_DELIVERABLE && $this->countRows(DeliveryBatch::getTable(), ['requests_id' => $req]) === $batches);
        $k = $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[0], $u[1]], $this->uRecipient, 'dup-c-' . $this->suffix));
        $this->check('[DELIVERY-DUP] mezcla (entregada + no entregada) ⇒ ROLLBACK COMPLETO: la no entregada sigue RECEIVED',
            $k === DeliveryException::UNIT_NOT_DELIVERABLE && $this->physicalOf($u[1]) === ReceiptUnit::PHYSICAL_RECEIVED
            && $this->countRows(DeliveryBatch::getTable(), ['requests_id' => $req]) === $batches && (int) $this->reqRow($req)['delivery_seq'] === 1);
        $other = $this->receivedRequest('dup-other', [['Pad', 1, 0]], ['10']);
        $foreign = $this->uuidsOf($other)[0];
        $this->asDeliverer($this->uDeliverer);
        $k = $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[1], $foreign], $this->uRecipient, 'dup-d-' . $this->suffix));
        $this->check('[DELIVERY-DUP] unidad de OTRA solicitud ⇒ rechazada (no se correlaciona por serial/línea/QR) y nada cambia',
            $k === DeliveryException::UNIT_NOT_DELIVERABLE && $this->physicalOf($u[1]) === ReceiptUnit::PHYSICAL_RECEIVED && $this->physicalOf($foreign) === ReceiptUnit::PHYSICAL_RECEIVED);
        $k = $this->kindOf(fn () => $this->dlv()->deliver($req, ['serial-1'], $this->uRecipient, 'dup-e-' . $this->suffix));
        $this->check('[DELIVERY-DUP] identidad = receipt_unit_uuid: un serial/texto ⇒ entrada inválida', $k === DeliveryException::INVALID);
    }

    // ================================================================ [DELIVERY-IDEMPOTENT]

    private function scenarioDeliveryIdempotent(): void
    {
        $this->out->writeln('== [DELIVERY-IDEMPOTENT] replay exacto vs. conflicto de clave ==');
        $req = $this->receivedRequest('idem', [['Keyboard', 4, 0]], ['80']);
        $u = $this->uuidsOf($req);
        $this->asDeliverer($this->uDeliverer);
        $key = 'idem-' . $this->suffix;
        $a = $this->dlv()->deliver($req, [$u[0], $u[1]], $this->uRecipient, $key, 'n');
        $b = $this->dlv()->deliver($req, [$u[1], $u[0]], $this->uRecipient, $key, 'n');
        $this->check('[DELIVERY-IDEMPOTENT] misma clave + misma entrada (otro orden) ⇒ el MISMO lote (replayed), sin duplicar',
            $a['status'] === 'recorded' && $b['status'] === 'replayed' && $a['batch_id'] === $b['batch_id']
            && $this->countRows(DeliveryBatch::getTable(), ['idempotency_key' => $key]) === 1 && $this->countEvents($req, PurchasingEvent::EV_DELIVERY_RECORDED) === 1);
        $k1 = $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[2]], $this->uRecipient, $key, 'n'));
        $k2 = $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[0], $u[1]], $this->uDeliverer, $key, 'n'));
        $this->check('[DELIVERY-IDEMPOTENT] misma clave + otra entrada (unidades / destinatario) ⇒ conflicto, nada cambia',
            $k1 === DeliveryException::IDEMPOTENCY_CONFLICT && $k2 === DeliveryException::IDEMPOTENCY_CONFLICT
            && $this->physicalOf($u[2]) === ReceiptUnit::PHYSICAL_RECEIVED);
        $this->check('[DELIVERY-IDEMPOTENT] clave inválida ⇒ rechazada', $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[2]], $this->uRecipient, 'x')) === DeliveryException::INVALID);
    }

    // ================================================================ [DELIVERY-GATE]

    private function scenarioDeliveryGate(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [DELIVERY-GATE] inventariable ⇒ handoff DONE; no inventariable ⇒ sin outbox ==');
        $req = $this->receivedRequest('gate', [['Laptop', 5, 1], ['Bag', 2, 0]], ['900', '30']);
        [$lInv, $lNon] = $this->lineIds($req);
        $inv = $this->uuidsOf($req, $lInv);
        $non = $this->uuidsOf($req, $lNon);
        $this->check('[DELIVERY-GATE] no inventariable: unidades sin outbox; inventariable: un handoff por unidad',
            $this->countRows(OutboxEntry::getTable(), ['receipt_unit_uuid' => $non]) === 0 && $this->countRows(OutboxEntry::getTable(), ['receipt_unit_uuid' => $inv]) === 5);
        $set = function (string $uuid, string $status) use ($DB): void {
            $DB->update(OutboxEntry::getTable(), ['status' => $status], ['receipt_unit_uuid' => $uuid]);
        };
        $set($inv[1], OutboxEntry::STATUS_LEASED);
        $set($inv[2], OutboxEntry::STATUS_RETRY);
        $set($inv[3], OutboxEntry::STATUS_ERROR);
        $this->asDeliverer($this->uDeliverer);
        $gates = [];
        foreach ([0, 1, 2, 3] as $i) {
            try {
                $this->dlv()->deliver($req, [$inv[$i]], $this->uRecipient, 'gate-' . $i . '-' . $this->suffix);
                $gates[$i] = 'delivered';
            } catch (DeliveryException $e) {
                $gates[$i] = $e->kind . ':' . implode(',', $e->details['gates'] ?? []);
            }
        }
        $this->check('[DELIVERY-GATE] PENDING ⇒ inventario pendiente; LEASED ⇒ integración en curso; RETRY ⇒ pendiente; ERROR ⇒ error (no se entrega)',
            $gates === [0 => 'inventory_gate:inventory_pending', 1 => 'inventory_gate:integration_in_progress', 2 => 'inventory_gate:inventory_pending', 3 => 'inventory_gate:integration_error']
            && array_filter($inv, fn (string $u): bool => $this->physicalOf($u) !== ReceiptUnit::PHYSICAL_RECEIVED) === []);
        $k = $this->kindOf(fn () => $this->dlv()->deliver($req, [$non[0], $inv[0]], $this->uRecipient, 'gate-mix-' . $this->suffix));
        $this->check('[DELIVERY-GATE] lote mixto con una inventariable sin DONE ⇒ ROLLBACK (tampoco la no inventariable)',
            $k === DeliveryException::INVENTORY_GATE && $this->physicalOf($non[0]) === ReceiptUnit::PHYSICAL_RECEIVED);
        $r = $this->dlv()->deliver($req, $non, $this->uRecipient, 'gate-non-' . $this->suffix);
        $this->check('[DELIVERY-GATE] no inventariables ⇒ entregables SIN SI-4', $r['status'] === 'recorded' && $this->physicalOf($non[1]) === ReceiptUnit::PHYSICAL_DELIVERED);
        $set($inv[4], OutboxEntry::STATUS_DONE);
        $r = $this->dlv()->deliver($req, [$inv[4]], $this->uRecipient, 'gate-done-' . $this->suffix);
        $this->check('[DELIVERY-GATE] inventariable con handoff DONE ⇒ entregable', $r['status'] === 'recorded' && $this->physicalOf($inv[4]) === ReceiptUnit::PHYSICAL_DELIVERED);
        $view = $this->dlv()->unitsWithGate($req);
        $json = (string) json_encode($view);
        $this->check('[DELIVERY-GATE] la UI explica el motivo FUNCIONAL sin secretos (sin last_error / lease / payload)',
            str_contains($json, 'integration_error') && str_contains($json, 'inventory_pending')
            && !str_contains($json, 'lease') && !str_contains($json, 'last_error') && !str_contains($json, 'payload'));
    }

    // ================================================================ [DELIVERY-CONC]

    private function scenarioDeliveryConcurrency(): void
    {
        $this->out->writeln('== [DELIVERY-CONC] entregas concurrentes (procesos reales) ==');
        $req = $this->receivedRequest('conc', [['Phone', 12, 0]], ['400']);
        $u = $this->uuidsOf($req);
        $base = 'php bin/console plugins:companypurchasing:concurrency-probe --op=deliver --request=' . $req . ' --user=' . $this->uDeliverer
            . ' --recipient=' . $this->uRecipient . ' --no-interaction';

        // (a) Misma unidad: A retiene el lock 3 s; B arranca 1 s después con OTRA clave ⇒ B espera el COMMIT de A y VE
        //     la unidad entregada en su validación (rechazo controlado), no un conflicto genérico.
        $outs = $this->runParallel([
            $base . ' --units=' . $u[0] . ' --key=conc-a-' . $this->suffix . ' --hold=3000',
            $base . ' --units=' . $u[0] . ' --key=conc-b-' . $this->suffix . ' --delay=1000',
        ]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $ok = count(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'OK:recorded:1')));
        $ctl = count(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'ERR:' . DeliveryException::UNIT_NOT_DELIVERABLE)));
        $this->check('[DELIVERY-CONC] 🔒 misma unidad: EXACTAMENTE una entrega; la otra se SERIALIZA por el lock y falla de forma controlada (unit_not_deliverable)',
            $ok === 1 && $ctl === 1);
        $row = $this->unitRow($u[0]);
        $this->check('[DELIVERY-CONC] exactamente UN delivery_batch dueño de la unidad (y un solo lote creado)',
            (int) $row['delivery_batches_id'] > 0 && $this->countRows(DeliveryBatch::getTable(), ['requests_id' => $req]) === 1
            && $this->countRows(DeliveryBatch::getTable(), ['id' => (int) $row['delivery_batches_id'], 'units_count' => 1]) === 1);

        // (b) Misma clave + misma entrada en paralelo ⇒ un lote (recorded + replayed).
        $same = $base . ' --units=' . $u[1] . ' --key=conc-same-' . $this->suffix;
        $outs = $this->runParallel([$same, $same]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $st = array_map(static fn (string $o): string => explode(':', $o)[1] ?? '', $outs);
        sort($st);
        $this->check('[DELIVERY-CONC] misma idempotency_key en paralelo ⇒ recorded + replayed y UN solo lote',
            $st === ['recorded', 'replayed'] && $this->countRows(DeliveryBatch::getTable(), ['idempotency_key' => 'conc-same-' . $this->suffix]) === 1);

        // (c) 4 + 6 concurrentes sobre unidades DISTINTAS ⇒ ambos OK, 10 entregadas, sin duplicados.
        $outs = $this->runParallel([
            $base . ' --units=' . implode(',', array_slice($u, 2, 4)) . ' --key=conc-4-' . $this->suffix,
            $base . ' --units=' . implode(',', array_slice($u, 6, 6)) . ' --key=conc-6-' . $this->suffix,
        ]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $this->check('[DELIVERY-CONC] lote 4 + lote 6 CONCURRENTES ⇒ ambos OK; EXACTAMENTE 12 de 12 entregadas, cada una en UN lote',
            count(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'OK:recorded:'))) === 2
            && countElementsInTable(ReceiptUnit::getTable(), ['requests_id' => $req, 'physical_state' => ReceiptUnit::PHYSICAL_DELIVERED]) === 12
            && countElementsInTable(ReceiptUnit::getTable(), ['requests_id' => $req, 'delivery_batches_id' => null]) === 0);
        $this->asAdmin();
        $this->orch()->reconcile($req);
        $this->check('[DELIVERY-CONC] el motor convergió a DELIVERED (una sola transición)', $this->wfState($req) === PurchasingWorkflow::S_DELIVERED
            && $this->countTransitionsTo($req, PurchasingWorkflow::S_DELIVERED) === 1);
    }

    // ================================================================ [DELIVERY-CRASH-SYNC]

    private function scenarioDeliveryCrashSync(): void
    {
        $this->out->writeln('== [DELIVERY-CRASH-SYNC] COMMIT de la entrega → caída antes del motor → convergencia ==');
        $req = $this->receivedRequest('dcrash', [['Dock', 2, 0]], ['150']);
        $u = $this->uuidsOf($req);
        $crash = new class extends DeliveryService {
            protected function afterDeliveryCommit(int $batchId): void
            {
                throw new \RuntimeException('caída simulada tras el COMMIT de la entrega');
            }
        };
        $this->asDeliverer($this->uDeliverer);
        $threw = $this->throws(fn () => $crash->deliver($req, $u, $this->uRecipient, 'dcrash-' . $this->suffix));
        $r = $this->reqRow($req);
        $this->check('[DELIVERY-CRASH-SYNC] la entrega confirmada PERSISTE; el motor sigue RECEIVED y el marcador queda PENDIENTE',
            $threw && $this->physicalOf($u[0]) === ReceiptUnit::PHYSICAL_DELIVERED && $this->physicalOf($u[1]) === ReceiptUnit::PHYSICAL_DELIVERED
            && $this->wfState($req) === PurchasingWorkflow::S_RECEIVED && (int) $r['delivery_seq'] > (int) $r['delivery_synced_seq']);
        $this->asDeliverer($this->uDeliverer, null, 0);
        $this->applySession($this->uDeliverer, [$this->entityA], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY]);
        $rep = $this->orch()->reconcile($req);
        $this->check('[DELIVERY-CRASH-SYNC] reconcile sin derecho del motor ⇒ REPORTA la sincronización pendiente (sin mutar)',
            $rep['receiving_pending'] === true && $this->wfState($req) === PurchasingWorkflow::S_RECEIVED);
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['reconcile_cursor' => '0']);
        $ran = \CronTask::launch(-\CronTask::MODE_EXTERNAL, 1, \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME);
        $this->leaveCronContext();
        $this->asAdmin();
        $r = $this->reqRow($req);
        $this->check('[DELIVERY-CRASH-SYNC] la Acción automática NATIVA converge: DELIVERED y marcador sincronizado',
            $ran === \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME && $this->wfState($req) === PurchasingWorkflow::S_DELIVERED
            && (int) $r['delivery_synced_seq'] === (int) $r['delivery_seq'] && $this->domainState($req) === PurchasingWorkflow::S_DELIVERED);
        $this->check('[DELIVERY-CRASH-SYNC] nunca se deshizo la entrega física para seguir al motor; reintento ⇒ mismo lote',
            countElementsInTable(ReceiptUnit::getTable(), ['requests_id' => $req, 'physical_state' => ReceiptUnit::PHYSICAL_DELIVERED]) === 2
            && $this->kindOf(function () use ($req, $u): void {
                $this->asDeliverer($this->uDeliverer);
                $r = $this->dlv()->deliver($req, $u, $this->uRecipient, 'dcrash-' . $this->suffix);
                if ($r['status'] !== 'replayed') {
                    throw new \RuntimeException('no replay');
                }
            }) === '');
    }

    // ================================================================ [DELIVERY-ACL]

    private function scenarioDeliveryAcl(): void
    {
        $this->out->writeln('== [DELIVERY-ACL] derecho, entidad, destinatario y estado ==');
        $req = $this->receivedRequest('acl', [['Cable', 2, 0]], ['5']);
        $u = $this->uuidsOf($req);
        $this->asReceiver($this->uReceiver);
        $this->check('[DELIVERY-ACL] sin RIGHT_DELIVER ⇒ acl', $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[0]], $this->uRecipient, 'acl-a-' . $this->suffix)) === DeliveryException::ACL);
        $this->asDeliverer($this->uDeliverer, [$this->entityB]);
        $this->check('[DELIVERY-ACL] 🔒 entidad B no entrega unidades de A ⇒ entity', $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[0]], $this->uRecipient, 'acl-b-' . $this->suffix)) === DeliveryException::ENTITY);
        $this->asDeliverer($this->uDeliverer);
        $this->check('[DELIVERY-ACL] destinatario sin perfil en la entidad (o inexistente) ⇒ recipient',
            $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[0]], $this->uReceiverB, 'acl-c-' . $this->suffix)) === DeliveryException::RECIPIENT
            && $this->kindOf(fn () => $this->dlv()->deliver($req, [$u[0]], 99999999, 'acl-d-' . $this->suffix)) === DeliveryException::RECIPIENT);
        $partial = $this->startedRequest('acl-state', [['Hub', 4, 0]], ['20']);
        $this->asReceiver($this->uReceiver);
        $this->recv()->receive($partial, 'acl-st-' . $this->suffix, [['items_id' => $this->lineIds($partial)[0], 'quantity' => '2']]);
        $pu = $this->uuidsOf($partial);
        $this->asDeliverer($this->uDeliverer);
        $this->check('[DELIVERY-ACL] recepción PARCIAL (PARTIALLY_RECEIVED) ⇒ no se entrega (sólo con TODO recibido)',
            $this->kindOf(fn () => $this->dlv()->deliver($partial, [$pu[0]], $this->uRecipient, 'acl-e-' . $this->suffix)) === DeliveryException::STATE
            && $this->physicalOf($pu[0]) === ReceiptUnit::PHYSICAL_RECEIVED);
    }

    // ================================================================ [CLOSE]

    private function scenarioClose(): void
    {
        $this->out->writeln('== [CLOSE] cierre administrativo (prematuro, ACL, final, idempotente, caída) ==');
        $req = $this->receivedRequest('close', [['Chair', 2, 0]], ['300']);
        $u = $this->uuidsOf($req);
        $this->asDeliverer($this->uDeliverer);
        $this->dlv()->deliver($req, [$u[0]], $this->uRecipient, 'close-a-' . $this->suffix);
        $this->asCloser($this->uBuyer);
        $blockers = [];
        try {
            $this->dlv()->closeRequest($req, 'prematuro');
        } catch (DeliveryException $e) {
            $blockers = $e->kind === DeliveryException::NOT_READY_TO_CLOSE ? $e->details['blockers'] : ['other'];
        }
        $this->check('[CLOSE] cierre PREMATURO (una unidad sin entregar) ⇒ rechazado con motivos funcionales; sin transición',
            in_array(DeliveryRules::BLOCK_STATE, $blockers, true) && in_array(DeliveryRules::BLOCK_UNITS_NOT_DELIVERED, $blockers, true)
            && $this->wfState($req) === PurchasingWorkflow::S_RECEIVED && $this->countTransitionsTo($req, PurchasingWorkflow::S_CLOSED) === 0);
        $this->asDeliverer($this->uDeliverer);
        $this->dlv()->deliver($req, [$u[1]], $this->uRecipient, 'close-b-' . $this->suffix);
        $this->asCloser($this->uBuyer, null, false);
        $this->check('[CLOSE] sin MANAGE_PURCHASING ⇒ acl', $this->kindOf(fn () => $this->dlv()->closeRequest($req)) === DeliveryException::ACL);
        $this->asCloser($this->uBuyer, [$this->entityB]);
        $this->check('[CLOSE] 🔒 otra entidad ⇒ entity', $this->kindOf(fn () => $this->dlv()->closeRequest($req)) === DeliveryException::ENTITY);
        $this->asCloser($this->uBuyer);
        $v = (new RequestDetailBuilder())->build($req);
        $this->check('[CLOSE] vista: DELIVERED con close en el motor + MANAGE ⇒ se ofrece cerrar (y no entregar: el motor ya no lo ofrece)',
            $v['can']['close'] && !$v['can']['deliver']);
        $c = $this->dlv()->closeRequest($req, 'cierre');
        $inst = (new WorkflowGateway())->loadInstance($this->instanceId($req));
        $this->check('[CLOSE] DELIVERED + todo entregado ⇒ CLOSED (estado FINAL: instancia cerrada) y proyección CLOSED',
            $c['status'] === 'closed' && $this->wfState($req) === PurchasingWorkflow::S_CLOSED && $inst !== null && !$inst->isOpen()
            && $this->domainState($req) === PurchasingWorkflow::S_CLOSED);
        $hist = count($this->ledger($req));
        $c2 = $this->dlv()->closeRequest($req, 'otra vez');
        $this->check('[CLOSE] reintento ⇒ already_closed, SIN segunda transición ni eventos duplicados',
            $c2['status'] === 'already_closed' && $this->countTransitionsTo($req, PurchasingWorkflow::S_CLOSED) === 1
            && count($this->ledger($req)) === $hist && $this->countEvents($req, PurchasingEvent::EV_REQUEST_CLOSED) === 1);
        $this->asAdmin();
        $rep = $this->orch()->reconcile($req);
        $this->check('[CLOSE] reconcile sobre una solicitud CERRADA: convergida, sin anomalías ni pendientes',
            $rep['state'] === PurchasingWorkflow::S_CLOSED && $rep['receiving_anomaly'] === null && !$rep['receiving_pending'] && !$rep['legacy']);

        // Caída DESPUÉS de la transición (antes de auditar): el reintento converge sin 2.ª transición y audita UNA vez.
        $req2 = $this->receivedRequest('close-crash', [['Desk', 1, 0]], ['900']);
        $this->asDeliverer($this->uDeliverer);
        $this->dlv()->deliver($req2, $this->uuidsOf($req2), $this->uRecipient, 'close-c-' . $this->suffix);
        $crash = new class extends DeliveryService {
            protected function afterCloseTransition(int $requestId): void
            {
                throw new \RuntimeException('caída simulada tras cerrar en el motor');
            }
        };
        $this->asCloser($this->uBuyer);
        $threw = $this->throws(fn () => $crash->closeRequest($req2));
        $mid = $this->countEvents($req2, PurchasingEvent::EV_REQUEST_CLOSED);
        $c3 = $this->dlv()->closeRequest($req2);
        $this->check('[CLOSE] caída tras la transición ⇒ el motor ya está CLOSED; el reintento NO transiciona de nuevo y audita una vez',
            $threw && $mid === 0 && $c3['status'] === 'already_closed' && $this->countTransitionsTo($req2, PurchasingWorkflow::S_CLOSED) === 1
            && $this->countEvents($req2, PurchasingEvent::EV_REQUEST_CLOSED) === 1);
    }

    // ================================================================ [LEGACY-DELIVERY]

    private function scenarioLegacyDelivery(): void
    {
        $this->out->writeln('== [LEGACY-DELIVERY] instancia de una versión SIN fase de entrega (P2D-3) ==');
        $this->asAdmin();
        $gw = new WorkflowGateway();
        $spec = PurchasingWorkflow::spec(PluginConfig::workflowCode(), Request::class, PluginConfig::stageConfig());
        $spec['states'] = array_values(array_filter($spec['states'], static fn (array $s): bool => !in_array($s['code'], PurchasingWorkflow::DELIVERY_PHASE_STATES, true)));
        $spec['transitions'] = array_values(array_filter($spec['transitions'], static fn (array $t): bool
            => !in_array($t['to'], PurchasingWorkflow::DELIVERY_PHASE_STATES, true) && !in_array($t['from'], PurchasingWorkflow::DELIVERY_PHASE_STATES, true)));
        $legacy = $gw->publish($spec);
        $req = $this->receivedRequest('legacy-dlv', [['Scanner', 2, 0]], ['250']);
        $inst = $gw->loadInstance($this->instanceId($req));
        $this->check('[LEGACY-DELIVERY] fixture: RECEIVED bajo una versión con compra/recepción pero SIN entrega',
            $this->wfState($req) === PurchasingWorkflow::S_RECEIVED && $inst !== null && (int) $inst->fields['workflowdefs_id'] === (int) $legacy->getID());
        $this->asAdmin();
        $this->orch()->publishDefinition(); // versión vigente (con entrega) para lo que siga
        $lock = (int) $gw->loadInstance($this->instanceId($req))->fields['lock_version'];
        $hist = count($this->ledger($req));
        $this->asDeliverer($this->uDeliverer);
        $k = $this->kindOf(fn () => $this->dlv()->deliver($req, $this->uuidsOf($req), $this->uRecipient, 'legacy-' . $this->suffix));
        $this->check('[LEGACY-DELIVERY] entregar ⇒ legacy_definition (no se migra en silencio); unidades intactas',
            $k === DeliveryException::LEGACY_DEFINITION && countElementsInTable(ReceiptUnit::getTable(), ['requests_id' => $req, 'physical_state' => ReceiptUnit::PHYSICAL_DELIVERED]) === 0);
        // El botón sale de availableActions() del motor, NO del estado: RECEIVED + DELIVER + MANAGE pero la versión de la
        // instancia no tiene deliver_complete/close ⇒ la vista no ofrece entregar ni cerrar.
        $this->asDeliverer($this->uDeliverer, null, Request::RIGHT_MANAGE_PURCHASING);
        $v = (new RequestDetailBuilder())->build($req);
        $this->check('[LEGACY-DELIVERY] 🔒 vista: estado RECEIVED pero el motor no ofrece deliver_complete ⇒ sin botón de entregar ni cerrar',
            $v['header']['state'] === PurchasingWorkflow::S_RECEIVED && !$v['can']['deliver'] && !$v['can']['close']);
        $this->asAdmin();
        $rep = $this->orch()->reconcile($req);
        $this->check('[LEGACY-DELIVERY] reconcile la DETECTA y REPORTA (bloqueada en RECEIVED)', $rep['legacy'] && $rep['legacy_blocked']);
        $inst = $gw->loadInstance($this->instanceId($req));
        $this->check('[LEGACY-DELIVERY] nada mutado: mismo estado, lock_version e historial',
            $this->wfState($req) === PurchasingWorkflow::S_RECEIVED && (int) $inst->fields['lock_version'] === $lock && count($this->ledger($req)) === $hist);
    }

    // ================================================================ [P2D4-RIGHTS]

    private function scenarioP2d4Rights(): void
    {
        $this->out->writeln('== [P2D4-RIGHTS] VIEW_OWN / VIEW_ENTITY / MANAGE / RECEIVE / DELIVER / VIEW_METRICS ==');
        $mine = $this->newSubmitted('rights-mine');
        $other = $this->receivedRequest('rights-other', [['Lamp', 1, 0]], ['40']);
        // `receivedRequest` usa al solicitante del fixture; la "ajena" se reasigna a otro solicitante (dato de prueba).
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(Request::getTable(), ['users_id_requester' => $this->uReceiver], ['id' => $other]);

        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_OWN]);
        $ids = array_map(static fn (array $r): int => $r['id'], (new RequestQuery())->search([], RequestQuery::SCOPE_ENTITY, 500));
        $this->check('[P2D4-RIGHTS] VIEW_OWN: sólo las PROPIAS (aunque pida alcance entidad); detalle ajeno ⇒ denegado',
            in_array($mine, $ids, true) && !in_array($other, $ids, true)
            && $this->throws(fn () => (new RequestDetailBuilder())->build($other)));
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY]);
        $ids = array_map(static fn (array $r): int => $r['id'], (new RequestQuery())->search([], RequestQuery::SCOPE_ENTITY, 500));
        $this->check('[P2D4-RIGHTS] VIEW_ENTITY: todas las de la entidad (incluida la ajena)', in_array($mine, $ids, true) && in_array($other, $ids, true));
        $this->applySession($this->uOwner, [$this->entityB], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY]);
        $this->check('[P2D4-RIGHTS] 🔒 VIEW_ENTITY en B: nada de A (multi-entidad)', array_filter((new RequestQuery())->search([], RequestQuery::SCOPE_ENTITY, 500),
            static fn (array $r): bool => in_array($r['id'], [$mine, $other], true)) === []);
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => READ]);
        $this->check('[P2D4-RIGHTS] sin VIEW_OWN ni VIEW_ENTITY ⇒ listado denegado (fail-closed)', $this->throws(fn () => (new RequestQuery())->search([])));
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_OWN, 'plugin_companyworkflow' => READ]);
        $this->check('[P2D4-RIGHTS] filtros del navegador no amplían: entidad ajena pedida ⇒ vacío; estado/num inválido ignorado',
            (new RequestQuery())->search(['entities_id' => (string) $this->entityB]) === []
            && count((new RequestQuery())->search(['state' => "X' OR '1'='1", 'number' => '%']) ) >= 1);
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY]);
        $this->check('[P2D4-RIGHTS] métricas sin VIEW_METRICS ⇒ denegado', $this->throws(fn () => (new MetricsService())->compute()));
        $this->check('[P2D4-RIGHTS] bandejas operativas exigen su derecho (compras / recepción / entrega)',
            $this->throws(fn () => (new InboxService())->box(InboxService::BOX_PURCHASING))
            && $this->throws(fn () => (new InboxService())->box(InboxService::BOX_RECEIVING))
            && $this->throws(fn () => (new InboxService())->box(InboxService::BOX_DELIVERY))
            && InboxService::availableBoxes() === [InboxService::BOX_APPROVALS]);
        // can.* de la vista = acciones del MOTOR ∩ derecho de dominio.
        $this->applySession($this->uDeliverer, [$this->entityA], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY | Request::RIGHT_DELIVER, 'plugin_companyworkflow' => READ]);
        $v = (new RequestDetailBuilder())->build($other);
        $this->check('[P2D4-RIGHTS] vista: DELIVER en RECEIVED ⇒ puede entregar; sin MANAGE ⇒ no cierra/no compra; sin RECEIVE ⇒ no recibe',
            $v['can']['deliver'] && !$v['can']['close'] && !$v['can']['start_purchase'] && !$v['can']['receive'] && $v['can']['decide'] === []);
        $this->applySession($this->uDeliverer, [$this->entityA], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY, 'plugin_companyworkflow' => READ]);
        $v = (new RequestDetailBuilder())->build($other);
        $this->check('[P2D4-RIGHTS] vista: sin DELIVER ⇒ no se ofrece entregar aunque el motor lo permita', !$v['can']['deliver']);
        $json = (string) json_encode($v);
        $this->check('[P2D4-RIGHTS] la vista no expone tokens/lease/hashes/uuids completos de unidades', !str_contains($json, 'lease_token')
            && !str_contains($json, 'payload_sha256') && !str_contains($json, 'input_sha256') && !str_contains($json, (string) $this->uuidsOf($other)[0]));
    }

    // ================================================================ [INBOX]

    private function scenarioInbox(): void
    {
        $this->out->writeln('== [INBOX] bandejas derivadas del motor ==');
        $req = $this->newSubmitted('inbox');
        $ids = static fn (array $rows): array => array_map(static fn (array $r): int => $r['id'], $rows);
        $this->asApprover($this->uHead1);
        $head = (new InboxService())->box(InboxService::BOX_APPROVALS);
        $row = array_values(array_filter($head, static fn (array $r): bool => $r['id'] === $req))[0] ?? [];
        $this->check('[INBOX] el jefe (aprobador EFECTIVO por grupo) la tiene en "para mí" con approve/reject/return del motor',
            in_array($req, $ids($head), true) && count(array_intersect($row['actions'] ?? [], ['approve', 'reject', 'return'])) === 3);
        $this->asApprover($this->uFin);
        $this->check('[INBOX] 🔒 aprobador de OTRA etapa (Finanzas) no la ve', !in_array($req, $ids((new InboxService())->box(InboxService::BOX_APPROVALS)), true));
        $this->asApprover($this->uHead1, [$this->entityB]);
        $this->check('[INBOX] 🔒 el jefe con la entidad B activa no la ve (multi-entidad)', !in_array($req, $ids((new InboxService())->box(InboxService::BOX_APPROVALS)), true));
        $this->approveAs($this->uHead1, $req);
        $this->asApprover($this->uHead1);
        $this->check('[INBOX] tras decidir, sale de la bandeja del jefe', !in_array($req, $ids((new InboxService())->box(InboxService::BOX_APPROVALS)), true));
        $this->asBuyer($this->uBuyer);
        $this->check('[INBOX] ahora está en la bandeja de Compras (aprobación) y en "Gestión de compras" (cotizar según política pinneada)',
            in_array($req, $ids((new InboxService())->box(InboxService::BOX_APPROVALS)), true)
            && in_array($req, $ids((new InboxService())->box(InboxService::BOX_PURCHASING)), true));
        $started = $this->startedRequest('inbox-rcv', [['Router', 2, 0]], ['60']);
        $this->asReceiver($this->uReceiver);
        $this->check('[INBOX] recepciones pendientes: compra iniciada con receive_* disponible en el motor', in_array($started, $ids((new InboxService())->box(InboxService::BOX_RECEIVING)), true));
        $this->recv()->receive($started, 'inbox-r-' . $this->suffix, [['items_id' => $this->lineIds($started)[0], 'quantity' => '2']]);
        $this->asDeliverer($this->uDeliverer);
        $this->check('[INBOX] entregas pendientes: RECEIVED con deliver_complete y unidades sin entregar', in_array($started, $ids((new InboxService())->box(InboxService::BOX_DELIVERY)), true));
        $this->dlv()->deliver($started, $this->uuidsOf($started), $this->uRecipient, 'inbox-d-' . $this->suffix);
        $this->check('[INBOX] entregada por completo ⇒ sale de entregas pendientes', !in_array($started, $ids((new InboxService())->box(InboxService::BOX_DELIVERY)), true));
        $this->asCloser($this->uBuyer);
        $this->check('[INBOX] y entra en Compras para el cierre (acción close del motor)', in_array($started, $ids((new InboxService())->box(InboxService::BOX_PURCHASING)), true));
    }

    // ================================================================ [METRICS]

    private function scenarioMetrics(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [METRICS] montos exactos, multi-moneda, aislamiento por entidad ==');
        $asA = fn () => $this->applySession($this->uMetrics, [$this->entityA], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_METRICS]);
        $asB = fn () => $this->applySession($this->uMetrics, [$this->entityB], ['plugin_companypurchasing' => READ | Request::RIGHT_VIEW_METRICS]);
        $amt = static fn (array $m, string $k, string $cur): string => (string) ($m['amounts'][$k][$cur] ?? '0');
        $asA();
        $a0 = (new MetricsService())->compute();
        $asB();
        $b0 = (new MetricsService())->compute();

        // A: PYG 3×1500 + 2×2000 = 8500 (enviada) y USD 1 × 10.50 (enviada).
        $this->asRequester();
        $rm = new RequestManager();
        $pyg = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'metrics-pyg', 'category' => 'MET']);
        $rm->addLine($pyg, ['description' => 'A', 'quantity' => '3', 'estimated_unit_price' => '1500']);
        $rm->addLine($pyg, ['description' => 'B', 'quantity' => '2', 'estimated_unit_price' => '2000']);
        $this->orch()->submit($pyg);
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['currency_scale_overrides' => '{"USD":2}']);
        $usd = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'metrics-usd', 'currency_code' => 'USD', 'category' => 'MET']);
        $rm->addLine($usd, ['description' => 'C', 'quantity' => '1', 'estimated_unit_price' => '10.50']);
        $this->orch()->submit($usd);
        // B: monto ENORME + un handoff en ERROR (dato de prueba en la tabla propia de Compras).
        $this->applySession($this->uOwner, [$this->entityB], ['plugin_companypurchasing' => READ | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_EDIT_DRAFT,
                                                         'plugin_companyworkflow' => READ]);
        $big = $rm->createDraft(['entities_id' => $this->entityB, 'reason' => 'metrics-b']);
        $rm->addLine($big, ['description' => 'Z', 'quantity' => '1', 'estimated_unit_price' => '999999999']);
        $rm->submitDraft($big);
        $DB->insert(OutboxEntry::getTable(), [
            'receipt_unit_uuid' => \GlpiPlugin\Companypurchasing\Service\ReceivingService::uuidV4(), 'receipt_units_id' => 0, 'requests_id' => $big,
            'entities_id' => $this->entityB, 'payload_version' => 1, 'payload_json' => '{}', 'payload_sha256' => str_repeat('0', 64),
            'status' => OutboxEntry::STATUS_ERROR, 'attempts' => 1, 'date_creation' => date('Y-m-d H:i:s'),
        ]);

        $asA();
        $a1 = (new MetricsService())->compute();
        $this->check('[METRICS] A: +2 solicitadas; monto solicitado PYG +8500 EXACTO (escala 0) y USD +10.50 separado (jamás sumados)',
            $a1['counts']['requested'] === $a0['counts']['requested'] + 2
            && \GlpiPlugin\Companypurchasing\Service\Decimal::subStr(\GlpiPlugin\Companypurchasing\Service\Decimal::toMicro($amt($a1, 'requested', 'PYG')), \GlpiPlugin\Companypurchasing\Service\Decimal::toMicro($amt($a0, 'requested', 'PYG'))) === '8500000000'
            && \GlpiPlugin\Companypurchasing\Service\Decimal::subStr(\GlpiPlugin\Companypurchasing\Service\Decimal::toMicro($amt($a1, 'requested', 'USD')), \GlpiPlugin\Companypurchasing\Service\Decimal::toMicro($amt($a0, 'requested', 'USD'))) === '10500000'
            && preg_match('/^\d+$/', $amt($a1, 'requested', 'PYG')) === 1 && preg_match('/^\d+\.\d{2}$/', $amt($a1, 'requested', 'USD')) === 1);
        // Aislamiento AUTÓNOMO (no depende del check anterior): el delta de A es EXACTAMENTE lo suyo (el 999999999 de B
        // no se sumó: un `str_contains` no basta porque la suma lo oculta), B no aparece en el desglose por entidad ni
        // en el alcance, y el error de integración de B no cuenta para A.
        $this->check('[METRICS] 🔒 A jamás ve el monto ni el error de integración de B (aislamiento por entidad)',
            $a1['counts']['requested'] - $a0['counts']['requested'] === 2
            && \GlpiPlugin\Companypurchasing\Service\Decimal::subStr(\GlpiPlugin\Companypurchasing\Service\Decimal::toMicro($amt($a1, 'requested', 'PYG')), \GlpiPlugin\Companypurchasing\Service\Decimal::toMicro($amt($a0, 'requested', 'PYG'))) === '8500000000'
            && array_filter($a1['breakdown']['entity'], fn (array $r): bool => (int) $r['key'] === $this->entityB) === []
            && $a1['inventory']['ERROR'] === $a0['inventory']['ERROR'] && !in_array($this->entityB, $a1['entities'], true));
        $this->check('[METRICS] 🔒 A pidiendo la entidad B por filtro ⇒ nada (el filtro no amplía)',
            (new MetricsService())->compute(['entities_id' => (string) $this->entityB])['counts']['requested'] === 0
            && (new MetricsService())->compute(['entities_id' => (string) $this->entityB])['entities'] === []);
        $asB();
        $b1 = (new MetricsService())->compute();
        $this->check('[METRICS] B: sí ve su solicitud y su error de integración',
            $b1['counts']['requested'] === $b0['counts']['requested'] + 1 && $b1['inventory']['ERROR'] === $b0['inventory']['ERROR'] + 1
            && str_contains((string) json_encode($b1['amounts']['requested']), '999999999'));
        $asA();
        $byCat = array_values(array_filter($a1['breakdown']['category'], static fn (array $r): bool => $r['key'] === 'MET'))[0] ?? [];
        $this->check('[METRICS] desglose por categoría con totales por moneda', ($byCat['count'] ?? 0) === 2
            && ($byCat['requested']['PYG'] ?? '') === '8500' && ($byCat['requested']['USD'] ?? '') === '10.50');

        // Aprobado / comprado EXACTOS: cotización 2 × 300 + 1 × 1250 = 1850 (sin ajustes) ⇒ +1850 en ambos.
        $before = $a1;
        $this->startedRequest('metrics-buy', [['X', 2, 0], ['Y', 1, 0]], ['300', '1250']);
        $asA();
        $a2 = (new MetricsService())->compute();
        $delta = static fn (array $m2, array $m1, string $k): string => \GlpiPlugin\Companypurchasing\Service\Decimal::subStr(
            \GlpiPlugin\Companypurchasing\Service\Decimal::toMicro($amt($m2, $k, 'PYG')), \GlpiPlugin\Companypurchasing\Service\Decimal::toMicro($amt($m1, $k, 'PYG')));
        $this->check('[METRICS] monto aprobado y comprado +1850 EXACTOS; recepciones pendientes +1',
            $delta($a2, $before, 'approved') === '1850000000' && $delta($a2, $before, 'purchased') === '1850000000'
            && $a2['receiving']['pending'] === $before['receiving']['pending'] + 1 && $a2['counts']['approved'] === $before['counts']['approved'] + 1);
        $this->check('[METRICS] duración por etapa desde el ledger del motor (horas enteras) y ciclo total de las cerradas',
            isset($a2['stages']['PENDING_AREA_HEAD']) && $a2['stages']['PENDING_AREA_HEAD']['count'] > 0 && $a2['cycle']['count'] >= 1
            && is_int($a2['cycle']['avg_hours']));
        $this->check('[METRICS] entregas: unidades recibidas pendientes / entregadas / lotes', $a2['delivery']['units_delivered'] > 0 && $a2['delivery']['batches'] > 0);
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['currency_scale_overrides' => (string) ($this->savedConfig['currency_scale_overrides'] ?? '{}')]);
    }

    // ================================================================ [NOTIFY]

    private function scenarioNotify(): void
    {
        global $CFG_GLPI;
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [NOTIFY] notificaciones NATIVAS (QueuedNotification), una vez por hecho, sin revertir negocio ==');
        $saved = [$CFG_GLPI['use_notifications'] ?? 0, $CFG_GLPI['notifications_mailing'] ?? 0];
        $CFG_GLPI['use_notifications'] = 1;
        $CFG_GLPI['notifications_mailing'] = 1;
        try {
            // El solicitante del fixture tiene su perfil por defecto en la raíz: GLPI sólo notifica a quien tiene perfil en la
            // entidad de la solicitud (`addToRecipientsList`), así que se le da uno en A (dato de prueba).
            if (countElementsInTable(Profile_User::getTable(), ['users_id' => $this->uOwner, 'entities_id' => $this->entityA]) === 0) {
                (new Profile_User())->add(['users_id' => $this->uOwner, 'profiles_id' => 1, 'entities_id' => $this->entityA, 'is_recursive' => 0]);
            }
            foreach ([$this->uOwner => 'owner', $this->uHead1 => 'head1', $this->uHead2 => 'head2', $this->uRecipient => 'recipient'] as $uid => $tag) {
                if (countElementsInTable(UserEmail::getTable(), ['users_id' => $uid]) === 0) {
                    (new UserEmail())->add(['users_id' => $uid, 'email' => 'cp-' . $tag . '-' . $this->suffix . '@example.test', 'is_default' => 1]);
                }
            }
            $queued = static fn (int $req, string $event): array => array_map(static fn (array $r): string => (string) $r['recipient'],
                array_values((new \QueuedNotification())->find(['itemtype' => Request::class, 'items_id' => $req, 'event' => $event])));
            $req = $this->newSubmitted('notify');
            $sub = $queued($req, NotificationRules::EV_SUBMITTED);
            $pend = $queued($req, NotificationRules::EV_APPROVAL_PENDING);
            sort($pend);
            $this->check('[NOTIFY] envío ⇒ "enviada" al SOLICITANTE y "pendiente de aprobación" a los aprobadores EFECTIVOS (motor) en la cola NATIVA',
                $sub === ['cp-owner-' . $this->suffix . '@example.test']
                && $pend === ['cp-head1-' . $this->suffix . '@example.test', 'cp-head2-' . $this->suffix . '@example.test']);
            $this->check('[NOTIFY] marca durable por hecho del ledger (notification.raised)', $this->countEvents($req, PurchasingEvent::EV_NOTIFICATION_RAISED) === 2);
            $transId = 0;
            foreach ($this->ledger($req, HistoryEvent::EVENT_TRANSITIONED) as $h) {
                $transId = max($transId, (int) $h['id']);
            }
            $again = (new NotificationDispatcher())->dispatchHistory($transId);
            \Config::setConfigurationValues(PluginConfig::CONTEXT, ['notify_cursor' => (string) max(0, $transId - 1)]);
            $sweep = (new NotificationDispatcher())->sweep(50);
            $this->check('[NOTIFY] reintento del listener + recorrido del ledger ⇒ SIN duplicados (como mucho una vez por hecho)',
                $again === [] && $sweep['scanned'] >= 1 && count($queued($req, NotificationRules::EV_SUBMITTED)) === 1
                && count($queued($req, NotificationRules::EV_APPROVAL_PENDING)) === 2 && $sweep['cursor'] >= $transId);

            // Un fallo del envío NO revierte la transición de negocio ni la evidencia.
            $this->approveAs($this->uHead1, $req);
            $hid = 0;
            foreach ($this->ledger($req, HistoryEvent::EVENT_TRANSITIONED) as $h) {
                $hid = max($hid, (int) $h['id']);
            }
            $DB->delete(PurchasingEvent::getTable(), ['idempotency_key' => NotificationRules::idempotencyKey($hid, NotificationRules::EV_APPROVAL_PENDING)]);
            $boom = new NotificationDispatcher(null, null, static function (): bool {
                throw new \RuntimeException('SMTP caído (simulado)');
            });
            $this->asAdmin();
            $raised = $boom->dispatchHistory($hid);
            $this->check('[NOTIFY] fallo de envío ⇒ notification.failed registrado; la transición (PURCHASING) y la evidencia PERMANECEN',
                $raised === [] && $this->countEvents($req, PurchasingEvent::EV_NOTIFICATION_FAILED) === 1
                && $this->wfState($req) === PurchasingWorkflow::S_PURCHASING && $this->evidenceOf($req, $this->uHead1) !== null);
            $CFG_GLPI['use_notifications'] = 0;
            $n0 = countElementsInTable(\QueuedNotification::getTable(), ['itemtype' => Request::class, 'items_id' => $req]);
            $this->check('[NOTIFY] notificaciones desactivadas (configuración NATIVA) ⇒ nada se encola ni se marca',
                (new NotificationDispatcher())->dispatchHistory($hid) === []
                && countElementsInTable(\QueuedNotification::getTable(), ['itemtype' => Request::class, 'items_id' => $req]) === $n0);
        } finally {
            [$CFG_GLPI['use_notifications'], $CFG_GLPI['notifications_mailing']] = $saved;
        }
    }

    // ================================================================ [E2E-FULL]

    private function scenarioE2eFull(): void
    {
        global $CFG_GLPI;
        $this->out->writeln('== [E2E-FULL] solicitud → aprobaciones → compra → recepción → SI-4 → entrega → CIERRE ==');
        $saved = [$CFG_GLPI['use_notifications'] ?? 0, $CFG_GLPI['notifications_mailing'] ?? 0];
        $CFG_GLPI['use_notifications'] = 1;
        $CFG_GLPI['notifications_mailing'] = 1;
        try {
            // Solicitante: DRAFT + líneas + envío.
            $this->asRequester();
            $rm = new RequestManager();
            $req = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'e2e-full-' . $this->suffix, 'category' => 'IT', 'destination' => 'Depósito']);
            $lInv = $rm->addLine($req, ['description' => 'Notebook', 'quantity' => '3', 'estimated_unit_price' => '5000', 'is_inventoriable' => 1, 'category' => 'HW']);
            $lNon = $rm->addLine($req, ['description' => 'Mochila', 'quantity' => '2', 'estimated_unit_price' => '200', 'is_inventoriable' => 0, 'category' => 'ACC']);
            $this->orch()->submit($req, 'e2e');
            // Jefe aprueba; Compras cotiza (2), selecciona y aprueba; Finanzas aprueba.
            $this->approveAs($this->uHead1, $req);
            $q1 = $this->makeQuote($req, $this->supA, ['4800', '190']);
            $q2 = $this->makeQuote($req, $this->supA2, ['4900', '180']);
            $this->asBuyer($this->uBuyer);
            $this->orch()->selectQuote($req, $q1);
            $this->approveAs($this->uBuyer, $req, true);
            $this->approveAs($this->uFin, $req);
            $this->check('[E2E-FULL] aprobada por el circuito real (jefe → Compras → Finanzas) con cotización seleccionada',
                $this->wfState($req) === PurchasingWorkflow::S_APPROVED && (int) $this->reqRow($req)['quotes_id_selected'] === $q1 && $q2 > 0);
            $pdf = countElementsInTable(\Document_Item::getTable(), ['itemtype' => Request::class, 'items_id' => $req]);
            $this->check('[E2E-FULL] PDF aprobado disponible (Document NATIVO vinculado) y evidencia por aprobador',
                $pdf >= 1 && $this->evidenceOf($req, $this->uHead1) !== null && $this->evidenceOf($req, $this->uFin) !== null);
            // Compra + recepción parcial + final.
            $this->asBuyer($this->uBuyer);
            $this->recv()->startPurchase($req, 'e2e');
            $this->asReceiver($this->uReceiver);
            $this->recv()->receive($req, 'e2e-r1-' . $this->suffix, [['items_id' => $lInv, 'quantity' => '2', 'serials' => ['E2E-S1-' . $this->suffix, 'E2E-S2-' . $this->suffix]]]);
            $this->check('[E2E-FULL] recepción PARCIAL ⇒ PARTIALLY_RECEIVED', $this->wfState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED);
            $this->recv()->receive($req, 'e2e-r2-' . $this->suffix, [['items_id' => $lInv, 'quantity' => '1', 'serials' => ['E2E-S3-' . $this->suffix]], ['items_id' => $lNon, 'quantity' => '2']]);
            $inv = $this->uuidsOf($req, $lInv);
            $non = $this->uuidsOf($req, $lNon);
            $this->check('[E2E-FULL] recepción FINAL ⇒ RECEIVED; EXACTAMENTE 5 receipt_units (3 inventariables con handoff, 2 sin)',
                $this->wfState($req) === PurchasingWorkflow::S_RECEIVED && count($inv) === 3 && count($non) === 2
                && $this->countRows(OutboxEntry::getTable(), ['requests_id' => $req]) === 3);
            // Entrega parcial: la NO inventariable sale sin SI-4; las inventariables esperan su DONE.
            $this->asDeliverer($this->uDeliverer);
            $gate = $this->kindOf(fn () => $this->dlv()->deliver($req, [$inv[0]], $this->uRecipient, 'e2e-d0-' . $this->suffix));
            $this->dlv()->deliver($req, [$non[0]], $this->uRecipient, 'e2e-d1-' . $this->suffix, 'parcial');
            $this->check('[E2E-FULL] inventariable sin DONE ⇒ bloqueada; no inventariable ⇒ entregada sin SI-4 (sigue RECEIVED)',
                $gate === DeliveryException::INVENTORY_GATE && $this->physicalOf($non[0]) === ReceiptUnit::PHYSICAL_DELIVERED
                && $this->wfState($req) === PurchasingWorkflow::S_RECEIVED);
            // SI-4 por el contrato público hasta DONE.
            $this->check('[E2E-FULL] SI-4 (contrato PurchasingIntegrationApi) ⇒ 3 handoffs DONE', $this->ackOutbox($inv) === 3
                && $this->countRows(OutboxEntry::getTable(), ['requests_id' => $req, 'status' => OutboxEntry::STATUS_DONE]) === 3);
            // Entrega final.
            $this->asDeliverer($this->uDeliverer);
            $this->dlv()->deliver($req, array_merge($inv, [$non[1]]), $this->uRecipient, 'e2e-d2-' . $this->suffix, 'final');
            $this->check('[E2E-FULL] entrega FINAL ⇒ DELIVERED', $this->wfState($req) === PurchasingWorkflow::S_DELIVERED);
            // Cierre.
            $this->asCloser($this->uBuyer);
            $this->dlv()->closeRequest($req, 'e2e');
            $this->asAdmin();
            $this->check('[E2E-FULL] estado final CLOSED (instancia cerrada; proyección CLOSED)', $this->wfState($req) === PurchasingWorkflow::S_CLOSED
                && $this->domainState($req) === PurchasingWorkflow::S_CLOSED);
            // Invariantes finales.
            $units = (new ReceiptUnit())->find(['requests_id' => $req]);
            $once = true;
            $before = true;
            foreach ($units as $u) {
                $once = $once && (int) $u['delivery_batches_id'] > 0 && $u['physical_state'] === ReceiptUnit::PHYSICAL_DELIVERED;
                if ((int) $u['is_inventoriable'] === 1) {
                    $o = $this->outboxRow((string) $u['receipt_unit_uuid']);
                    $before = $before && ($o['status'] ?? '') === OutboxEntry::STATUS_DONE && strtotime((string) $o['processed_at']) <= strtotime((string) $u['delivered_at']) + 600;
                }
            }
            $sum = 0;
            foreach ((new DeliveryBatch())->find(['requests_id' => $req]) as $b) {
                $sum += (int) $b['units_count'];
            }
            $this->check('[E2E-FULL] EXACTAMENTE una entrega por unidad (5 unidades, Σ lotes = 5); inventariables DONE antes de entregar',
                count($units) === 5 && $once && $sum === 5 && $before);
            $trans = array_map(fn (array $h): string => (string) $h['to_code'], $this->ledger($req, HistoryEvent::EVENT_TRANSITIONED));
            $this->check('[E2E-FULL] evidencia histórica conservada: el ledger del motor recorre TODO el circuito en orden',
                $trans === ['PENDING_AREA_HEAD', 'PURCHASING', 'PENDING_FINANCE', 'APPROVED', 'IN_PURCHASE', 'PARTIALLY_RECEIVED', 'RECEIVED', 'DELIVERED', 'CLOSED']);
            $audit = [];
            foreach ((new \GlpiPlugin\Companypurchasing\Model\PurchasingEvent())->find(['requests_id' => $req]) as $e) {
                $audit[(string) $e['event']] = ($audit[(string) $e['event']] ?? 0) + 1;
            }
            $this->check('[E2E-FULL] auditoría completa (creación, envío, decisiones, cotizaciones, compra, recepciones, entregas, sincronizaciones, cierre)',
                ($audit[PurchasingEvent::EV_REQUEST_CREATED] ?? 0) === 1 && ($audit[PurchasingEvent::EV_REQUEST_SUBMITTED] ?? 0) === 1
                && ($audit[PurchasingEvent::EV_DECISION] ?? 0) === 3 && ($audit[PurchasingEvent::EV_QUOTE_SELECTED] ?? 0) === 1
                && ($audit[PurchasingEvent::EV_PURCHASE_STARTED] ?? 0) === 1 && ($audit[PurchasingEvent::EV_RECEIPT_RECORDED] ?? 0) === 2
                && ($audit[PurchasingEvent::EV_DELIVERY_RECORDED] ?? 0) === 2 && ($audit[PurchasingEvent::EV_DELIVERY_SYNCED] ?? 0) === 1
                && ($audit[PurchasingEvent::EV_REQUEST_CLOSED] ?? 0) === 1 && ($audit[PurchasingEvent::EV_HANDOFF_DONE] ?? 0) === 3);
            $events = array_unique(array_map(static fn (array $q): string => (string) $q['event'],
                array_values((new \QueuedNotification())->find(['itemtype' => Request::class, 'items_id' => $req]))));
            sort($events);
            $expected = [NotificationRules::EV_APPROVAL_PENDING, NotificationRules::EV_APPROVED, NotificationRules::EV_CLOSED, NotificationRules::EV_DELIVERED,
                         NotificationRules::EV_PURCHASE_STARTED, NotificationRules::EV_READY_FOR_DELIVERY, NotificationRules::EV_RECEIPT_COMPLETED, NotificationRules::EV_SUBMITTED];
            sort($expected);
            $this->check('[E2E-FULL] notificaciones NATIVAS encoladas para cada hecho del circuito (enviada … cerrada)', $events === $expected);
            $v = (new RequestDetailBuilder())->build($req);
            $this->check('[E2E-FULL] la vista de detalle consolida todas las secciones (cabecera, líneas, cotizaciones, historial, PDF, recepción, unidades, entrega, auditoría)',
                $v['header']['state'] === PurchasingWorkflow::S_CLOSED && count($v['lines']) === 2 && count($v['quotes']) === 2 && count($v['history']) > 9
                && count($v['pdfs']) >= 1 && count($v['receipts']) === 2 && count($v['units']) === 5 && count($v['deliveries']) === 2 && count($v['audit']) > 10
                && $v['can']['close'] === false && $v['can']['deliver'] === false);
        } finally {
            [$CFG_GLPI['use_notifications'], $CFG_GLPI['notifications_mailing']] = $saved;
        }
    }
}
