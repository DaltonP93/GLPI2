<?php

/**
 * Autotest de INTEGRACIÓN + E2E de companypurchasing — P2D-1 (corre DENTRO de un GLPI arrancado, en CI).
 *
 * Nombre: `plugins:companypurchasing:selftest`. Fail-closed → exit 1 si algo falla.
 *
 * Cobertura (núcleo P2D-1; SIN companyworkflow/companysignature/recepción/outbox):
 *   [PERSIST]     5 tablas propias + derecho de plugin.
 *   [MONEY]       amount_estimated exacto vía BD (PYG sin decimales; suma de líneas).
 *   [NUMBERING]   asignación transaccional; distinta por proceso; independiente por entidad/año.
 *   [CRUD/E2E]    crear borrador → líneas → editar → submit (asigna número) → construir REQUEST_SCOPE.
 *   [ACL]         crear/editar sin permiso → denegado (fail-closed).
 *   [MULTI-ENT]   solicitud de la entidad A no visible desde sesión en la entidad B.
 *   [REUSE]       Supplier/Budget nativos referenciados (no se duplican maestros).
 *   [NEG]         line_no duplicado; reordenar cambia line_no no identidad; decimal inválido;
 *                 PYG con fracción; cantidad inválida.
 *   [MIGRATE]     install/uninstall/reinstall reversible (al final, tras limpiar objetos core).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Command;

use Budget;
use Entity;
use Supplier;
use User;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;
use GlpiPlugin\Companypurchasing\Model\ScopeDef;
use GlpiPlugin\Companypurchasing\Service\NumberingService;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use GlpiPlugin\Companypurchasing\Service\ScopeCatalog;
use GlpiPlugin\Companypurchasing\Service\ScopeSnapshotBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SelftestCommand extends Command
{
    private int $failures = 0;
    private OutputInterface $out;
    private string $suffix = '';
    private int $clock = 0;

    /** @var array<int,int> */
    private array $createdEntities = [];
    private array $createdUsers = [];
    private array $createdSuppliers = [];
    private array $createdBudgets = [];

    private int $entityA = 0;
    private int $entityB = 0;
    private int $uOwner = 0;

    /** Bits activos en P2D-1. */
    private const FULL = READ
        | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_VIEW_ENTITY
        | Request::RIGHT_EDIT_DRAFT | Request::RIGHT_MANAGE_CONFIG;

    protected function configure(): void
    {
        $this->setName('plugins:companypurchasing:selftest')
            ->setDescription('Pruebas de integración + E2E del núcleo de compras (dinero exacto, numeración, scopes, ACL/multi-entidad).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->clock = time();
        $this->suffix = substr(bin2hex(random_bytes(4)), 0, 8);

        try {
            $this->setup();
            $this->scenarioPersist();
            $this->scenarioMoney();
            $this->scenarioNumbering();
            $this->scenarioCrudE2E();
            $this->scenarioAcl();
            $this->scenarioMultiEntity();
            $this->scenarioSupplierBudget();
            $this->scenarioNegatives();
        } catch (\Throwable $e) {
            $this->out->writeln('<error>EXCEPCIÓN: ' . $e->getMessage() . '</error>');
            $this->failures++;
        } finally {
            $this->cleanup();
        }

        // Reversibilidad de migraciones (al final, ya sin datos de negocio propios).
        $this->scenarioReinstall();

        if ($this->failures > 0) {
            $this->out->writeln("<error>SELFTEST: {$this->failures} comprobación(es) fallaron.</error>");
            return Command::FAILURE;
        }
        $this->out->writeln('<info>SELFTEST: todas las comprobaciones pasaron.</info>');
        return Command::SUCCESS;
    }

    // ---------------------------------------------------------------- setup

    private function setup(): void
    {
        $this->applySession(2, [0], ['plugin_companypurchasing' => self::FULL], 1);
        $this->entityA = $this->makeEntity('A');
        $this->entityB = $this->makeEntity('B');
        $this->uOwner  = $this->makeUser('owner');
        $this->check('[SETUP] entidades + usuario', $this->entityA > 0 && $this->entityB > 0 && $this->uOwner > 0);
    }

    // ---------------------------------------------------------------- [PERSIST]

    private function scenarioPersist(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [PERSIST] tablas propias + derecho ==');
        foreach ([
            'glpi_plugin_companypurchasing_requests',
            'glpi_plugin_companypurchasing_items',
            'glpi_plugin_companypurchasing_numbering',
            'glpi_plugin_companypurchasing_events',
            'glpi_plugin_companypurchasing_scope_defs',
        ] as $t) {
            $this->check("tabla {$t} existe", $DB->tableExists($t));
        }
        // Scopes sembrados (versión 1).
        $seeded = 0;
        foreach ($DB->request(['FROM' => ScopeDef::getTable(), 'WHERE' => ['scopes_version' => 1]]) as $ignored) {
            $seeded++;
        }
        $this->check('[PERSIST] scopes v1 sembrados (REQUEST + COMMERCIAL)', $seeded >= 2);
    }

    // ---------------------------------------------------------------- [MONEY]

    private function scenarioMoney(): void
    {
        $this->out->writeln('== [MONEY] amount_estimated exacto (PYG) ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();
        $reqId = $rm->createDraft(['entities_id' => $this->entityA, 'currency_code' => 'PYG', 'reason' => 'money']);
        $rm->addLine($reqId, ['description' => 'A', 'quantity' => '3', 'estimated_unit_price' => '1500', 'is_inventoriable' => 1]);
        $rm->addLine($reqId, ['description' => 'B', 'quantity' => '2', 'estimated_unit_price' => '2000', 'is_inventoriable' => 0]);

        $req = new Request();
        $req->getFromDB($reqId);
        // 3×1500 + 2×2000 = 4500 + 4000 = 8500 (PYG, sin decimales)
        $this->check('[MONEY] amount_estimated = 8500 (exacto)', (int) $req->fields['amount_estimated'] === 8500);
        $this->check('[MONEY] PYG almacenado sin fracción significativa', str_ends_with((string) $req->fields['amount_estimated'], '.000000') || strpos((string) $req->fields['amount_estimated'], '.') === false);
    }

    // ---------------------------------------------------------------- [NUMBERING]

    private function scenarioNumbering(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [NUMBERING] asignación transaccional/independiente ==');
        $svc = new NumberingService();
        $year = 2099;

        $n1 = $svc->assign($this->entityA, NumberingService::SCOPE_REQUEST, $year);
        $n2 = $svc->assign($this->entityA, NumberingService::SCOPE_REQUEST, $year);
        $this->check('[NUMBERING] dos asignaciones → números distintos', $n1 !== $n2);
        $this->check('[NUMBERING] estrictamente crecientes (sin reuso)', $n2 === $n1 + 1);

        $b1 = $svc->assign($this->entityB, NumberingService::SCOPE_REQUEST, $year);
        $this->check('[NUMBERING] otra entidad usa su propia secuencia (empieza en 1)', $b1 === 1);

        // Una sola fila de secuencia por (entidad, scope, año): UNIQUE respetado.
        $rows = 0;
        foreach ($DB->request(['FROM' => 'glpi_plugin_companypurchasing_numbering', 'WHERE' => ['entities_id' => $this->entityA, 'scope' => 'request', 'year' => $year]]) as $ignored) {
            $rows++;
        }
        $this->check('[NUMBERING] UNIQUE(entities_id,scope,year): una sola fila', $rows === 1);
    }

    // ---------------------------------------------------------------- [CRUD/E2E]

    private function scenarioCrudE2E(): void
    {
        $this->out->writeln('== [CRUD/E2E] borrador → líneas → editar → submit → REQUEST_SCOPE ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();

        $reqId = $rm->createDraft([
            'entities_id' => $this->entityA, 'currency_code' => 'PYG',
            'groups_id_department' => 5, 'category' => 'IT', 'destination' => 'Depósito', 'reason' => 'e2e',
        ]);
        $l1 = $rm->addLine($reqId, ['description' => 'Notebook', 'quantity' => '2', 'unit' => 'u', 'estimated_unit_price' => '5000000', 'is_inventoriable' => 1]);
        $rm->addLine($reqId, ['description' => 'Cable', 'quantity' => '10', 'unit' => 'u', 'estimated_unit_price' => '15000', 'is_inventoriable' => 0]);
        $rm->updateDraft($reqId, ['observations' => 'revisado']);
        $rm->updateLine($l1, ['quantity' => '3']); // re-cálculo

        $req = new Request();
        $req->getFromDB($reqId);
        $this->check('[E2E] sigue en DRAFT (editable) antes de submit', $req->isDraft() && $req->fields['number'] === null);
        // 3×5.000.000 + 10×15.000 = 15.000.000 + 150.000 = 15.150.000
        $this->check('[E2E] total recalculado tras editar = 15150000', (int) $req->fields['amount_estimated'] === 15150000);

        // Construir REQUEST_SCOPE del borrador (aún sin ejecutar aprobación).
        $items = $rm->loadItems($reqId);
        $snap = (new ScopeSnapshotBuilder())->build($req, $items, ScopeCatalog::SCOPE_REQUEST);
        $this->check('[E2E] snapshot REQUEST_SCOPE con subject/entidad correctos', $snap['subject_type'] === Request::class && (int) $snap['subject_id'] === $reqId && (int) $snap['entity_id'] === $this->entityA);
        $leak = array_intersect(array_keys($snap['payload']), ScopeCatalog::COMMERCIAL_ONLY_KEYS);
        $this->check('[E2E] REQUEST_SCOPE no filtra campos comerciales', $leak === []);
        $this->check('[E2E] REQUEST_SCOPE incluye líneas ordenadas', isset($snap['payload']['lines']) && count($snap['payload']['lines']) === 2);

        // Submit: asigna número + pinnea scopes_version + estado PENDING (snapshot).
        $seq = $rm->submitDraft($reqId);
        $req2 = new Request();
        $req2->getFromDB($reqId);
        $this->check('[E2E] submit asigna número visible', is_string($req2->fields['number']) && $req2->fields['number'] === NumberingService::formatNumber('request', (int) $req2->fields['number_year'], $seq));
        $this->check('[E2E] submit pinnea scopes_version (>=1)', (int) $req2->fields['scopes_version'] >= 1);
        $this->check('[E2E] estado de dominio pasa a PENDING (snapshot)', (string) $req2->fields['domain_state'] === Request::STATE_PENDING);
        $this->check('[E2E] ya no es editable como borrador', !$req2->isDraft());

        // Auditoría: al menos created + submitted registrados.
        $this->check('[E2E] auditoría de negocio registrada (created+submitted)', $this->countEvents($reqId, PurchasingEvent::EV_REQUEST_CREATED) >= 1 && $this->countEvents($reqId, PurchasingEvent::EV_REQUEST_SUBMITTED) >= 1);
    }

    // ---------------------------------------------------------------- [ACL]

    private function scenarioAcl(): void
    {
        $this->out->writeln('== [ACL] fail-closed ==');
        // Sesión SIN ningún bit del plugin.
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => 0]);
        $rm = new RequestManager();
        $this->check('[ACL] crear sin CREATE_REQUEST → denegado', $this->throws(fn() => $rm->createDraft(['entities_id' => $this->entityA])));

        // Crear con permiso, luego intentar editar SIN EDIT_DRAFT.
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $reqId = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'acl']);
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => Request::RIGHT_CREATE_REQUEST | READ]);
        $this->check('[ACL] editar sin EDIT_DRAFT → denegado', $this->throws(fn() => $rm->updateDraft($reqId, ['reason' => 'x'])));
    }

    // ---------------------------------------------------------------- [MULTI-ENTIDAD]

    private function scenarioMultiEntity(): void
    {
        $this->out->writeln('== [MULTI-ENTIDAD] aislamiento ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();
        $reqId = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'iso']);

        // Cambiar la sesión a la entidad B (con todos los derechos, pero SIN acceso a la entidad A).
        $this->applySession($this->uOwner, [$this->entityB], ['plugin_companypurchasing' => self::FULL]);
        $this->check('[MULTI-ENTIDAD] la entidad B no puede ver la solicitud de A', $this->throws(fn() => $rm->getViewable($reqId)));

        // La entidad A sí la ve.
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $seen = false;
        try {
            $rm->getViewable($reqId);
            $seen = true;
        } catch (\Throwable) {
        }
        $this->check('[MULTI-ENTIDAD] la entidad A sí la ve', $seen);
    }

    // ---------------------------------------------------------------- [REUSE Supplier/Budget]

    private function scenarioSupplierBudget(): void
    {
        $this->out->writeln('== [REUSE] Supplier/Budget nativos ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $sup = (int) (new Supplier())->add(['name' => 'CP-SUP-' . $this->suffix, 'entities_id' => $this->entityA]);
        $bud = (int) (new Budget())->add(['name' => 'CP-BUD-' . $this->suffix, 'entities_id' => $this->entityA]);
        if ($sup > 0) {
            $this->createdSuppliers[] = $sup;
        }
        if ($bud > 0) {
            $this->createdBudgets[] = $bud;
        }
        $rm = new RequestManager();
        $reqId = $rm->createDraft([
            'entities_id' => $this->entityA, 'reason' => 'refs',
            'suppliers_id_suggested' => $sup, 'budgets_id' => $bud,
        ]);
        $req = new Request();
        $req->getFromDB($reqId);
        $this->check('[REUSE] referencia a Supplier/Budget nativos persistida', (int) $req->fields['suppliers_id_suggested'] === $sup && (int) $req->fields['budgets_id'] === $bud);
    }

    // ---------------------------------------------------------------- [NEGATIVOS]

    private function scenarioNegatives(): void
    {
        $this->out->writeln('== [NEG] validaciones ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();
        $reqId = $rm->createDraft(['entities_id' => $this->entityA, 'currency_code' => 'PYG', 'reason' => 'neg']);

        $l1 = $rm->addLine($reqId, ['description' => 'x', 'quantity' => '1', 'estimated_unit_price' => '100', 'line_no' => 1]);
        $this->check('[NEG] line_no duplicado → rechazado', $this->throws(fn() => $rm->addLine($reqId, ['description' => 'y', 'quantity' => '1', 'estimated_unit_price' => '100', 'line_no' => 1])));

        // Reordenar: cambiar line_no NO cambia la identidad (id).
        $rm->updateLine($l1, ['line_no' => 9]);
        $it = new RequestItem();
        $it->getFromDB($l1);
        $this->check('[NEG] reordenar cambia line_no pero no la identidad', (int) $it->getID() === $l1 && (int) $it->fields['line_no'] === 9);

        $this->check('[NEG] precio decimal inválido (PYG "100.50") → rechazado', $this->throws(fn() => $rm->addLine($reqId, ['description' => 'z', 'quantity' => '1', 'estimated_unit_price' => '100.50'])));
        $this->check('[NEG] cantidad decimal → rechazada', $this->throws(fn() => $rm->addLine($reqId, ['description' => 'q', 'quantity' => '2.5', 'estimated_unit_price' => '100'])));
        $this->check('[NEG] cantidad cero → rechazada', $this->throws(fn() => $rm->addLine($reqId, ['description' => 'q', 'quantity' => '0', 'estimated_unit_price' => '100'])));
    }

    // ---------------------------------------------------------------- [MIGRATE]

    private function scenarioReinstall(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [MIGRATE] install/uninstall/reinstall reversible ==');
        $hook = dirname(__DIR__, 2) . '/hook.php';
        if (!function_exists('plugin_companypurchasing_uninstall')) {
            include_once $hook;
        }
        if (!function_exists('plugin_companypurchasing_uninstall') || !function_exists('plugin_companypurchasing_install')) {
            $this->check('[MIGRATE] funciones de instalación disponibles', false);
            return;
        }
        $table = 'glpi_plugin_companypurchasing_requests';

        plugin_companypurchasing_uninstall();
        // Comprobación EN VIVO (SHOW TABLES): la caché de esquema de GLPI no se invalida tras un DROP por
        // SQL crudo dentro del mismo proceso, así que no se usa $DB->tableExists() aquí.
        $this->check('[MIGRATE] uninstall elimina las tablas propias', !$this->tableExistsLive($table));

        plugin_companypurchasing_install();
        $this->check('[MIGRATE] reinstall recrea las tablas propias', $this->tableExistsLive($table));
        // Scopes re-sembrados tras reinstalar.
        $seeded = 0;
        foreach ($DB->request(['FROM' => ScopeDef::getTable(), 'WHERE' => ['scopes_version' => 1]]) as $ignored) {
            $seeded++;
        }
        $this->check('[MIGRATE] reinstall re-siembra los scopes v1', $seeded >= 2);
    }

    // ---------------------------------------------------------------- helpers

    /** Existencia de tabla EN VIVO (independiente de la caché de esquema de GLPI). */
    private function tableExistsLive(string $table): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        $res = $DB->doQuery("SHOW TABLES LIKE '" . $table . "'");
        return $res !== false && $DB->numrows($res) > 0;
    }

    private function countEvents(int $reqId, string $event): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request(['FROM' => PurchasingEvent::getTable(), 'WHERE' => ['requests_id' => $reqId, 'event' => $event]]) as $ignored) {
            $n++;
        }
        return $n;
    }

    private function makeEntity(string $tag): int
    {
        $id = (int) (new Entity())->add(['name' => 'CP-ENT-' . $tag . '-' . $this->suffix, 'entities_id' => 0]);
        if ($id > 0) {
            $this->createdEntities[] = $id;
        }
        return $id;
    }

    private function makeUser(string $tag): int
    {
        $id = (int) (new User())->add(['name' => 'cp_' . $tag . '_' . $this->suffix, 'realname' => 'CP ' . $tag, '_no_history' => true]);
        if ($id > 0) {
            $this->createdUsers[] = $id;
        }
        return $id;
    }

    /**
     * @param array<int>        $entities
     * @param array<string,int> $rights
     */
    private function applySession(int $userId, array $entities, array $rights, int $recursive = 0): void
    {
        $_SESSION['glpiID']                      = $userId;
        $_SESSION['glpiname']                    = 'cp_selftest';
        $_SESSION['glpiactive_entity']           = $entities[0] ?? 0;
        $_SESSION['glpiactiveentities']          = $entities;
        $_SESSION['glpiactiveentities_string']   = "'" . implode("','", $entities) . "'";
        $_SESSION['glpiactive_entity_recursive'] = $recursive;
        $_SESSION['glpigroups']                  = [];
        $this->clock += 5;
        $_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s', $this->clock);
        $_SESSION['glpiactiveprofile']           = array_merge(['id' => 1, 'interface' => 'central', 'entities_id' => $entities[0] ?? 0], $rights);
    }

    private function cleanup(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            // Datos de negocio propios (por si el reinstall no corre).
            foreach ([
                'glpi_plugin_companypurchasing_events',
                'glpi_plugin_companypurchasing_items',
                'glpi_plugin_companypurchasing_requests',
            ] as $t) {
                if ($DB->tableExists($t)) {
                    $DB->delete($t, ['id' => ['>', 0]]);
                }
            }
            foreach ($this->createdSuppliers as $s) {
                (new Supplier())->delete(['id' => $s], true);
            }
            foreach ($this->createdBudgets as $b) {
                (new Budget())->delete(['id' => $b], true);
            }
            foreach ($this->createdUsers as $u) {
                (new User())->delete(['id' => $u], true);
            }
            foreach ($this->createdEntities as $e) {
                (new Entity())->delete(['id' => $e], true);
            }
        } catch (\Throwable) {
            // best-effort; el stack de CI es efímero.
        }
    }

    private function throws(callable $fn): bool
    {
        try {
            $fn();
            return false;
        } catch (\Throwable) {
            return true;
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
