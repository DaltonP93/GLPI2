<?php

/**
 * Escenarios P2D-3 (recepción física + outbox + saga del motor) del selftest OBLIGATORIO
 * `plugins:companypurchasing:selftest`.
 *
 * TRAIT del mismo `SelftestCommand` (no un selftest paralelo que pueda omitirse): corre en el mismo proceso,
 * con el mismo fail-closed y reutilizando los fixtures P2D-2 (aprobadores, grupos, proveedores, sesiones).
 *
 * Cobertura:
 *   [UPGRADE-P2D3]      instalación P2D-2 con datos → install() ×2 (0.3.0 → 0.4.0): tablas/columnas nuevas,
 *                       datos P2D-2 intactos (huella), config/derechos/Acción automática preservados.
 *   [P2D3-PERSIST]      tablas/columnas; versión; derecho INTEGRATION; definición con fase de compra.
 *   [PURCHASE-START]    APPROVED → IN_PURCHASE; ordered_qty congelado; costo exacto pinneado; idempotente; ACL.
 *   [FREEZE]            tras iniciar: cotización/selección/precio/cantidad rechazados (fail-closed).
 *   [FREEZE-WINDOW]     inicio confirmado con la saga pendiente (motor aún APPROVED): el congelamiento local
 *                       rige igual; la recepción converge la saga antes de recibir.
 *   [RECEIVE-PARTIAL]   4 + 6 = 10 unidades, 10 UUID distintos, seriales independientes, costos por unidad
 *                       exactos (PYG) = total de la cotización, no inventariable ⇒ unidad sí / outbox no,
 *                       IN_PURCHASE → PARTIALLY_RECEIVED → RECEIVED (instancia abierta), sobre-recepción rechazada.
 *   [RECEIVE-IDEMPOTENT] misma clave ⇒ mismo lote; otra entrada con la misma clave ⇒ conflicto.
 *   [RECEIVE-VALIDATION] seriales, líneas ajenas, cantidades; UUID duplicado rechazado por UNIQUE.
 *   [RECEIVE-ATOMIC]    falla el outbox n-ésimo ⇒ ROLLBACK de lote + unidades + contadores + marcador.
 *   [RECEIVE-CRASH-SYNC] COMMIT de la recepción → caída antes del motor ⇒ pendiente; la Acción automática
 *                       NATIVA converge.
 *   [RECEIVE-CONC]      procesos REALES: 6 + 6 sobre 10 ⇒ nunca 12; misma clave concurrente ⇒ un lote;
 *                       4 + 6 concurrentes ⇒ 10 unidades.
 *   [POST-PURCHASE-INTEGRITY] deriva tras iniciar la compra ⇒ no se recibe, NO se reabre el circuito.
 *   [RECEIVE-ACL]       RIGHT_RECEIVE, multi-entidad, lectura de unidades.
 *   [OUTBOX]            payload inmutable + hash; ACL INTEGRATION (mínimo privilegio) + multi-entidad; claim
 *                       concurrente (procesos reales) disjunto; lease vencido ⇒ re-toma; token viejo rechazado;
 *                       ACK ⇒ DONE (idempotente); RETRY respeta next_retry_at; ERROR final; intentos agotados;
 *                       last_error sin secretos; payload alterado ⇒ ERROR.
 *   [LEGACY-DEF]        instancia iniciada con una versión anterior (sin fase de compra): fail-closed,
 *                       reportada por reconcile, NO mutada.
 *   [NO-SIDE-EFFECTS]   ningún Computer/Infocom/companyqr/companyintegrations creado (no SI-4, no Snipe).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Command;

use Profile_User;
use GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi;
use GlpiPlugin\Companypurchasing\Model\OutboxEntry;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\ReceiptBatch;
use GlpiPlugin\Companypurchasing\Model\ReceiptUnit;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;
use GlpiPlugin\Companypurchasing\Service\HandoffPayload;
use GlpiPlugin\Companypurchasing\Service\Money;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;
use GlpiPlugin\Companypurchasing\Service\PurchasingWorkflow;
use GlpiPlugin\Companypurchasing\Service\ReceivingService;
use GlpiPlugin\Companypurchasing\Service\ReceivingSync;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use GlpiPlugin\Companypurchasing\Service\WorkflowGateway;
use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;
use GlpiPlugin\Companyworkflow\Model\HistoryEvent;
use GlpiPlugin\Companyworkflow\Model\Instance;

trait ReceivingSelftestScenarios
{
    private int $uReceiver = 0;
    private int $uReceiverB = 0;
    private int $uIntegr = 0;
    /** Solicitud principal (10 + 3 unidades recibidas) reutilizada por [OUTBOX]. */
    private int $p2d3Main = 0;

    /** Columnas / tablas que AGREGA P2D-3 (para simular una instalación P2D-2 y comparar huellas). */
    private const P2D3_REQUEST_COLS = ['purchase_started_at', 'purchase_quotes_id', 'purchase_suppliers_id', 'cost_policies_id', 'receiving_seq', 'receiving_synced_seq'];
    private const P2D3_ITEM_COLS    = ['ordered_qty', 'received_qty', 'purchase_unit_price', 'line_cost_total'];
    private const P2D3_TABLES       = ['receipt_batches', 'receipt_units', 'inventory_outbox', 'cost_policies'];
    private const P2D3_CONFIG_KEYS  = ['cost_include_discounts', 'cost_include_taxes', 'cost_include_freight', 'outbox_max_attempts', 'outbox_max_lease_seconds', 'receipt_max_units_per_batch'];

    // ================================================================ orquestación

    private function runReceivingScenarios(): void
    {
        $this->out->writeln('== [P2D-3] recepción física + outbox + saga del motor ==');
        $this->p2d3Setup();
        $before = $this->sideEffectCounts();
        $this->scenarioP2d3Persist();
        $this->scenarioPurchaseStart();
        $this->scenarioFreezeWindow();
        $this->scenarioReceiveValidation();
        $this->scenarioReceiveAtomic();
        $this->scenarioReceiveCrashSync();
        $this->scenarioReceiveConcurrency();
        $this->scenarioPostPurchaseIntegrity();
        $this->scenarioReceiveAcl();
        $this->scenarioOutbox();
        $this->scenarioLegacyDefinition();
        $this->check('[NO-SIDE-EFFECTS] ningún Computer/Infocom/companyqr/companyintegrations creado (no SI-4, no Snipe-IT)', $this->sideEffectCounts() === $before);
    }

    private function p2d3Setup(): void
    {
        $this->asAdmin();
        foreach (self::P2D3_CONFIG_KEYS as $k) {
            if (!array_key_exists($k, $this->savedConfig)) {
                $this->savedConfig[$k] = PluginConfig::get($k);
            }
        }
        $this->uReceiver  = $this->makeActor('receiver');
        $this->uIntegr    = $this->makeActor('integr');
        $this->uReceiverB = $this->makeUser('receiverB');
        if ($this->uReceiverB > 0) {
            (new Profile_User())->add(['users_id' => $this->uReceiverB, 'profiles_id' => 1, 'entities_id' => $this->entityB, 'is_recursive' => 0]);
        }
        $this->check('[P2D-3 SETUP] receptor / receptor B / worker de integración', min($this->uReceiver, $this->uReceiverB, $this->uIntegr) > 0);
    }

    // ---- sesiones (mínimo privilegio) ----

    /** @param array<int>|null $entities */
    private function asReceiver(int $user, ?array $entities = null, bool $workflowRead = true): void
    {
        $this->applySession($user, $entities ?? [$this->entityA], [
            'plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY | Request::RIGHT_RECEIVE,
            'plugin_companyworkflow'   => $workflowRead ? READ : 0,
        ]);
    }

    /** Worker de integración: SÓLO el bit INTEGRATION (no Super-Admin, no ve solicitudes, no actúa en el motor). */
    private function asIntegration(int $user, ?array $entities = null, ?int $bits = null): void
    {
        $this->applySession($user, $entities ?? [$this->entityA], [
            'plugin_companypurchasing' => $bits ?? Request::RIGHT_INTEGRATION,
        ]);
    }

    private function recv(): ReceivingService
    {
        return new ReceivingService();
    }

    private function api(): PurchasingIntegrationApi
    {
        return new PurchasingIntegrationApi();
    }

    /**
     * Solicitud APROBADA por el circuito real (jefe → Compras con cotización → Gerencia).
     *
     * @param array<int,array{0:string,1:int,2:int}> $lines  [descripción, cantidad, inventariable]
     * @param array<int,string> $prices  precio final por posición de línea
     */
    private function approvedRequest(string $tag, array $lines, array $prices, string $disc = '0', string $tax = '0', string $freight = '0', ?int $supplier = null): int
    {
        $this->asRequester();
        $rm = new RequestManager();
        $id = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'p2d3-' . $tag . '-' . $this->suffix, 'category' => 'IT']);
        foreach ($lines as [$desc, $qty, $inv]) {
            $rm->addLine($id, ['description' => $desc, 'quantity' => (string) $qty, 'estimated_unit_price' => '1000', 'is_inventoriable' => $inv, 'category' => 'HW']);
        }
        $this->orch()->submit($id, 'envío ' . $tag);
        $this->approveAs($this->uHead1, $id);
        $q = $this->makeQuote($id, $supplier ?? $this->supA, $prices, $disc, $tax, $freight);
        $this->asBuyer($this->uBuyer);
        $this->orch()->selectQuote($id, $q);
        $this->approveAs($this->uBuyer, $id, true);
        $this->approveAs($this->uFin, $id);
        return $id;
    }

    /** Aprobada + compra iniciada (Compras). @param array<int,array{0:string,1:int,2:int}> $lines @param array<int,string> $prices */
    private function startedRequest(string $tag, array $lines, array $prices, string $disc = '0', string $tax = '0', string $freight = '0'): int
    {
        $id = $this->approvedRequest($tag, $lines, $prices, $disc, $tax, $freight);
        $this->asBuyer($this->uBuyer);
        $this->recv()->startPurchase($id, 'inicio ' . $tag);
        return $id;
    }

    /** @return array<string,mixed> */
    private function itemRow(int $itemsId): array
    {
        $it = new RequestItem();
        return $it->getFromDB($itemsId) ? $it->fields : [];
    }

    /** @return array<string,mixed> */
    private function reqRow(int $reqId): array
    {
        $r = new Request();
        return $r->getFromDB($reqId) ? $r->fields : [];
    }

    /** @param array<string,mixed> $where */
    private function countRows(string $table, array $where): int
    {
        return countElementsInTable($table, $where);
    }

    /** @return array<int,array<string,mixed>> unidades de una línea en orden de ordinal */
    private function unitsOf(int $itemsId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => ReceiptUnit::getTable(), 'WHERE' => ['items_id' => $itemsId], 'ORDER' => 'unit_index ASC']) as $row) {
            $out[] = $row;
        }
        return $out;
    }

    private function pyg(string $stored): string
    {
        return Money::ofStored($stored, 'PYG')->amount();
    }

    /** @return array<string,mixed> */
    private function outboxRow(string $uuid): array
    {
        $o = new OutboxEntry();
        return $o->getFromDBByCrit(['receipt_unit_uuid' => $uuid]) ? $o->fields : [];
    }

    /** Recuentos de tablas ajenas que P2D-3 NO debe tocar (activos, Infocom, companyqr, companyintegrations). @return array<string,int> */
    private function sideEffectCounts(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [
            'computers' => countElementsInTable(\Computer::getTable()),
            'infocoms'  => countElementsInTable(\Infocom::getTable()),
        ];
        foreach (['glpi_plugin_companyqr_%', 'glpi_plugin_companyintegrations_%'] as $like) {
            $res = $DB->doQuery("SHOW TABLES LIKE '" . $like . "'");
            while ($res !== false && ($row = $DB->fetchRow($res))) {
                $out[(string) $row[0]] = countElementsInTable((string) $row[0]);
            }
        }
        ksort($out);
        return $out;
    }

    /** Sale del modo "cron" que deja `CronTask::launch()` en la sesión CLI (GLPI no lo limpia). */
    private function leaveCronContext(): void
    {
        unset($_SESSION['glpicronuserrunning']);
    }

    /** Toma TODO lo elegible con un lease largo (aísla las pruebas de lease siguientes). */
    private function drainOutbox(): int
    {
        $this->asIntegration($this->uIntegr);
        $n = 0;
        for ($i = 0; $i < 20; $i++) {
            $got = $this->api()->claimPending('drain-' . $this->suffix, PurchasingIntegrationApi::MAX_CLAIM, 3600);
            $n += count($got);
            if ($got === []) {
                break;
            }
        }
        return $n;
    }

    // ================================================================ [UPGRADE-P2D3]

    /**
     * Instalación P2D-2 (0.3.0) con datos reales → upgrade a P2D-3 (0.4.0): se simula el esquema/config P2D-2
     * (sin tablas/columnas/claves/bit de P2D-3) sobre los datos P2D-2 creados por los escenarios anteriores y se
     * ejecuta `install()` DOS veces (como GLPI al actualizar + un reintento).
     */
    private function scenarioUpgradeP2d3(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [UPGRADE-P2D3] P2D-2 (0.3.0) con datos → install() ×2 (0.4.0) ==');
        $this->asAdmin();
        foreach (self::P2D3_CONFIG_KEYS as $k) {
            if (!array_key_exists($k, $this->savedConfig)) {
                $this->savedConfig[$k] = PluginConfig::get($k);
            }
        }
        $hook = dirname(__DIR__, 2) . '/hook.php';
        if (!function_exists('plugin_companypurchasing_install')) {
            include_once $hook;
        }
        // Una solicitud APROBADA pre-upgrade (se comprará y recibirá DESPUÉS del upgrade).
        $pre = $this->approvedRequest('preupgrade', [['Monitor', 2, 1]], ['900']);
        $this->check('[UPGRADE-P2D3] fixture: solicitud APROBADA antes del upgrade', $this->wfState($pre) === PurchasingWorkflow::S_APPROVED);

        // (1) Esquema/config/derechos como P2D-2.
        foreach (self::P2D3_TABLES as $t) {
            $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_companypurchasing_{$t}`");
        }
        foreach (self::P2D3_REQUEST_COLS as $c) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_companypurchasing_requests` DROP COLUMN `{$c}`");
        }
        foreach (self::P2D3_ITEM_COLS as $c) {
            $DB->doQuery("ALTER TABLE `glpi_plugin_companypurchasing_items` DROP COLUMN `{$c}`");
        }
        \Config::deleteConfigurationValues(PluginConfig::CONTEXT, self::P2D3_CONFIG_KEYS);
        $p2d2Full = READ | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_VIEW_ENTITY
            | Request::RIGHT_EDIT_DRAFT | Request::RIGHT_MANAGE_CONFIG | Request::RIGHT_MANAGE_PURCHASING
            | Request::RIGHT_RECEIVE | Request::RIGHT_DELIVER | Request::RIGHT_VIEW_METRICS;
        $DB->update('glpi_profilerights', ['rights' => $p2d2Full], ['profiles_id' => 4, 'name' => Request::$rightname]);
        $this->check('[UPGRADE-P2D3] esquema P2D-2 simulado (sin tablas/columnas/claves P2D-3)',
            !$this->tableExistsLive('glpi_plugin_companypurchasing_receipt_units')
            && !$this->columnExistsLive('glpi_plugin_companypurchasing_items', 'received_qty')
            && !$this->columnExistsLive('glpi_plugin_companypurchasing_requests', 'receiving_seq')
            && !array_key_exists('outbox_max_attempts', (array) \Config::getConfigurationValues(PluginConfig::CONTEXT)));

        // (2) Personalizaciones del administrador que un upgrade NO debe pisar.
        $profileId = 0;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => \Profile::getTable(), 'WHERE' => ['NOT' => ['id' => 4]], 'ORDER' => 'id ASC', 'LIMIT' => 1]) as $row) {
            $profileId = (int) $row['id'];
        }
        $origRight = $this->profileRightValue($profileId);
        $customRight = READ | Request::RIGHT_VIEW_OWN | Request::RIGHT_RECEIVE;
        $DB->update('glpi_profilerights', ['rights' => $customRight], ['profiles_id' => $profileId, 'name' => Request::$rightname]);
        $cron = new \CronTask();
        $cron->getFromDBbyName(\GlpiPlugin\Companypurchasing\Model\ProjectionTask::class, \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME);
        $cronId = (int) $cron->getID();
        $origFreq = (int) ($cron->fields['frequency'] ?? 900);
        $cron->update(['id' => $cronId, 'frequency' => 7200]);
        $configBefore = (array) \Config::getConfigurationValues(PluginConfig::CONTEXT);
        $fpBefore = $this->p2d2Fingerprint();

        // (3) Upgrade: install() ×2.
        $threw = $this->throws(function (): void {
            plugin_companypurchasing_install();
            plugin_companypurchasing_install();
        });
        $this->check('[UPGRADE-P2D3] install() ×2 sin excepción', !$threw);
        $this->check('[UPGRADE-P2D3] crea receipt_batches / receipt_units / inventory_outbox / cost_policies',
            array_reduce(self::P2D3_TABLES, fn (bool $c, string $t): bool => $c && $this->tableExistsLive("glpi_plugin_companypurchasing_{$t}"), true));
        $this->check('[UPGRADE-P2D3] agrega columnas de requests (' . implode(' / ', self::P2D3_REQUEST_COLS) . ')',
            array_reduce(self::P2D3_REQUEST_COLS, fn (bool $c, string $col): bool => $c && $this->columnExistsLive('glpi_plugin_companypurchasing_requests', $col), true));
        $this->check('[UPGRADE-P2D3] agrega columnas de items (' . implode(' / ', self::P2D3_ITEM_COLS) . ')',
            array_reduce(self::P2D3_ITEM_COLS, fn (bool $c, string $col): bool => $c && $this->columnExistsLive('glpi_plugin_companypurchasing_items', $col), true));
        $this->check('[UPGRADE-P2D3] datos P2D-2 INTACTOS (requests/items/quotes/quote_items/doc_versions/docseq/policies/integrity/events/scope_defs/numbering + instancias/historial del motor + versiones/evidencias de Firma: conteo + sha256)',
            $this->p2d2Fingerprint() === $fpBefore);
        $pr = $this->reqRow($pre);
        $this->check('[UPGRADE-P2D3] filas existentes con defaults P2D-3 neutros (sin compra iniciada, contadores 0)',
            empty($pr['purchase_started_at']) && (int) $pr['receiving_seq'] === 0 && (int) $pr['cost_policies_id'] === 0
            && (int) ($this->itemRow($this->lineIds($pre)[0])['received_qty'] ?? -1) === 0);
        $configAfter = (array) \Config::getConfigurationValues(PluginConfig::CONTEXT);
        $preserved = true;
        foreach ($configBefore as $k => $v) {
            $preserved = $preserved && array_key_exists($k, $configAfter) && (string) $configAfter[$k] === (string) $v;
        }
        $this->check('[UPGRADE-P2D3] configuración existente preservada (' . count($configBefore) . ' claves)', $preserved);
        $this->check('[UPGRADE-P2D3] sólo se siembran las claves P2D-3 ausentes (defaults)',
            array_reduce(self::P2D3_CONFIG_KEYS, fn (bool $c, string $k): bool => $c && (string) ($configAfter[$k] ?? '') === PluginConfig::DEFAULTS[$k], true));
        $dups = 0;
        foreach ($DB->request(['SELECT' => ['profiles_id'], 'COUNT' => 'n', 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => Request::$rightname], 'GROUPBY' => 'profiles_id']) as $row) {
            $dups += (int) $row['n'] > 1 ? 1 : 0;
        }
        $this->check('[UPGRADE-P2D3] derecho plugin_companypurchasing una vez por perfil (sin duplicar)', $dups === 0 && $this->profileRightValue($profileId) >= 0);
        $this->check('[UPGRADE-P2D3] derecho personalizado de un perfil preservado', $this->profileRightValue($profileId) === $customRight);
        $this->check('[UPGRADE-P2D3] Super-Admin recibe el bit nuevo INTEGRATION', ($this->profileRightValue(4) & Request::RIGHT_INTEGRATION) === Request::RIGHT_INTEGRATION);
        $cron2 = new \CronTask();
        $cron2->getFromDBbyName(\GlpiPlugin\Companypurchasing\Model\ProjectionTask::class, \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME);
        $this->check('[UPGRADE-P2D3] Acción automática única, mismo id y frecuencia preservada',
            countElementsInTable(\CronTask::getTable(), ['itemtype' => \GlpiPlugin\Companypurchasing\Model\ProjectionTask::class]) === 1
            && (int) $cron2->getID() === $cronId && (int) $cron2->fields['frequency'] === 7200);

        // (4) Después del upgrade: compra + recepción sobre la solicitud P2D-2 existente.
        $evidences = countElementsInTable(ApprovalEvidence::getTable(), ['subject_itemtype' => Request::class, 'subject_items_id' => $pre]);
        $quotes = countElementsInTable('glpi_plugin_companypurchasing_quotes', ['requests_id' => $pre]);
        $this->asBuyer($this->uBuyer);
        $st = $this->recv()->startPurchase($pre, 'post-upgrade');
        $this->asReceiver($this->uReceiver);
        $rc = $this->recv()->receive($pre, 'upg-' . $this->suffix . '-1', [['items_id' => $this->lineIds($pre)[0], 'quantity' => '2']]);
        $this->check('[UPGRADE-P2D3] solicitud P2D-2 existente: compra + recepción + outbox tras el upgrade',
            ($st['sync']['status'] ?? '') === ReceivingSync::ST_CONVERGED && count($rc['units']) === 2 && $rc['outbox'] === 2
            && $this->wfState($pre) === PurchasingWorkflow::S_RECEIVED);
        $this->check('[UPGRADE-P2D3] cotizaciones y evidencias P2D-2 de esa solicitud intactas',
            countElementsInTable(ApprovalEvidence::getTable(), ['subject_itemtype' => Request::class, 'subject_items_id' => $pre]) === $evidences
            && countElementsInTable('glpi_plugin_companypurchasing_quotes', ['requests_id' => $pre]) === $quotes);

        // Restaurar personalizaciones de la prueba.
        $this->asAdmin();
        if ($origRight >= 0) {
            $DB->update('glpi_profilerights', ['rights' => $origRight], ['profiles_id' => $profileId, 'name' => Request::$rightname]);
        }
        (new \CronTask())->update(['id' => $cronId, 'frequency' => $origFreq]);
    }

    private function profileRightValue(int $profileId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['SELECT' => 'rights', 'FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $profileId, 'name' => Request::$rightname]]) as $row) {
            return (int) $row['rights'];
        }
        return -1;
    }

    /**
     * Huella (conteo + sha256) de los datos P2D-2: tablas propias sin las columnas P2D-3, instancias/historial
     * del motor de las solicitudes y versiones/evidencias de Firma de las solicitudes.
     *
     * @return array<string,string>
     */
    private function p2d2Fingerprint(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        $skip = ['glpi_plugin_companypurchasing_requests' => self::P2D3_REQUEST_COLS, 'glpi_plugin_companypurchasing_items' => self::P2D3_ITEM_COLS];
        foreach (['requests', 'items', 'numbering', 'events', 'scope_defs', 'quotes', 'quote_items', 'doc_versions', 'docseq', 'policies', 'integrity'] as $t) {
            $table = 'glpi_plugin_companypurchasing_' . $t;
            $out[$t] = $this->tableHash($table, [], $skip[$table] ?? []);
        }
        $out['wf_instances'] = $this->tableHash(Instance::getTable(), ['itemtype' => Request::class], []);
        $inst = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Instance::getTable(), 'WHERE' => ['itemtype' => Request::class]]) as $row) {
            $inst[] = (int) $row['id'];
        }
        $out['wf_history'] = $inst === [] ? '0' : $this->tableHash(HistoryEvent::getTable(), ['instances_id' => $inst], []);
        $out['sig_versions'] = $this->tableHash(DocumentVersion::getTable(), ['subject_itemtype' => Request::class], []);
        $out['sig_evidences'] = $this->tableHash(ApprovalEvidence::getTable(), ['subject_itemtype' => Request::class], []);
        return $out;
    }

    /** @param array<string,mixed> $where @param array<int,string> $exclude */
    private function tableHash(string $table, array $where, array $exclude): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $ctx = hash_init('sha256');
        $n = 0;
        $crit = ['FROM' => $table, 'ORDER' => 'id ASC'];
        if ($where !== []) {
            $crit['WHERE'] = $where;
        }
        foreach ($DB->request($crit) as $row) {
            foreach ($exclude as $c) {
                unset($row[$c]);
            }
            hash_update($ctx, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
            $n++;
        }
        return $n . ':' . hash_final($ctx);
    }

    // ================================================================ [P2D3-PERSIST]

    private function scenarioP2d3Persist(): void
    {
        $this->out->writeln('== [P2D3-PERSIST] esquema, versión, derecho y definición ==');
        $this->check('[P2D3-PERSIST] versión del plugin 0.4.0', defined('PLUGIN_COMPANYPURCHASING_VERSION') && PLUGIN_COMPANYPURCHASING_VERSION === '0.4.0');
        $this->check('[P2D3-PERSIST] tablas P2D-3', array_reduce(self::P2D3_TABLES, fn (bool $c, string $t): bool => $c && $this->tableExistsLive("glpi_plugin_companypurchasing_{$t}"), true));
        $this->check('[P2D3-PERSIST] unit_cost DECIMAL exacto (no float)', $this->columnType('glpi_plugin_companypurchasing_receipt_units', 'unit_cost') === 'decimal(20,6)'
            && $this->columnType('glpi_plugin_companypurchasing_items', 'line_cost_total') === 'decimal(20,6)');
        $this->check('[P2D3-PERSIST] literales puros == modelo (estados del outbox)', OutboxEntry::STATUS_PENDING === 'PENDING' && OutboxEntry::STATUS_LEASED === 'LEASED'
            && OutboxEntry::STATUS_RETRY === 'RETRY' && OutboxEntry::STATUS_DONE === 'DONE' && OutboxEntry::STATUS_ERROR === 'ERROR');
        $gw = new WorkflowGateway();
        $def = $gw->activeDefinition(PluginConfig::workflowCode());
        $hasAll = false;
        if ($def !== null) {
            $n = 0;
            foreach ([PurchasingWorkflow::A_START_PURCHASE, PurchasingWorkflow::A_RECEIVE_PARTIAL, PurchasingWorkflow::A_RECEIVE_COMPLETE] as $a) {
                $n += count((new \GlpiPlugin\Companyworkflow\Model\Transition())->find(['workflowdefs_id' => (int) $def->getID(), 'action' => $a]));
            }
            $hasAll = $n === 4;
        }
        $this->check('[P2D3-PERSIST] la definición publicada es una VERSIÓN con start_purchase/receive_partial/receive_complete', $hasAll);
    }

    private function columnType(string $table, string $column): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $res = $DB->doQuery("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        $row = $res !== false ? $DB->fetchAssoc($res) : null;
        return strtolower((string) ($row['Type'] ?? ''));
    }

    // ================================================================ [PURCHASE-START] + [FREEZE] + [RECEIVE-PARTIAL] + [RECEIVE-IDEMPOTENT]

    private function scenarioPurchaseStart(): void
    {
        $this->out->writeln('== [PURCHASE-START] APPROVED → IN_PURCHASE (congelamiento + costo pinneado) ==');
        // Política de costo que INCLUYE ajustes de cabecera (prorrateo explícito) — se pinnea al iniciar.
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['cost_include_discounts' => '1', 'cost_include_taxes' => '1', 'cost_include_freight' => '1']);
        $req = $this->approvedRequest('partial', [['Notebook', 10, 1], ['Licencia', 3, 0]], ['1400', '2300'], '300', '550', '100');
        [$l1, $l2] = $this->lineIds($req);
        $this->check('[PURCHASE-START] fixture: APROBADA (motor)', $this->wfState($req) === PurchasingWorkflow::S_APPROVED);

        $this->asReceiver($this->uReceiver);
        $this->check('[PURCHASE-START] recibir ANTES de iniciar la compra → fail-closed', $this->throws(fn () => $this->recv()->receive($req, 'early-' . $this->suffix, [['items_id' => $l1, 'quantity' => '1']])));
        $this->check('[PURCHASE-START] iniciar sin MANAGE_PURCHASING (receptor) → denegado', $this->throws(fn () => $this->recv()->startPurchase($req)));
        $this->asIntegration($this->uIntegr);
        $this->check('[PURCHASE-START] iniciar como worker de integración → denegado', $this->throws(fn () => $this->recv()->startPurchase($req)));

        $this->asBuyer($this->uBuyer);
        $st = $this->recv()->startPurchase($req, 'orden de compra');
        $r = $this->reqRow($req);
        $this->check('[PURCHASE-START] motor APPROVED → IN_PURCHASE (saga) y domain_state proyectado',
            $st['started_now'] && ($st['sync']['status'] ?? '') === ReceivingSync::ST_CONVERGED
            && $this->wfState($req) === PurchasingWorkflow::S_IN_PURCHASE && $this->domainState($req) === PurchasingWorkflow::S_IN_PURCHASE);
        $this->check('[PURCHASE-START] marcador durable sincronizado (receiving_synced_seq = receiving_seq)', (int) $r['receiving_seq'] >= 1 && (int) $r['receiving_synced_seq'] === (int) $r['receiving_seq']);
        $i1 = $this->itemRow($l1);
        $i2 = $this->itemRow($l2);
        $this->check('[PURCHASE-START] ordered_qty CONGELADO = cantidad aprobada (10 / 3); received_qty 0', (int) $i1['ordered_qty'] === 10 && (int) $i2['ordered_qty'] === 3
            && (int) $i1['received_qty'] === 0 && (int) $i2['received_qty'] === 0);
        $this->check('[PURCHASE-START] costo de línea exacto (prorrateo por valor, mayor resto): 14234 / 7016 (Σ = total 21250)',
            $this->pyg((string) $i1['line_cost_total']) === '14234' && $this->pyg((string) $i2['line_cost_total']) === '7016'
            && $this->pyg((string) $i1['purchase_unit_price']) === '1400');
        $this->check('[PURCHASE-START] proveedor/cotización de la compra y política de costo pinneados', (int) $r['purchase_suppliers_id'] === $this->supA
            && (int) $r['purchase_quotes_id'] > 0 && (int) $r['cost_policies_id'] > 0 && !empty($r['purchase_started_at']));
        $again = $this->recv()->startPurchase($req);
        $this->check('[PURCHASE-START] reintento idempotente (sin segundo inicio ni segundo evento)', !$again['started_now']
            && $this->countEvents($req, PurchasingEvent::EV_PURCHASE_STARTED) === 1 && $this->countTransitionsTo($req, PurchasingWorkflow::S_IN_PURCHASE) === 1);

        // Un cambio POSTERIOR de configuración no altera la política pinneada.
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['cost_include_discounts' => '0', 'cost_include_taxes' => '0', 'cost_include_freight' => '0']);

        $this->scenarioFreeze($req, $l1);
        $this->scenarioReceivePartial($req, $l1, $l2);
        $this->scenarioReceiveIdempotent($req, $l1);
        $this->p2d3Main = $req;
    }

    /** @return array<int,string> seriales deterministas del primer lote del escenario principal */
    private function mainSerials(): array
    {
        return ['SN-A-' . $this->suffix, 'SN-B-' . $this->suffix, 'SN-C-' . $this->suffix, 'SN-D-' . $this->suffix];
    }

    // ================================================================ [FREEZE]

    private function scenarioFreeze(int $req, int $l1): void
    {
        $this->out->writeln('== [FREEZE] tras iniciar la compra: nada del contenido aprobado cambia ==');
        $this->asBuyer($this->uBuyer);
        $r = $this->reqRow($req);
        $quoteId = (int) $r['quotes_id_selected'];
        $costBefore = (string) $this->itemRow($l1)['line_cost_total'];
        $this->check('[FREEZE] crear otra cotización → rechazado', $this->throws(fn () => $this->orch()->createQuote($req, ['suppliers_id' => $this->supA2, 'lines' => []])));
        $this->check('[FREEZE] cambiar impuestos/precio de la cotización seleccionada → rechazado', $this->throws(fn () => $this->orch()->updateQuote($quoteId, ['taxes' => '999'])));
        $this->check('[FREEZE] cambiar la selección → rechazado', $this->throws(fn () => $this->orch()->selectQuote($req, $quoteId)));
        $this->check('[FREEZE] enmendar la cantidad → rechazado', $this->throws(fn () => $this->orch()->amendLineQuantity($l1, '11')));
        $q = new \GlpiPlugin\Companypurchasing\Model\Quote();
        $q->getFromDB($quoteId);
        $i1 = $this->itemRow($l1);
        $this->check('[FREEZE] nada cambió (cantidad, ordered_qty, costo de línea, impuestos de la cotización)',
            (int) $i1['quantity'] === 10 && (int) $i1['ordered_qty'] === 10 && (string) $i1['line_cost_total'] === $costBefore
            && $this->pyg((string) $q->fields['taxes']) === '550');
        $e = $this->orch()->enforceIntegrity($req);
        $this->check('[FREEZE] la integridad sigue limpia y el circuito NO se reabre (motor IN_PURCHASE)',
            $e['invalidated'] === [] && $e['error'] === null && $this->orch()->integrityStatus($req)['clean']
            && $this->wfState($req) === PurchasingWorkflow::S_IN_PURCHASE);
    }

    // ================================================================ [FREEZE-WINDOW]

    /**
     * Ventana real "compra iniciada localmente, motor todavía en APPROVED" (saga pendiente: aquí, quien inicia no
     * tiene derecho en el motor). APPROVED está en quote_states/amend_states de la política: SÓLO el congelamiento
     * por `purchase_started_at` impide cambiar lo aprobado. Luego la recepción converge la saga antes de recibir.
     */
    private function scenarioFreezeWindow(): void
    {
        $this->out->writeln('== [FREEZE-WINDOW] compra iniciada con la saga del motor pendiente (motor aún APPROVED) ==');
        $req = $this->approvedRequest('fwin', [['Proyector', 2, 1]], ['3000']);
        $l = $this->lineIds($req)[0];
        $this->applySession($this->uBuyer, [$this->entityA], [
            'plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY | Request::RIGHT_MANAGE_PURCHASING,
            'plugin_companyworkflow'   => 0,
        ]);
        $st = $this->recv()->startPurchase($req);
        $r = $this->reqRow($req);
        $this->check('[FREEZE-WINDOW] inicio confirmado localmente; saga PENDIENTE (motor APPROVED, marcador sin sincronizar)',
            $st['started_now'] && ($st['sync']['status'] ?? '') === ReceivingSync::ST_PENDING && !empty($r['purchase_started_at'])
            && $this->wfState($req) === PurchasingWorkflow::S_APPROVED && (int) $r['receiving_seq'] > (int) $r['receiving_synced_seq']);
        $this->asBuyer($this->uBuyer);
        $quoteId = (int) $r['quotes_id_selected'];
        $this->check('[FREEZE-WINDOW] en APPROVED (estado comercial de la política) igual se rechaza cotizar/seleccionar/cambiar precio/enmendar',
            $this->throws(fn () => $this->orch()->updateQuote($quoteId, ['taxes' => '1']))
            && $this->throws(fn () => $this->orch()->selectQuote($req, $quoteId))
            && $this->throws(fn () => $this->orch()->createQuote($req, ['suppliers_id' => $this->supA2, 'lines' => []]))
            && $this->throws(fn () => $this->orch()->amendLineQuantity($l, '3')));
        $this->check('[FREEZE-WINDOW] nada cambió', (int) $this->itemRow($l)['quantity'] === 2 && (int) $this->itemRow($l)['ordered_qty'] === 2
            && $this->countRows('glpi_plugin_companypurchasing_quotes', ['requests_id' => $req]) === 1);
        $this->asReceiver($this->uReceiver);
        $rc = $this->recv()->receive($req, 'fwin-' . $this->suffix, [['items_id' => $l, 'quantity' => '1']]);
        $r = $this->reqRow($req);
        $this->check('[FREEZE-WINDOW] la recepción converge primero la saga (start_purchase) y luego recibe: PARTIALLY_RECEIVED',
            $rc['status'] === 'recorded' && $this->wfState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED
            && $this->countTransitionsTo($req, PurchasingWorkflow::S_IN_PURCHASE) === 1 && (int) $r['receiving_synced_seq'] === (int) $r['receiving_seq']);
    }

    // ================================================================ [RECEIVE-PARTIAL]

    private function scenarioReceivePartial(int $req, int $l1, int $l2): void
    {
        $this->out->writeln('== [RECEIVE-PARTIAL] 4 + 6 = 10 unidades; no inventariable; costos exactos ==');
        $this->asReceiver($this->uReceiver);
        $a = $this->recv()->receive($req, 'rp1-' . $this->suffix, [['items_id' => $l1, 'quantity' => '4', 'serials' => $this->mainSerials()]], ['notes' => 'remito 001']);
        $this->check('[RECEIVE-PARTIAL] lote 1: 4 unidades + 4 handoffs (inventariable)', $a['status'] === 'recorded' && count($a['units']) === 4 && $a['outbox'] === 4);
        $this->check('[RECEIVE-PARTIAL] motor IN_PURCHASE → PARTIALLY_RECEIVED (saga tras el COMMIT) + domain_state',
            ($a['sync']['status'] ?? '') === ReceivingSync::ST_CONVERGED && $this->wfState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED
            && $this->domainState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED);
        $i1 = $this->itemRow($l1);
        $this->check('[RECEIVE-PARTIAL] contadores: recibido 4, pendiente 6 (derivado = ordenado − recibido)', (int) $i1['received_qty'] === 4 && (int) $i1['ordered_qty'] - (int) $i1['received_qty'] === 6);

        $b = $this->recv()->receive($req, 'rp2-' . $this->suffix, [['items_id' => $l1, 'quantity' => '6']]);
        $this->check('[RECEIVE-PARTIAL] lote 2: 6 unidades + 6 handoffs; sigue PARTIALLY_RECEIVED (falta la línea 2)',
            $b['status'] === 'recorded' && count($b['units']) === 6 && $b['outbox'] === 6 && $this->wfState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED);
        $units = $this->unitsOf($l1);
        $uuids = array_map(static fn (array $u): string => (string) $u['receipt_unit_uuid'], $units);
        $this->check('[RECEIVE-PARTIAL] 4 + 6 = EXACTAMENTE 10 unidades de la línea (received_qty 10)', count($units) === 10 && (int) $this->itemRow($l1)['received_qty'] === 10);
        $this->check('[RECEIVE-PARTIAL] 10 receipt_unit_uuid DISTINTOS, UUID v4 (identidad canónica)', count(array_unique($uuids)) === 10
            && array_reduce($uuids, static fn (bool $c, string $u): bool => $c && preg_match(HandoffPayload::UUID_PATTERN, $u) === 1, true)
            && $uuids === array_merge($a['units'], $b['units']));
        $this->check('[RECEIVE-PARTIAL] identidad por items_id (no line_no); unit_index 1..10 sólo display',
            array_map(static fn (array $u): int => (int) $u['unit_index'], $units) === range(1, 10)
            && array_reduce($units, static fn (bool $c, array $u): bool => $c && (int) $u['items_id'] === $l1, true));
        $serials = array_map(static fn (array $u): ?string => $u['serial'] !== null ? (string) $u['serial'] : null, $units);
        $this->check('[RECEIVE-PARTIAL] seriales independientes por unidad (4 distintos; las demás sin serial)',
            array_slice($serials, 0, 4) === $this->mainSerials() && array_slice($serials, 4) === array_fill(0, 6, null));
        $costs = array_map(fn (array $u): string => $this->pyg((string) $u['unit_cost']), $units);
        $this->check('[RECEIVE-PARTIAL] unit_cost exacto PYG: 1424 ×4 + 1423 ×6 = 14234 (nunca total ÷ cantidad)',
            $costs === array_merge(array_fill(0, 4, '1424'), array_fill(0, 6, '1423')));
        $this->check('[RECEIVE-PARTIAL] política pinneada: el cambio de configuración posterior NO alteró el costo (lotes con la política de la solicitud)',
            $this->countRows(ReceiptBatch::getTable(), ['requests_id' => $req, 'cost_policies_id' => (int) $this->reqRow($req)['cost_policies_id']]) === 2);

        $c = $this->recv()->receive($req, 'rp3-' . $this->suffix, [['items_id' => $l2, 'quantity' => '3']]);
        $u2 = $this->unitsOf($l2);
        $this->check('[RECEIVE-PARTIAL] línea NO inventariable: 3 unidades sí, handoff NO', count($c['units']) === 3 && $c['outbox'] === 0
            && count($u2) === 3 && $this->countRows(OutboxEntry::getTable(), ['requests_id' => $req]) === 10);
        $this->check('[RECEIVE-PARTIAL] costos de la línea 2: 2339, 2339, 2338 (Σ 7016)', array_map(fn (array $u): string => $this->pyg((string) $u['unit_cost']), $u2) === ['2339', '2339', '2338']);
        $total = 0;
        foreach (array_merge($units, $u2) as $u) {
            $total += (int) $this->pyg((string) $u['unit_cost']);
        }
        $this->check('[RECEIVE-PARTIAL] Σ unit_cost de todas las unidades = total de la cotización (21250, exacto)', $total === 21250);
        $inst = (new WorkflowGateway())->loadInstance($this->instanceId($req));
        $this->check('[RECEIVE-PARTIAL] todas las líneas completas ⇒ RECEIVED; la instancia sigue ABIERTA (no se cierra en RECEIVED)',
            $this->wfState($req) === PurchasingWorkflow::S_RECEIVED && $inst !== null && $inst->isOpen());
        $this->check('[RECEIVE-PARTIAL] motor: exactamente UNA transición a PARTIALLY_RECEIVED y UNA a RECEIVED',
            $this->countTransitionsTo($req, PurchasingWorkflow::S_PARTIALLY_RECEIVED) === 1 && $this->countTransitionsTo($req, PurchasingWorkflow::S_RECEIVED) === 1);
        $this->check('[RECEIVE-PARTIAL] recibir de más ⇒ rechazado (fail-closed), sin cambios',
            $this->throws(fn () => $this->recv()->receive($req, 'rp4-' . $this->suffix, [['items_id' => $l1, 'quantity' => '1']]))
            && (int) $this->itemRow($l1)['received_qty'] === 10 && $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 13);
        $this->check('[RECEIVE-PARTIAL] auditoría: 3 eventos receipt.recorded (actor + lote)', $this->countEvents($req, PurchasingEvent::EV_RECEIPT_RECORDED) === 3);
        $batch = new ReceiptBatch();
        $batch->getFromDB($a['batch_id']);
        $this->check('[RECEIVE-PARTIAL] el lote guarda actor, fecha, notas y la clave de idempotencia',
            (int) $batch->fields['actor_users_id'] === $this->uReceiver && !empty($batch->fields['received_at'])
            && (string) $batch->fields['notes'] === 'remito 001' && (string) $batch->fields['idempotency_key'] === 'rp1-' . $this->suffix
            && (int) $batch->fields['units_count'] === 4);
        $this->p2d3First = $a;
    }

    /** @var array<string,mixed> primer lote del escenario principal (para [RECEIVE-IDEMPOTENT]) */
    private array $p2d3First = [];

    // ================================================================ [RECEIVE-IDEMPOTENT]

    private function scenarioReceiveIdempotent(int $req, int $l1): void
    {
        $this->out->writeln('== [RECEIVE-IDEMPOTENT] misma operación ⇒ mismo lote ==');
        $this->asReceiver($this->uReceiver);
        $again = $this->recv()->receive($req, 'rp1-' . $this->suffix, [['items_id' => $l1, 'quantity' => '4', 'serials' => $this->mainSerials()]], ['notes' => 'remito 001']);
        $this->check('[RECEIVE-IDEMPOTENT] misma idempotency_key + misma entrada ⇒ el MISMO lote y las MISMAS unidades',
            $again['status'] === 'replayed' && $again['batch_id'] === (int) ($this->p2d3First['batch_id'] ?? -1) && $again['units'] === ($this->p2d3First['units'] ?? []));
        $this->check('[RECEIVE-IDEMPOTENT] sin lotes/unidades/handoffs nuevos',
            $this->countRows(ReceiptBatch::getTable(), ['requests_id' => $req]) === 3 && $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 13
            && $this->countRows(OutboxEntry::getTable(), ['requests_id' => $req]) === 10 && (int) $this->itemRow($l1)['received_qty'] === 10);
        $this->check('[RECEIVE-IDEMPOTENT] misma clave con OTRA entrada ⇒ conflicto (fail-closed)',
            $this->throws(fn () => $this->recv()->receive($req, 'rp1-' . $this->suffix, [['items_id' => $l1, 'quantity' => '3']])));
        $this->check('[RECEIVE-IDEMPOTENT] idempotency_key obligatoria y con formato válido',
            $this->throws(fn () => $this->recv()->receive($req, '', [['items_id' => $l1, 'quantity' => '1']]))
            && $this->throws(fn () => $this->recv()->receive($req, 'short', [['items_id' => $l1, 'quantity' => '1']]))
            && $this->throws(fn () => $this->recv()->receive($req, "bad key ' OR 1=1", [['items_id' => $l1, 'quantity' => '1']])));
    }

    // ================================================================ [RECEIVE-VALIDATION]

    private function scenarioReceiveValidation(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [RECEIVE-VALIDATION] entradas inválidas y UUID duplicado ==');
        $req = $this->startedRequest('valid', [['Router', 5, 1]], ['700']);
        $l = $this->lineIds($req)[0];
        $foreign = $this->lineIds($this->p2d3Main)[0];
        $this->asReceiver($this->uReceiver);
        $k = fn (string $t): string => 'val-' . $t . '-' . $this->suffix;
        $this->check('[RECEIVE-VALIDATION] cantidad 0 / decimal / negativa ⇒ rechazada',
            $this->throws(fn () => $this->recv()->receive($req, $k('q0'), [['items_id' => $l, 'quantity' => '0']]))
            && $this->throws(fn () => $this->recv()->receive($req, $k('q1'), [['items_id' => $l, 'quantity' => '1.5']]))
            && $this->throws(fn () => $this->recv()->receive($req, $k('q2'), [['items_id' => $l, 'quantity' => '-1']])));
        $this->check('[RECEIVE-VALIDATION] seriales ≠ unidades ⇒ rechazado', $this->throws(fn () => $this->recv()->receive($req, $k('s1'), [['items_id' => $l, 'quantity' => '2', 'serials' => ['X1']]])));
        $this->check('[RECEIVE-VALIDATION] seriales repetidos en el lote ⇒ rechazado', $this->throws(fn () => $this->recv()->receive($req, $k('s2'), [['items_id' => $l, 'quantity' => '2', 'serials' => ['X1', 'X1']]])));
        $this->check('[RECEIVE-VALIDATION] línea de OTRA solicitud ⇒ rechazada', $this->throws(fn () => $this->recv()->receive($req, $k('f'), [['items_id' => $foreign, 'quantity' => '1']])));
        $this->check('[RECEIVE-VALIDATION] línea repetida en el lote ⇒ rechazada', $this->throws(fn () => $this->recv()->receive($req, $k('d'), [['items_id' => $l, 'quantity' => '1'], ['items_id' => $l, 'quantity' => '1']])));
        $ok = $this->recv()->receive($req, $k('ok'), [['items_id' => $l, 'quantity' => '1', 'serials' => ['SER-X-' . $this->suffix]]]);
        $this->check('[RECEIVE-VALIDATION] serial ya recibido en la línea ⇒ rechazado',
            $ok['status'] === 'recorded' && $this->throws(fn () => $this->recv()->receive($req, $k('dup'), [['items_id' => $l, 'quantity' => '1', 'serials' => ['SER-X-' . $this->suffix]]])));
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['receipt_max_units_per_batch' => '2']);
        $this->asReceiver($this->uReceiver);
        $capped = $this->throws(fn () => $this->recv()->receive($req, $k('cap'), [['items_id' => $l, 'quantity' => '3']]));
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['receipt_max_units_per_batch' => PluginConfig::DEFAULTS['receipt_max_units_per_batch']]);
        $this->check('[RECEIVE-VALIDATION] tope de unidades por lote (configurable) ⇒ rechazado', $capped);
        $this->check('[RECEIVE-VALIDATION] ninguna entrada inválida persistió nada (1 lote, 1 unidad, recibido 1)',
            $this->countRows(ReceiptBatch::getTable(), ['requests_id' => $req]) === 1 && $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 1
            && (int) $this->itemRow($l)['received_qty'] === 1);

        // UNIQUE(receipt_unit_uuid): la BD rechaza una segunda unidad/handoff con la misma identidad.
        $u = $this->unitsOf($l)[0];
        $now = date('Y-m-d H:i:s');
        $dupUnit = $this->throws(function () use ($DB, $u, $req, $l, $now): void {
            $DB->insert(ReceiptUnit::getTable(), [
                'receipt_batches_id' => (int) $u['receipt_batches_id'], 'requests_id' => $req, 'items_id' => $l, 'entities_id' => $this->entityA,
                'receipt_unit_uuid' => (string) $u['receipt_unit_uuid'], 'currency_code' => 'PYG', 'unit_cost' => '700', 'unit_index' => 99, 'date_creation' => $now,
            ]);
        });
        $o = $this->outboxRow((string) $u['receipt_unit_uuid']);
        $dupOutbox = $this->throws(function () use ($DB, $o, $now): void {
            $DB->insert(OutboxEntry::getTable(), [
                'receipt_unit_uuid' => (string) $o['receipt_unit_uuid'], 'receipt_units_id' => (int) $o['receipt_units_id'], 'requests_id' => (int) $o['requests_id'],
                'entities_id' => (int) $o['entities_id'], 'payload_json' => (string) $o['payload_json'], 'payload_sha256' => (string) $o['payload_sha256'], 'date_creation' => $now,
            ]);
        });
        $this->check('[RECEIVE-VALIDATION] receipt_unit_uuid duplicado RECHAZADO por UNIQUE (unidad y handoff)',
            $o !== [] && $dupUnit && $dupOutbox && $this->countRows(ReceiptUnit::getTable(), ['receipt_unit_uuid' => (string) $u['receipt_unit_uuid']]) === 1);
    }

    // ================================================================ [RECEIVE-ATOMIC]

    private function scenarioReceiveAtomic(): void
    {
        $this->out->writeln('== [RECEIVE-ATOMIC] falla un handoff ⇒ ROLLBACK de todo ==');
        $req = $this->startedRequest('atomic', [['Switch', 5, 1]], ['800']);
        $l = $this->lineIds($req)[0];
        $seq = (int) $this->reqRow($req)['receiving_seq'];
        $failing = new class extends ReceivingService {
            protected function beforeOutboxInsert(string $uuid, int $n): void
            {
                if ($n === 3) {
                    throw new \RuntimeException('fallo inyectado creando el handoff #3');
                }
            }
        };
        $this->asReceiver($this->uReceiver);
        $key = 'atomic-' . $this->suffix;
        $threw = $this->throws(fn () => $failing->receive($req, $key, [['items_id' => $l, 'quantity' => '5']]));
        $this->check('[RECEIVE-ATOMIC] fallo inyectado en el 3.er handoff ⇒ excepción', $threw);
        $this->check('[RECEIVE-ATOMIC] ROLLBACK: sin lote, sin unidades, sin handoffs',
            $this->countRows(ReceiptBatch::getTable(), ['idempotency_key' => $key]) === 0 && $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 0
            && $this->countRows(OutboxEntry::getTable(), ['requests_id' => $req]) === 0);
        $this->check('[RECEIVE-ATOMIC] contadores y marcador intactos (recibido 0, receiving_seq igual), sin evento de recepción',
            (int) $this->itemRow($l)['received_qty'] === 0 && (int) $this->reqRow($req)['receiving_seq'] === $seq
            && $this->countEvents($req, PurchasingEvent::EV_RECEIPT_RECORDED) === 0);
        $this->check('[RECEIVE-ATOMIC] el motor no se movió (IN_PURCHASE)', $this->wfState($req) === PurchasingWorkflow::S_IN_PURCHASE);
        $ok = $this->recv()->receive($req, $key, [['items_id' => $l, 'quantity' => '5']]);
        $this->check('[RECEIVE-ATOMIC] la MISMA clave luego se registra entera (nada quedó a medias): 5 unidades + 5 handoffs → RECEIVED',
            $ok['status'] === 'recorded' && count($ok['units']) === 5 && $ok['outbox'] === 5 && $this->wfState($req) === PurchasingWorkflow::S_RECEIVED);
    }

    // ================================================================ [RECEIVE-CRASH-SYNC]

    private function scenarioReceiveCrashSync(): void
    {
        $this->out->writeln('== [RECEIVE-CRASH-SYNC] COMMIT de la recepción → caída antes del motor → convergencia ==');
        $req = $this->startedRequest('crash', [['UPS', 4, 1]], ['500']);
        $l = $this->lineIds($req)[0];
        $crash = new class extends ReceivingService {
            protected function afterReceiptCommit(int $batchId): void
            {
                throw new \RuntimeException('caída simulada tras el COMMIT de la recepción');
            }
        };
        $this->asReceiver($this->uReceiver);
        $threw = $this->throws(fn () => $crash->receive($req, 'crash-' . $this->suffix, [['items_id' => $l, 'quantity' => '2']]));
        $r = $this->reqRow($req);
        $this->check('[RECEIVE-CRASH-SYNC] la recepción confirmada PERSISTE (2 unidades + 2 handoffs, recibido 2)',
            $threw && $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 2 && $this->countRows(OutboxEntry::getTable(), ['requests_id' => $req]) === 2
            && (int) $this->itemRow($l)['received_qty'] === 2);
        $this->check('[RECEIVE-CRASH-SYNC] el motor NO se movió (IN_PURCHASE) y el marcador durable queda PENDIENTE',
            $this->wfState($req) === PurchasingWorkflow::S_IN_PURCHASE && (int) $r['receiving_seq'] > (int) $r['receiving_synced_seq']);

        // Sin autoridad sobre el motor, la reconciliación REPORTA (no oculta) la sincronización pendiente.
        $this->asReceiver($this->uReceiver, null, false);
        $rep = $this->orch()->reconcile($req);
        $this->check('[RECEIVE-CRASH-SYNC] reconcile sin derecho del motor ⇒ REPORTA recepcion_pendiente (sin mutar)',
            $rep['receiving_pending'] === true && $this->wfState($req) === PurchasingWorkflow::S_IN_PURCHASE);

        // La Acción automática NATIVA (contexto de sistema de la CronTask) converge.
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['reconcile_cursor' => '0']);
        $ran = \CronTask::launch(-\CronTask::MODE_EXTERNAL, 1, \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME);
        $this->leaveCronContext();
        $this->asAdmin();
        $r = $this->reqRow($req);
        $this->check('[RECEIVE-CRASH-SYNC] la Acción automática nativa converge: PARTIALLY_RECEIVED y marcador sincronizado',
            $ran === \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME && $this->wfState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED
            && (int) $r['receiving_synced_seq'] === (int) $r['receiving_seq'] && $this->domainState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED);
        $this->check('[RECEIVE-CRASH-SYNC] auditoría de la convergencia (receiving.synced) y una sola transición', $this->countEvents($req, PurchasingEvent::EV_RECEIVING_SYNCED) === 2
            && $this->countTransitionsTo($req, PurchasingWorkflow::S_PARTIALLY_RECEIVED) === 1);
        $this->check('[RECEIVE-CRASH-SYNC] nunca se deshicieron unidades para seguir al motor', $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 2);
        $this->asReceiver($this->uReceiver);
        $rp = $this->recv()->receive($req, 'crash-' . $this->suffix, [['items_id' => $l, 'quantity' => '2']]);
        $this->check('[RECEIVE-CRASH-SYNC] el cliente reintenta con la misma clave ⇒ mismo lote (no duplica)',
            $rp['status'] === 'replayed' && $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 2);
    }

    // ================================================================ [RECEIVE-CONC]

    private function scenarioReceiveConcurrency(): void
    {
        $this->out->writeln('== [RECEIVE-CONC] recepciones concurrentes (procesos reales) ==');
        $req = $this->startedRequest('conc', [['Tablet', 10, 1], ['Dock', 10, 1], ['Cable', 4, 1]], ['300', '200', '50']);
        [$la, $lb, $lc] = $this->lineIds($req);
        $base = 'php bin/console plugins:companypurchasing:concurrency-probe --op=receive --request=' . $req . ' --user=' . $this->uReceiver . ' --no-interaction';

        $outs = $this->runParallel([$base . ' --item=' . $la . ' --qty=6 --key=conc-a-' . $this->suffix, $base . ' --item=' . $la . ' --qty=6 --key=conc-b-' . $this->suffix]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $ok = count(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'OK:recorded:6')));
        $over = count(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'ERR:') && str_contains($o, 'sobre-recepción')));
        $this->check('[RECEIVE-CONC] 6 + 6 sobre 10: exactamente UNO gana; el otro falla de forma CONTROLADA (sobre-recepción)', $ok === 1 && $over === 1);
        $this->check('[RECEIVE-CONC] received_qty = 6, NUNCA 12 (6 unidades, 6 handoffs)', (int) $this->itemRow($la)['received_qty'] === 6
            && count($this->unitsOf($la)) === 6);

        $outs = $this->runParallel([$base . ' --item=' . $lb . ' --qty=4 --key=conc-4-' . $this->suffix, $base . ' --item=' . $lb . ' --qty=6 --key=conc-6-' . $this->suffix]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $ub = $this->unitsOf($lb);
        $this->check('[RECEIVE-CONC] lote 4 + lote 6 CONCURRENTES ⇒ ambos OK; EXACTAMENTE 10 unidades con 10 UUID distintos',
            count(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'OK:recorded:'))) === 2
            && (int) $this->itemRow($lb)['received_qty'] === 10 && count($ub) === 10
            && count(array_unique(array_map(static fn (array $u): string => (string) $u['receipt_unit_uuid'], $ub))) === 10
            && array_map(static fn (array $u): int => (int) $u['unit_index'], $ub) === range(1, 10));

        $same = $base . ' --item=' . $lc . ' --qty=2 --key=conc-same-' . $this->suffix;
        $outs = $this->runParallel([$same, $same]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $st = array_map(static fn (string $o): string => explode(':', $o)[1] ?? '', $outs);
        sort($st);
        $this->check('[RECEIVE-CONC] misma idempotency_key en paralelo ⇒ ambos OK (recorded + replayed) y UN solo lote de 2',
            $st === ['recorded', 'replayed'] && $this->countRows(ReceiptBatch::getTable(), ['idempotency_key' => 'conc-same-' . $this->suffix]) === 1
            && count($this->unitsOf($lc)) === 2);
        $r = $this->reqRow($req);
        $this->check('[RECEIVE-CONC] handoffs = unidades inventariables (18) y el motor convergió (PARTIALLY_RECEIVED, marcador al día)',
            $this->countRows(OutboxEntry::getTable(), ['requests_id' => $req]) === 18 && $this->wfState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED
            && (int) $r['receiving_synced_seq'] === (int) $r['receiving_seq']);
    }

    // ================================================================ [POST-PURCHASE-INTEGRITY]

    private function scenarioPostPurchaseIntegrity(): void
    {
        $this->out->writeln('== [POST-PURCHASE-INTEGRITY] deriva tras iniciar la compra ⇒ no se recibe y NO se reabre ==');
        $this->asAdmin();
        $supM = $this->makeSupplier('CP-SUP-P3MOVE-' . $this->suffix, $this->entityA, false);
        $req = $this->approvedRequest('postint', [['Printer', 3, 1]], ['1000'], '0', '0', '0', $supM);
        $this->asBuyer($this->uBuyer);
        $this->recv()->startPurchase($req);
        $l = $this->lineIds($req)[0];
        $this->asReceiver($this->uReceiver);
        $this->recv()->receive($req, 'pi-1-' . $this->suffix, [['items_id' => $l, 'quantity' => '1']]);
        $this->asAdmin();
        (new \Supplier())->update(['id' => $supM, 'entities_id' => $this->entityB]);
        $invalidations = count($this->ledger($req, HistoryEvent::EVENT_APPROVAL_INVALIDATED));
        $this->asReceiver($this->uReceiver);
        $this->check('[POST-PURCHASE-INTEGRITY] Supplier movido de rama tras iniciar la compra ⇒ recibir falla cerrado',
            $this->throws(fn () => $this->recv()->receive($req, 'pi-2-' . $this->suffix, [['items_id' => $l, 'quantity' => '1']])));
        $this->asBuyer($this->uBuyer);
        $e = $this->orch()->enforceIntegrity($req);
        $this->check('[POST-PURCHASE-INTEGRITY] NO se reabre el circuito: sin invalidación, error reportado, motor sigue PARTIALLY_RECEIVED',
            $e['invalidated'] === [] && $e['error'] !== null && $this->wfState($req) === PurchasingWorkflow::S_PARTIALLY_RECEIVED
            && count($this->ledger($req, HistoryEvent::EVENT_APPROVAL_INVALIDATED)) === $invalidations
            && $this->countEvents($req, PurchasingEvent::EV_SCOPE_INVALIDATED) === 0);
        $this->check('[POST-PURCHASE-INTEGRITY] las unidades ya recibidas se conservan', $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 1);
        $this->asAdmin();
        (new \Supplier())->update(['id' => $supM, 'entities_id' => $this->entityA]);
        $this->asReceiver($this->uReceiver);
        $ok = $this->recv()->receive($req, 'pi-2-' . $this->suffix, [['items_id' => $l, 'quantity' => '1']]);
        $this->check('[POST-PURCHASE-INTEGRITY] integridad restaurada ⇒ recibe normalmente', $ok['status'] === 'recorded');
    }

    // ================================================================ [RECEIVE-ACL]

    private function scenarioReceiveAcl(): void
    {
        $this->out->writeln('== [RECEIVE-ACL] RIGHT_RECEIVE + multi-entidad ==');
        $req = $this->startedRequest('acl', [['Scanner', 2, 1]], ['400']);
        $l = $this->lineIds($req)[0];
        $line = [['items_id' => $l, 'quantity' => '1']];
        $this->asReceiver($this->uReceiverB, [$this->entityB]);
        $this->check('[RECEIVE-ACL] receptor de OTRA entidad ⇒ no recibe', $this->throws(fn () => $this->recv()->receive($req, 'acl-b-' . $this->suffix, $line)));
        $this->check('[RECEIVE-ACL] receptor de OTRA entidad ⇒ no lee las unidades', $this->throws(fn () => $this->recv()->units($req)));
        $this->asBuyer($this->uBuyer);
        $this->check('[RECEIVE-ACL] Compras sin RIGHT_RECEIVE ⇒ denegado', $this->throws(fn () => $this->recv()->receive($req, 'acl-m-' . $this->suffix, $line)));
        $this->asRequester();
        $this->check('[RECEIVE-ACL] solicitante ⇒ denegado', $this->throws(fn () => $this->recv()->receive($req, 'acl-r-' . $this->suffix, $line)));
        $this->asIntegration($this->uIntegr);
        $this->check('[RECEIVE-ACL] worker de integración (sólo INTEGRATION) ⇒ no recibe', $this->throws(fn () => $this->recv()->receive($req, 'acl-i-' . $this->suffix, $line)));
        $this->check('[RECEIVE-ACL] ningún intento denegado persistió nada', $this->countRows(ReceiptUnit::getTable(), ['requests_id' => $req]) === 0);
        $this->asReceiver($this->uReceiver);
        $ok = $this->recv()->receive($req, 'acl-ok-' . $this->suffix, $line);
        $this->check('[RECEIVE-ACL] RIGHT_RECEIVE + entidad ⇒ recibe y lee sus unidades', $ok['status'] === 'recorded' && count($this->recv()->units($req)) === 1);
    }

    // ================================================================ [OUTBOX]

    private function scenarioOutbox(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [OUTBOX] contrato PurchasingIntegrationApi (lease, ACL, multi-entidad) ==');
        $req = $this->p2d3Main;
        $l1 = $this->lineIds($req)[0];
        $u0 = $this->unitsOf($l1)[0];
        $uuid = (string) $u0['receipt_unit_uuid'];
        $rr = $this->reqRow($req);

        $this->asIntegration($this->uIntegr);
        $h = $this->api()->getHandoff($uuid);
        $o = $this->outboxRow($uuid);
        $keys = $h !== null ? array_keys($h['payload']) : [];
        sort($keys);
        $expected = HandoffPayload::KEYS;
        sort($expected);
        $this->check('[OUTBOX] getHandoff por identidad canónica: PENDING, payload v1 con las claves EXACTAS',
            $h !== null && $h['status'] === OutboxEntry::STATUS_PENDING && $h['payload_version'] === HandoffPayload::SCHEMA_VERSION && $keys === $expected);
        $p = $h['payload'] ?? [];
        $this->check('[OUTBOX] payload: unidad, solicitud/número, línea, entidad, serial, proveedor, moneda, costo EXACTO (string), correlación',
            ($p['receipt_unit_uuid'] ?? '') === $uuid && ($p['request_id'] ?? 0) === $req && ($p['request_number'] ?? '') === (string) $rr['number']
            && ($p['item_id'] ?? 0) === $l1 && ($p['entity_id'] ?? -1) === $this->entityA && ($p['serial'] ?? '') === $this->mainSerials()[0]
            && ($p['supplier_id'] ?? 0) === $this->supA && ($p['currency'] ?? '') === 'PYG' && ($p['unit_cost'] ?? null) === '1424'
            && ($p['correlation_id'] ?? '') === (string) $rr['correlation_id'] && ($p['description'] ?? '') === 'Notebook');
        $this->check('[OUTBOX] payload INMUTABLE + hash: sha256(payload_json) = payload_sha256 y forma canónica verificable',
            $o !== [] && hash('sha256', (string) $o['payload_json']) === (string) $o['payload_sha256']
            && HandoffPayload::verify((string) $o['payload_json'], (string) $o['payload_sha256']) !== null);
        $this->check('[OUTBOX] sin secretos ni datos de infraestructura en el payload', preg_match('/password|secret|api_?key|https?:|token/i', (string) ($o['payload_json'] ?? '')) !== 1);

        // ACL de mínimo privilegio + multi-entidad.
        $this->asReceiver($this->uReceiver);
        $this->check('[OUTBOX] ACL: sin INTEGRATION (receptor) ⇒ claim/getHandoff denegados',
            $this->throws(fn () => $this->api()->claimPending('w-x', 1, 60)) && $this->throws(fn () => $this->api()->getHandoff($uuid)));
        $allButIntegration = READ | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_VIEW_ENTITY | Request::RIGHT_EDIT_DRAFT
            | Request::RIGHT_MANAGE_CONFIG | Request::RIGHT_MANAGE_PURCHASING | Request::RIGHT_RECEIVE | Request::RIGHT_DELIVER | Request::RIGHT_VIEW_METRICS;
        $this->asIntegration($this->uIntegr, null, $allButIntegration);
        $this->check('[OUTBOX] ACL: TODOS los demás bits de Compras pero sin INTEGRATION ⇒ denegado (derecho dedicado)', $this->throws(fn () => $this->api()->claimPending('w-x', 1, 60)));
        $this->asIntegration($this->uIntegr, [$this->entityB]);
        $this->check('[OUTBOX] multi-entidad: un worker de la entidad B no toma, no lee ni confirma handoffs de A',
            $this->api()->claimPending('w-b-' . $this->suffix, 100, 60) === [] && $this->api()->getHandoff($uuid) === null
            && $this->throws(fn () => $this->api()->acknowledgeProcessed($uuid, str_repeat('a', 64))));
        $this->asIntegration($this->uIntegr);
        $this->check('[OUTBOX] entradas inválidas ⇒ rechazadas (worker/limit/lease/uuid/token)',
            $this->throws(fn () => $this->api()->claimPending('', 1, 60)) && $this->throws(fn () => $this->api()->claimPending('w', 0, 60))
            && $this->throws(fn () => $this->api()->claimPending('w', 1, 0)) && $this->throws(fn () => $this->api()->claimPending('w', 1, PluginConfig::outboxMaxLeaseSeconds() + 1))
            && $this->throws(fn () => $this->api()->getHandoff('no-uuid')) && $this->throws(fn () => $this->api()->acknowledgeProcessed($uuid, 'short')));

        // Claim CONCURRENTE (procesos reales): conjuntos disjuntos.
        $pending = $this->countRows(OutboxEntry::getTable(), ['entities_id' => $this->entityA, 'status' => OutboxEntry::STATUS_PENDING]);
        $base = 'php bin/console plugins:companypurchasing:concurrency-probe --op=claim --entity=' . $this->entityA . ' --user=' . $this->uIntegr . ' --limit=5 --lease=3600 --no-interaction --worker=';
        $outs = $this->runParallel([$base . 'wc1-' . $this->suffix, $base . 'wc2-' . $this->suffix]);
        $this->out->writeln('  probes: ' . implode(' | ', array_map(static fn (string $o): string => substr($o, 0, 60) . '…', $outs)));
        $sets = array_map(static fn (string $o): array => str_starts_with($o, 'OK:') && strlen($o) > 3 ? explode(',', substr($o, 3)) : [], $outs);
        $all = array_merge(...array_values($sets));
        $leasedOk = true;
        foreach ($sets as $i => $set) {
            foreach ($set as $u) {
                $row = $this->outboxRow($u);
                $leasedOk = $leasedOk && ($row['status'] ?? '') === OutboxEntry::STATUS_LEASED && (int) $row['attempts'] === 1
                    && preg_match('/^[0-9a-f]{64}$/', (string) $row['lease_token']) === 1 && (string) $row['leased_by'] === 'wc' . ($i + 1) . '-' . $this->suffix;
            }
        }
        $this->check('[OUTBOX] claim concurrente: ambos OK y conjuntos DISJUNTOS (nunca la misma fila a dos workers)',
            count(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'OK:'))) === 2
            && count($all) === count(array_unique($all)) && count($all) === min(10, $pending));
        $this->check('[OUTBOX] cada fila tomada: LEASED, attempts 1, lease_token propio, leased_by = su worker', $all !== [] && $leasedOk);

        // Aislamiento: tomar todo lo elegible con un lease largo.
        $this->drainOutbox();
        $this->check('[OUTBOX] sin elegibles tras tomar todo', $this->api()->claimPending('w-empty-' . $this->suffix, 100, 60) === []);

        // Unidades NUEVAS de una a la vez para las pruebas de lease.
        $rq = $this->startedRequest('lease', [['Camera', 6, 1]], ['250']);
        $lq = $this->lineIds($rq)[0];
        $one = function (string $tag) use ($rq, $lq): string {
            $this->asReceiver($this->uReceiver);
            $r = $this->recv()->receive($rq, 'ls-' . $tag . '-' . $this->suffix, [['items_id' => $lq, 'quantity' => '1']]);
            $this->asIntegration($this->uIntegr);
            return (string) ($r['units'][0] ?? '');
        };

        // (a) lease vencido ⇒ re-toma; el token viejo ya no confirma.
        $ua = $one('a');
        $ca = $this->api()->claimPending('worker-A', 10, 1);
        $this->check('[OUTBOX] worker A toma la unidad (token-A, lease 1 s)', count($ca) === 1 && $ca[0]['receipt_unit_uuid'] === $ua && preg_match('/^[0-9a-f]{64}$/', $ca[0]['lease_token']) === 1);
        $this->check('[OUTBOX] lease VIGENTE ⇒ ningún otro worker la toma', $this->api()->claimPending('worker-B', 10, 60) === []);
        sleep(2);
        $cb = $this->api()->claimPending('worker-B', 10, 60);
        $this->check('[OUTBOX] lease VENCIDO ⇒ worker B la re-toma con token-B distinto (attempts 2)',
            count($cb) === 1 && $cb[0]['receipt_unit_uuid'] === $ua && $cb[0]['lease_token'] !== ($ca[0]['lease_token'] ?? '') && $cb[0]['attempts'] === 2);
        $this->check('[OUTBOX] worker A confirma con token-A (viejo) ⇒ RECHAZADO', $this->throws(fn () => $this->api()->acknowledgeProcessed($ua, (string) ($ca[0]['lease_token'] ?? ''))));
        $ack = $this->api()->acknowledgeProcessed($ua, (string) ($cb[0]['lease_token'] ?? ''));
        $row = $this->outboxRow($ua);
        $this->check('[OUTBOX] worker B confirma con token-B ⇒ DONE', $ack['status'] === OutboxEntry::STATUS_DONE && !$ack['idempotent']
            && ($row['status'] ?? '') === OutboxEntry::STATUS_DONE && !empty($row['processed_at']));
        $ack2 = $this->api()->acknowledgeProcessed($ua, (string) ($cb[0]['lease_token'] ?? ''));
        $this->check('[OUTBOX] repetir el ACK con el mismo token ⇒ idempotente', $ack2['status'] === OutboxEntry::STATUS_DONE && $ack2['idempotent']);
        $this->check('[OUTBOX] DONE no vuelve a ser elegible; un solo evento handoff.done', $this->api()->claimPending('worker-C', 10, 60) === []
            && $this->countEvents($rq, PurchasingEvent::EV_HANDOFF_DONE) === 1);
        $this->check('[OUTBOX] markError con el token viejo sobre DONE ⇒ rechazado', $this->throws(fn () => $this->api()->markError($ua, (string) ($ca[0]['lease_token'] ?? ''), 'x')));

        // (b) RETRY respeta next_retry_at.
        $ur = $one('r');
        $cr = $this->api()->claimPending('worker-R', 10, 60);
        $res = $this->api()->markRetry($ur, (string) ($cr[0]['lease_token'] ?? ''), 'Snipe-IT 503 temporarily unavailable', new \DateTimeImmutable('+2 seconds'));
        $row = $this->outboxRow($ur);
        $this->check('[OUTBOX] markRetry ⇒ RETRY con next_retry_at y last_error', $res['status'] === OutboxEntry::STATUS_RETRY && ($row['status'] ?? '') === OutboxEntry::STATUS_RETRY
            && !empty($row['next_retry_at']) && str_contains((string) $row['last_error'], '503') && empty($row['leased_until']));
        $this->check('[OUTBOX] RETRY NO es elegible antes de next_retry_at', $this->api()->claimPending('worker-R2', 10, 60) === []);
        sleep(3);
        $cr2 = $this->api()->claimPending('worker-R2', 10, 60);
        $this->check('[OUTBOX] RETRY elegible al vencer next_retry_at (attempts 2)', count($cr2) === 1 && $cr2[0]['receipt_unit_uuid'] === $ur && $cr2[0]['attempts'] === 2);
        $this->api()->acknowledgeProcessed($ur, (string) ($cr2[0]['lease_token'] ?? ''));

        // (c) ERROR final + last_error saneado.
        $ue = $one('e');
        $ce = $this->api()->claimPending('worker-E', 10, 60);
        $res = $this->api()->markError($ue, (string) ($ce[0]['lease_token'] ?? ''), "auth failed password=hunter2 token: abc123\nAuthorization: Bearer eyJhbGciOi.xyz https://svc:pw9@snipe.local/api");
        $row = $this->outboxRow($ue);
        $err = (string) ($row['last_error'] ?? '');
        $this->check('[OUTBOX] markError ⇒ ERROR (final)', $res['status'] === OutboxEntry::STATUS_ERROR && ($row['status'] ?? '') === OutboxEntry::STATUS_ERROR);
        $this->check('[OUTBOX] last_error SIN secretos (password/token/Bearer/credenciales en URL redactados)',
            !str_contains($err, 'hunter2') && !str_contains($err, 'abc123') && !str_contains($err, 'eyJhbGciOi') && !str_contains($err, 'pw9')
            && str_contains($err, '[redacted]') && !str_contains($err, "\n"));
        $this->check('[OUTBOX] ERROR nunca vuelve a ser elegible; confirmar después ⇒ rechazado',
            $this->api()->claimPending('worker-E2', 10, 60) === [] && $this->throws(fn () => $this->api()->acknowledgeProcessed($ue, (string) ($ce[0]['lease_token'] ?? ''))));

        // (d) intentos agotados ⇒ ERROR.
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['outbox_max_attempts' => '1']);
        $ux = $one('x');
        $cx = $this->api()->claimPending('worker-X', 10, 60);
        $res = $this->api()->markRetry($ux, (string) ($cx[0]['lease_token'] ?? ''), 'still failing', '+1 hour');
        $this->check('[OUTBOX] intentos agotados (outbox_max_attempts) ⇒ markRetry termina en ERROR',
            $res['status'] === OutboxEntry::STATUS_ERROR && ($this->outboxRow($ux)['status'] ?? '') === OutboxEntry::STATUS_ERROR);
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['outbox_max_attempts' => PluginConfig::DEFAULTS['outbox_max_attempts']]);

        // (e) payload alterado ⇒ jamás se entrega.
        $ut = $one('t');
        $ot = $this->outboxRow($ut);
        $DB->update(OutboxEntry::getTable(), ['payload_json' => str_replace('"unit_cost":"250"', '"unit_cost":"1"', (string) $ot['payload_json'])], ['receipt_unit_uuid' => $ut]);
        $this->asIntegration($this->uIntegr);
        $ct = $this->api()->claimPending('worker-T', 10, 60);
        $row = $this->outboxRow($ut);
        $this->check('[OUTBOX] payload ALTERADO ⇒ no se entrega: el claim lo deja en ERROR visible',
            $ct === [] && ($row['status'] ?? '') === OutboxEntry::STATUS_ERROR && (string) $row['last_error'] === 'payload integrity check failed');
        $this->check('[OUTBOX] getHandoff de un payload alterado ⇒ fail-closed', $this->throws(fn () => $this->api()->getHandoff($ut)));
    }

    // ================================================================ [LEGACY-DEF]

    private function scenarioLegacyDefinition(): void
    {
        $this->out->writeln('== [LEGACY-DEF] instancia con una versión ANTERIOR (sin fase de compra) ==');
        $this->asAdmin();
        $gw = new WorkflowGateway();
        $spec = PurchasingWorkflow::spec(PluginConfig::workflowCode(), Request::class, PluginConfig::stageConfig());
        $spec['states'] = array_values(array_filter($spec['states'], static fn (array $s): bool => !in_array($s['code'], PurchasingWorkflow::PURCHASE_STATES, true)));
        $spec['transitions'] = array_values(array_filter($spec['transitions'], static fn (array $t): bool
            => !in_array($t['to'], PurchasingWorkflow::PURCHASE_STATES, true) && !in_array($t['from'], PurchasingWorkflow::PURCHASE_STATES, true)));
        $legacy = $gw->publish($spec);
        $req = $this->approvedRequest('legacy', [['Phone', 2, 1]], ['600']);
        $inst = $gw->loadInstance($this->instanceId($req));
        $this->check('[LEGACY-DEF] fixture: instancia APROBADA bajo una versión SIN fase de compra',
            $this->wfState($req) === PurchasingWorkflow::S_APPROVED && $inst !== null && (int) $inst->fields['workflowdefs_id'] === (int) $legacy->getID());
        $this->asAdmin();
        $newDef = $this->orch()->publishDefinition();
        $inst = $gw->loadInstance($this->instanceId($req));
        $this->check('[LEGACY-DEF] nueva versión activa; la instancia CONSERVA la suya',
            $newDef !== (int) $legacy->getID() && $inst !== null && (int) $inst->fields['workflowdefs_id'] === (int) $legacy->getID());
        $lock = (int) $inst->fields['lock_version'];
        $hist = count($this->ledger($req));
        $this->asBuyer($this->uBuyer);
        $this->check('[LEGACY-DEF] iniciar la compra ⇒ fail-closed (no se migra en silencio)',
            $this->throws(fn () => $this->recv()->startPurchase($req)) && empty($this->reqRow($req)['purchase_started_at'])
            && (int) $this->itemRow($this->lineIds($req)[0])['ordered_qty'] === 0);
        $this->asAdmin();
        $rep = $this->orch()->reconcile($req);
        $this->check('[LEGACY-DEF] reconcile la DETECTA y REPORTA (versión anterior, bloqueada en APPROVED)', $rep['legacy'] && $rep['legacy_blocked']);
        $inst = $gw->loadInstance($this->instanceId($req));
        $this->check('[LEGACY-DEF] nada mutado: mismo estado, lock_version e historial del motor',
            $this->wfState($req) === PurchasingWorkflow::S_APPROVED && $inst !== null && (int) $inst->fields['lock_version'] === $lock && count($this->ledger($req)) === $hist);
    }
}
