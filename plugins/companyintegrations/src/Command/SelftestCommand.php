<?php

/**
 * Autotest de INTEGRACIÓN + E2E de companyintegrations (SI-1). Corre DENTRO de un GLPI arrancado.
 *
 * Nombre: `plugins:companyintegrations:selftest`. Fail-closed → exit 1 si algo falla.
 *
 * Usa un TRANSPORTE de prueba (fake Snipe por API, sin socket ni Snipe real) + activos GLPI reales:
 *   [PERSIST]   tablas propias creadas por la migración.
 *   [ROUTES]    GET /asset/{asset_tag} AUTHENTICATED (el tag NO autoriza; GLPI sí).
 *   [E2E]       Snipe API (fake) → leer → reconciliar → encontrar GLPI → PERSISTIR puente →
 *               gateway lookup (tag actual e histórico) — SIN escribir en Snipe ni modificar GLPI.
 *   [RECON]     clasifica MATCHED/SNIPE_ONLY/AMBIGUOUS/COMPANY_UNMAPPED/SERIAL_CONFLICT.
 *   [MULTI-ENT] 🔒 gateway: usuario de entidad A no ve el activo de entidad B (canViewItem).
 *   [READONLY]  🔒 el transporte sólo hizo GET; el activo GLPI no fue modificado.
 *   [LABEL]     configuración de etiquetas (plain_asset_tag + prefijo) detectada por API.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Command;

use Computer;
use Entity;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companyintegrations\Client\ArrayTransport;
use GlpiPlugin\Companyintegrations\Client\HttpResponse;
use GlpiPlugin\Companyintegrations\Client\SnipeClientConfig;
use GlpiPlugin\Companyintegrations\Client\SnipeItClient;
use GlpiPlugin\Companyintegrations\Controller\GatewayController;
use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Model\AssetTagAlias;
use GlpiPlugin\Companyintegrations\Model\MapCompany;
use GlpiPlugin\Companyintegrations\Model\ReconResult;
use GlpiPlugin\Companyintegrations\Service\AssetResolver;
use GlpiPlugin\Companyintegrations\Service\LabelConfigChecker;
use GlpiPlugin\Companyintegrations\Service\ReconciliationClassifier;
use GlpiPlugin\Companyintegrations\Service\Reconciler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Annotation\Route;

final class SelftestCommand extends Command
{
    private int $failures = 0;
    private OutputInterface $out;
    private string $suffix;
    private int $entityA = 0;
    private int $entityB = 0;
    /** @var array<int,int> */
    private array $createdComputers = [];
    /** @var array<int,int> */
    private array $createdEntities = [];

    protected function configure(): void
    {
        $this->setName('plugins:companyintegrations:selftest')
            ->setDescription('Pruebas de integración + E2E de SI-1 (reconciliación read-only, bridge, gateway, multi-entidad).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->suffix = substr((string) time(), -6);

        $this->checkPersistence();
        $this->checkRoutes();
        $this->buildFixtures();
        $this->scenarioReconcileAndBridge();
        $this->scenarioSerialConflict();
        $this->scenarioGatewayMultiEntity();
        $this->scenarioLabelConfig();
        $this->cleanup();

        if ($this->failures > 0) {
            $output->writeln(sprintf('<error>SELFTEST: %d comprobación(es) fallida(s).</error>', $this->failures));
            return Command::FAILURE;
        }
        $output->writeln('<info>SELFTEST: todas las comprobaciones pasaron.</info>');
        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------ [PERSIST]

    private function checkPersistence(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [PERSIST] tablas propias ==');
        foreach (['asset_bridge', 'asset_tag_aliases', 'map_companies', 'map_users', 'recon'] as $t) {
            $this->check("tabla glpi_plugin_companyintegrations_{$t} existe", $DB->tableExists("glpi_plugin_companyintegrations_{$t}"));
        }
    }

    // ------------------------------------------------------------------ [ROUTES]

    private function checkRoutes(): void
    {
        $this->out->writeln('== [ROUTES] gateway ==');
        try {
            $m = (new \ReflectionClass(GatewayController::class))->getMethod('asset');
            $path = '';
            $strategy = null;
            $methods = [];
            foreach ($m->getAttributes(Route::class) as $a) {
                $args = $a->getArguments();
                $path = (string) ($args['path'] ?? $args[0] ?? '');
                $methods = (array) ($args['methods'] ?? []);
            }
            foreach ($m->getAttributes(SecurityStrategy::class) as $a) {
                $args = $a->getArguments();
                $strategy = (string) ($args['strategy'] ?? $args[0] ?? '');
            }
            $this->check('ruta /asset/{asset_tag} declarada', str_contains($path, '/asset/{asset_tag}'));
            $this->check('ruta es GET', in_array('GET', $methods, true));
            $this->check('ruta es AUTHENTICATED (el tag no autoriza)', $strategy === Firewall::STRATEGY_AUTHENTICATED);
        } catch (\Throwable $e) {
            $this->check('reflexión GatewayController: ' . $e->getMessage(), false);
        }
    }

    // ------------------------------------------------------------------ fixtures

    private function buildFixtures(): void
    {
        $this->out->writeln('== fixtures (entidades + activos GLPI reales) ==');
        $this->applySession(2, [0], ['entity' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        $this->entityA = (int) (new Entity())->add(['name' => 'SI1-A-' . $this->suffix, 'entities_id' => 0]);
        $this->entityB = (int) (new Entity())->add(['name' => 'SI1-B-' . $this->suffix, 'entities_id' => 0]);
        $this->createdEntities = array_filter([$this->entityA, $this->entityB]);
        $this->check('entidades A/B creadas', $this->entityA > 0 && $this->entityB > 0);
    }

    private function makeComputer(string $serial): int
    {
        $id = (int) (new Computer())->add([
            'name'        => 'SI1-PC-' . $this->suffix . '-' . random_int(1000, 9999),
            'entities_id' => $this->entityB,
            'serial'      => $serial,
        ]);
        if ($id > 0) {
            $this->createdComputers[] = $id;
        }
        return $id;
    }

    // ------------------------------------------------------------------ [E2E] reconcile + bridge

    private function scenarioReconcileAndBridge(): void
    {
        $this->out->writeln('== [E2E] reconciliación read-only + puente + gateway ==');

        $matchSerial = 'SNX-100-' . $this->suffix;
        $ambSerial   = 'SNX-AMB-' . $this->suffix;
        $pcMatch = $this->makeComputer($matchSerial);
        $pcAmb1  = $this->makeComputer($ambSerial);
        $pcAmb2  = $this->makeComputer($ambSerial);
        $this->check('activos GLPI de prueba creados', $pcMatch > 0 && $pcAmb1 > 0 && $pcAmb2 > 0);
        $nameBefore = (function (int $id): string {
            $c = new Computer();
            return $c->getFromDB($id) ? (string) $c->fields['name'] : '';
        })($pcMatch);

        // Mapear compañía Snipe 7 → entidad B (aprobado).
        (new MapCompany())->add([
            'snipe_company_id' => 7, 'snipe_name' => 'ACME', 'glpi_entity_id' => $this->entityB,
            'is_approved' => 1, 'notes' => 'selftest',
        ]);

        // Fake Snipe: 4 activos.
        $rows = [
            ['id' => 101, 'asset_tag' => 'NB-101-' . $this->suffix, 'serial' => $matchSerial, 'company' => ['id' => 7]],
            ['id' => 102, 'asset_tag' => 'NB-102-' . $this->suffix, 'serial' => 'SNX-NONE-' . $this->suffix, 'company' => ['id' => 7]],
            ['id' => 103, 'asset_tag' => 'NB-103-' . $this->suffix, 'serial' => $ambSerial, 'company' => ['id' => 7]],
            ['id' => 104, 'asset_tag' => 'NB-104-' . $this->suffix, 'serial' => 'SNX-UN-' . $this->suffix, 'company' => ['id' => 999]],
        ];
        $transport = new ArrayTransport([new HttpResponse(200, [], json_encode(['total' => 4, 'rows' => $rows]) ?: '')]);
        $client = new SnipeItClient($transport, new SnipeClientConfig('https://snipe.test', 'tok', 5000, 0, 1, 5, 60), null, false);

        $summary = (new Reconciler())->reconcile($client, ['Computer'], 50);
        $this->check('[RECON] 1 MATCHED', ($summary[ReconciliationClassifier::MATCHED] ?? 0) >= 1);
        $this->check('[RECON] 1 SNIPE_ONLY', ($summary[ReconciliationClassifier::SNIPE_ONLY] ?? 0) >= 1);
        $this->check('[RECON] 1 AMBIGUOUS', ($summary[ReconciliationClassifier::AMBIGUOUS] ?? 0) >= 1);
        $this->check('[RECON] 1 COMPANY_UNMAPPED', ($summary[ReconciliationClassifier::COMPANY_UNMAPPED] ?? 0) >= 1);

        // Puente creado sólo para el match inequívoco (101 → pcMatch).
        $b = new AssetBridge();
        $bridged = $b->getFromDBByCrit(['snipe_asset_id' => 101]);
        $this->check('[E2E] puente creado para 101', $bridged);
        if ($bridged) {
            $this->check('[E2E] puente apunta al Computer correcto',
                (string) $b->fields['glpi_itemtype'] === 'Computer' && (int) $b->fields['glpi_items_id'] === $pcMatch);
            $this->check('[E2E] puente en entidad B', (int) $b->fields['glpi_entity_id'] === $this->entityB);
            $this->check('[E2E] sync_status = matched', (string) $b->fields['sync_status'] === AssetBridge::STATUS_MATCHED);
        }

        // NO se crean puentes para ambiguo/snipe_only/company_unmapped.
        $this->check('[RECON] sin puente para 103 (ambiguo)', !(new AssetBridge())->getFromDBByCrit(['snipe_asset_id' => 103]));
        $this->check('[RECON] sin puente para 104 (compañía no mapeada)', !(new AssetBridge())->getFromDBByCrit(['snipe_asset_id' => 104]));

        // 🔒 READ-ONLY: sólo GET y el activo GLPI intacto.
        $this->check('[READONLY] 🔒 el transporte sólo hizo GET', $transport->allReadOnly());
        $nameAfter = (function (int $id): string {
            $c = new Computer();
            return $c->getFromDB($id) ? (string) $c->fields['name'] : '';
        })($pcMatch);
        $this->check('[READONLY] 🔒 el activo GLPI no fue modificado', $nameBefore !== '' && $nameBefore === $nameAfter);

        // Gateway: resolver por tag actual, y por alias histórico.
        $resolver = new AssetResolver();
        $currentTag = 'NB-101-' . $this->suffix;
        $this->check('[E2E] gateway resuelve tag actual → puente',
            ($resolver->resolveByTag($currentTag)?->getID()) === $b->getID());

        if ($bridged) {
            (new AssetTagAlias())->add([
                'asset_bridge_id' => $b->getID(), 'asset_tag' => 'OLD-101-' . $this->suffix,
                'is_current' => 0, 'valid_from' => date('Y-m-d H:i:s', time() - 86400), 'valid_to' => date('Y-m-d H:i:s'),
            ]);
            $this->check('[E2E] gateway resuelve tag HISTÓRICO → mismo puente (QR estable)',
                ($resolver->resolveByTag('OLD-101-' . $this->suffix)?->getID()) === $b->getID());
        }

        // Guardar para el test multi-entidad.
        $this->matchedComputerId = $pcMatch;

        // Auditoría de reconciliación registrada (append-only).
        $this->check('[RECON] resultados persistidos en recon', (new ReconResult())->getFromDBByCrit(['snipe_asset_id' => 101]));
    }

    private int $matchedComputerId = 0;

    // ------------------------------------------------------------------ [RECON] serial conflict

    private function scenarioSerialConflict(): void
    {
        $this->out->writeln('== [RECON] serial conflict (no auto-corrige) ==');
        $pc = $this->makeComputer('SER-A-' . $this->suffix);

        // Puente pre-existente con serial AAA.
        (new AssetBridge())->add([
            'snipe_asset_id' => 201, 'snipe_asset_tag' => 'NB-201-' . $this->suffix,
            'glpi_itemtype' => 'Computer', 'glpi_items_id' => $pc, 'glpi_entity_id' => $this->entityB,
            'serial' => 'SER-A-' . $this->suffix, 'sync_status' => AssetBridge::STATUS_MATCHED,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        (new MapCompany())->add([
            'snipe_company_id' => 8, 'snipe_name' => 'BETA', 'glpi_entity_id' => $this->entityB, 'is_approved' => 1,
        ]);

        // Snipe reporta el MISMO activo (201) con serial DISTINTO.
        $rows = [['id' => 201, 'asset_tag' => 'NB-201-' . $this->suffix, 'serial' => 'SER-B-' . $this->suffix, 'company' => ['id' => 8]]];
        $transport = new ArrayTransport([new HttpResponse(200, [], json_encode(['rows' => $rows]) ?: '')]);
        $client = new SnipeItClient($transport, new SnipeClientConfig('https://snipe.test', 'tok', 5000, 0, 1, 5, 60), null, false);

        $summary = (new Reconciler())->reconcile($client, ['Computer'], 50);
        $this->check('[RECON] SERIAL_CONFLICT detectado', ($summary[ReconciliationClassifier::SERIAL_CONFLICT] ?? 0) >= 1);

        // El puente NO cambió su vínculo (no auto-corrige).
        $b = new AssetBridge();
        $b->getFromDBByCrit(['snipe_asset_id' => 201]);
        $this->check('[RECON] puente conserva su vínculo (no auto-fix)', (int) $b->fields['glpi_items_id'] === $pc);
        $this->check('[RECON] puente marcado serial_conflict', (string) $b->fields['sync_status'] === AssetBridge::STATUS_SERIAL_CONFLICT);
    }

    // ------------------------------------------------------------------ [MULTI-ENT] gateway ACL

    private function scenarioGatewayMultiEntity(): void
    {
        $this->out->writeln('== [MULTI-ENT] gateway respeta ACL de entidad ==');
        if ($this->matchedComputerId <= 0) {
            $this->check('[MULTI-ENT] activo de referencia disponible', false);
            return;
        }
        $pc = new Computer();
        $pc->getFromDB($this->matchedComputerId);

        // Usuario de entidad A (sin acceso a B) → NO puede ver el activo de B.
        $this->applySession(101, [$this->entityA], ['computer' => READ]);
        $this->check('[MULTI-ENT] 🔒 entidad A NO ve activo de B', $pc->canViewItem() === false);

        // Usuario de entidad B → SÍ.
        $this->applySession(102, [$this->entityB], ['computer' => READ]);
        $this->check('[MULTI-ENT] entidad B SÍ ve activo de B', $pc->canViewItem() === true);
    }

    // ------------------------------------------------------------------ [LABEL]

    private function scenarioLabelConfig(): void
    {
        $this->out->writeln('== [LABEL] configuración de etiquetas (read-only) ==');
        $settings = ['label2_2d_target' => 'plain_asset_tag', 'label2_2d_prefix' => 'https://portal/asset/'];
        $transport = new ArrayTransport([new HttpResponse(200, [], json_encode($settings) ?: '')]);
        $client = new SnipeItClient($transport, new SnipeClientConfig('https://snipe.test', 'tok', 5000, 0, 1, 5, 60), null, false);
        $check = (new LabelConfigChecker())->check($client->getSettings());
        $this->check('[LABEL] plain_asset_tag + prefijo detectado', $check['ok'] === true);
        $this->check('[LABEL] la consulta de settings fue GET (read-only)', $transport->allReadOnly());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<int>        $entities
     * @param array<string,int> $rights
     */
    private function applySession(int $userId, array $entities, array $rights, int $recursive = 0): void
    {
        $_SESSION['glpiID']                      = $userId;
        $_SESSION['glpiname']                    = 'si1_selftest';
        $_SESSION['glpiactive_entity']           = $entities[0] ?? 0;
        $_SESSION['glpiactiveentities']          = $entities;
        $_SESSION['glpiactiveentities_string']   = "'" . implode("','", $entities) . "'";
        $_SESSION['glpiactive_entity_recursive'] = $recursive;
        $_SESSION['glpigroups']                  = [];
        $_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s');
        $_SESSION['glpiactiveprofile']           = array_merge(
            ['id' => 1, 'interface' => 'central', 'entities_id' => $entities[0] ?? 0],
            $rights
        );
    }

    private function cleanup(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            foreach (['asset_bridge', 'asset_tag_aliases', 'map_companies', 'map_users', 'recon'] as $t) {
                $DB->doQuery("DELETE FROM `glpi_plugin_companyintegrations_{$t}` WHERE 1=1");
            }
            foreach ($this->createdComputers as $id) {
                (new Computer())->delete(['id' => $id], true);
            }
            foreach ($this->createdEntities as $id) {
                (new Entity())->delete(['id' => $id], true);
            }
        } catch (\Throwable) {
            // best-effort; el stack de CI es efímero.
        }
    }

    private function check(string $label, bool $ok): void
    {
        if ($ok) {
            $this->out->writeln('  <info>✓</info> ' . $label);
        } else {
            $this->failures++;
            $this->out->writeln('  <error>✗</error> ' . $label);
        }
    }
}
