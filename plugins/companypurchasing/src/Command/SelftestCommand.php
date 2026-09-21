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
 *   [REF-ENTITY]  referencias válidas PARA la entidad de la solicitud (misma/ancestro recursivo; no cross-branch).
 *   [REF-MOVE]    autoridad por cadena viva: mover una entidad invalida la caché stale (best-effort e2e).
 *   [ROLLBACK]    mutación + auditoría atómicas: fallo de Audit → rollback (sin dato/evento parcial).
 *   [IDENTITY]    (C) solicitante = usuario autenticado; is_recursive baseline 0; departamento visible.
 *   [SCOPE-*]     (B) pinning fail-closed + validación semántica del vocabulario de scopes.
 *   [NEG]         line_no duplicado; reordenar cambia line_no no identidad; decimal inválido;
 *                 PYG con fracción; cantidad inválida.
 *   [CONCURRENCY] (A) procesos REALES en paralelo: numeración, doble submit, edit/addline vs submit.
 *   [CRASH-SAFE]  (D) submit + auditoría atómicos/durables: fallo del evento → rollback (no PENDING).
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
use GlpiPlugin\Companypurchasing\Service\Audit;
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
    private array $createdGroups = [];
    private array $createdSuppliers = [];
    private array $createdBudgets = [];

    private int $entityA = 0;
    private int $entityB = 0;
    private int $uOwner = 0;
    private int $gDept = 0;

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
            $this->scenarioMultiEntityNumbering();
            $this->scenarioCrudE2E();
            $this->scenarioAcl();
            $this->scenarioMultiEntity();
            $this->scenarioSupplierBudget();
            $this->scenarioReferenceEntityScope();
            $this->scenarioEntityMoveAuthoritative();
            $this->scenarioRequesterIdentity();
            $this->scenarioScopeFailClosed();
            $this->scenarioScopeSemantic();
            $this->scenarioNegatives();
            $this->scenarioConcurrentNumbering();
            $this->scenarioConcurrentSubmit();
            $this->scenarioConcurrentMutations();
            $this->scenarioSubmitCrashSafe();
            $this->scenarioMutationRollback();
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
        $this->gDept   = $this->makeGroup('dept', $this->entityA);
        $this->check('[SETUP] entidades + usuario + grupo', $this->entityA > 0 && $this->entityB > 0 && $this->uOwner > 0 && $this->gDept > 0);
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
            'groups_id_department' => $this->gDept, 'category' => 'IT', 'destination' => 'Depósito', 'reason' => 'e2e',
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
        $this->check('[E2E] snapshot SEMÁNTICO (sin document_version hardcodeada)', !array_key_exists('document_version', $snap));
        $env = ScopeSnapshotBuilder::envelope($snap, 7);
        $this->check('[E2E] envelope() exige document_version>0 (parametrizada, no inventada)', (int) $env['document_version'] === 7 && $this->throws(fn() => ScopeSnapshotBuilder::envelope($snap, 0)));
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

        // Negativos de validación de referencias.
        $this->check('[REUSE] Supplier inexistente → rechazo', $this->throws(fn() => $rm->createDraft(['entities_id' => $this->entityA, 'suppliers_id_suggested' => 99999999])));
        $this->check('[REUSE] Budget inexistente → rechazo', $this->throws(fn() => $rm->createDraft(['entities_id' => $this->entityA, 'budgets_id' => 99999999])));
        // Referencia cross-entity NO visible: proveedor en B, sesión sólo en A.
        $this->applySession($this->uOwner, [$this->entityA, $this->entityB], ['plugin_companypurchasing' => self::FULL], 1);
        $supB = (int) (new Supplier())->add(['name' => 'CP-SUPB-' . $this->suffix, 'entities_id' => $this->entityB]);
        if ($supB > 0) {
            $this->createdSuppliers[] = $supB;
        }
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $this->check('[REUSE] referencia cross-entity no visible → rechazo', $this->throws(fn() => $rm->createDraft(['entities_id' => $this->entityA, 'suppliers_id_suggested' => $supB])));
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

    // ---------------------------------------------------------------- [MULTI-ENT-NUM]

    private function scenarioMultiEntityNumbering(): void
    {
        $this->out->writeln('== [MULTI-ENT-NUM] mismo número visible en entidades distintas ==');
        // El texto visible "REQUEST-2099-000001" puede coexistir en A y B (secuencias independientes),
        // pero jamás repetirse dentro de la misma entidad (ni por `number` ni por seq).
        $a1 = $this->insertReqRow($this->entityA, 'REQUEST-2099-000001', 1, 2099);
        $b1 = $this->insertReqRow($this->entityB, 'REQUEST-2099-000001', 1, 2099);
        $this->check('[MULTI-ENT-NUM] A y B con el MISMO número visible → ambas persisten', $a1 && $b1);
        $dupNumber = $this->insertReqRow($this->entityA, 'REQUEST-2099-000001', 2, 2099);
        $this->check('[MULTI-ENT-NUM] mismo número en la MISMA entidad → rechazado (UNIQUE ent_number)', !$dupNumber);
        $dupSeq = $this->insertReqRow($this->entityA, 'REQUEST-2099-000002', 1, 2099);
        $this->check('[MULTI-ENT-NUM] mismo seq en la MISMA entidad/año → rechazado (UNIQUE ent_seq)', !$dupSeq);
    }

    // ---------------------------------------------------------------- [SCOPE-FAILCLOSED]

    private function scenarioScopeFailClosed(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [SCOPE-FAILCLOSED] pinning estricto ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();

        // (a) versión vigente INCOMPLETA (falta COMMERCIAL) → submit falla; sigue DRAFT y sin número.
        $reqId = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'scopefc']);
        $DB->delete(ScopeDef::getTable(), ['scopes_version' => 1, 'scope_key' => ScopeCatalog::SCOPE_COMMERCIAL_FINANCIAL]);
        $threw = $this->throws(fn() => $rm->submitDraft($reqId));
        $req = new Request();
        $req->getFromDB($reqId);
        $this->check('[SCOPE-FAILCLOSED] scope vigente incompleto → submit falla', $threw);
        $this->check('[SCOPE-FAILCLOSED] sigue DRAFT y SIN número (no consumió secuencia)', $req->isDraft() && $req->fields['number'] === null);
        (new ScopeDef())->add([
            'scopes_version' => 1, 'scope_key' => ScopeCatalog::SCOPE_COMMERCIAL_FINANCIAL,
            'fields_json' => json_encode(ScopeCatalog::defaultFields(ScopeCatalog::SCOPE_COMMERCIAL_FINANCIAL, 1)),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
        $this->check('[SCOPE-FAILCLOSED] assertVersionComplete(999) inexistente → lanza', $this->throws(fn() => ScopeCatalog::assertVersionComplete(999)));

        // (b) fields_json corrupto → build de snapshot falla (no fallback a defaults).
        $items = $rm->loadItems($reqId);
        $DB->update(ScopeDef::getTable(), ['fields_json' => '{invalido'], ['scopes_version' => 1, 'scope_key' => ScopeCatalog::SCOPE_REQUEST]);
        $req->getFromDB($reqId);
        $this->check('[SCOPE-FAILCLOSED] fields_json corrupto → snapshot falla', $this->throws(fn() => (new ScopeSnapshotBuilder())->build($req, $items, ScopeCatalog::SCOPE_REQUEST, 1)));
        $DB->update(ScopeDef::getTable(), ['fields_json' => json_encode(ScopeCatalog::defaultFields(ScopeCatalog::SCOPE_REQUEST, 1))], ['scopes_version' => 1, 'scope_key' => ScopeCatalog::SCOPE_REQUEST]);

        // (c) amount almacenado incompatible con PYG → snapshot falla (sin fallback silencioso).
        $DB->update(Request::getTable(), ['amount_estimated' => '1.500000'], ['id' => $reqId]);
        $req->getFromDB($reqId);
        $items = $rm->loadItems($reqId);
        $this->check('[SCOPE-FAILCLOSED] amount incompatible con PYG → snapshot falla', $this->throws(fn() => (new ScopeSnapshotBuilder())->build($req, $items, ScopeCatalog::SCOPE_REQUEST, 1)));
    }

    // ---------------------------------------------------------------- [CONCURRENCY]

    private function scenarioConcurrentNumbering(): void
    {
        $this->out->writeln('== [CONCURRENCY] numeración: procesos REALES en paralelo ==');
        $year = 2097;
        $n = 6;
        $base = 'php bin/console plugins:companypurchasing:concurrency-probe --op=assign --entity='
            . $this->entityA . ' --year=' . $year . ' --no-interaction';
        $outs = $this->runParallel(array_fill(0, $n, $base));
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $seqs = [];
        foreach ($outs as $o) {
            if (preg_match('/OK:(\d+)/', $o, $m) === 1) {
                $seqs[] = (int) $m[1];
            }
        }
        $this->check('[CONCURRENCY] los ' . $n . ' procesos devolvieron número', count($seqs) === $n);
        $this->check('[CONCURRENCY] todos DISTINTOS (sin lost update)', $seqs !== [] && count(array_unique($seqs)) === count($seqs));
        sort($seqs);
        $this->check('[CONCURRENCY] secuencia contigua 1..' . $n . ' (una sola secuencia por entidad/año)', $seqs === range(1, $n));
    }

    private function scenarioConcurrentSubmit(): void
    {
        $this->out->writeln('== [CONCURRENCY] submit: doble envío del MISMO request ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();
        $reqId = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'concsubmit']);
        $rm->addLine($reqId, ['description' => 'x', 'quantity' => '1', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0]);
        $cmd = 'php bin/console plugins:companypurchasing:concurrency-probe --op=submit --request=' . $reqId . ' --no-interaction';
        $outs = $this->runParallel([$cmd, $cmd]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $seqs = [];
        foreach ($outs as $o) {
            if (preg_match('/OK:(\d+)/', $o, $m) === 1) {
                $seqs[] = (int) $m[1];
            }
        }
        $this->check('[CONCURRENCY] ambos procesos OK (idempotente)', count($seqs) === 2);
        $this->check('[CONCURRENCY] mismo número devuelto por ambos', count($seqs) === 2 && $seqs[0] === $seqs[1]);
        $req = new Request();
        $req->getFromDB($reqId);
        $this->check('[CONCURRENCY] una sola transición DRAFT→PENDING (número asignado una vez)', (string) $req->fields['domain_state'] === Request::STATE_PENDING && (int) $req->fields['number_seq'] > 0);
        $this->check('[CONCURRENCY] un solo evento REQUEST_SUBMITTED', $this->countEvents($reqId, PurchasingEvent::EV_REQUEST_SUBMITTED) === 1);
    }

    // ---------------------------------------------------------------- [IDENTITY]

    /**
     * Invariante C: el solicitante es SIEMPRE el usuario autenticado (no hay "crear en nombre de" en
     * P2D-1); `is_recursive` no queda bajo control del solicitante; el departamento debe existir y ser
     * visible desde la entidad de la solicitud.
     */
    private function scenarioRequesterIdentity(): void
    {
        $this->out->writeln('== [IDENTITY] solicitante = usuario autenticado; departamento válido/visible ==');
        // Grupo en la entidad B (creado con una sesión amplia) para probar el cruce de entidad.
        $this->applySession($this->uOwner, [$this->entityA, $this->entityB], ['plugin_companypurchasing' => self::FULL], 1);
        $gB = $this->makeGroup('deptB', $this->entityB);

        // Sesión de trabajo: SÓLO entidad A; usuario autenticado = uOwner.
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();

        // (1) Declarar OTRO solicitante → rechazado (CREATE_REQUEST no concede "en nombre de").
        $this->check('[IDENTITY] declarar OTRO solicitante → rechazado', $this->throws(fn() => $rm->createDraft([
            'entities_id' => $this->entityA, 'users_id_requester' => 999999, 'reason' => 'spoof',
        ])));

        // (2) Solicitante = usuario autenticado; (3) is_recursive forzado a 0 aunque el input pida 1.
        $reqId = $rm->createDraft(['entities_id' => $this->entityA, 'is_recursive' => 1, 'reason' => 'identity']);
        $req = new Request();
        $req->getFromDB($reqId);
        $this->check('[IDENTITY] users_id_requester = usuario autenticado', (int) $req->fields['users_id_requester'] === $this->uOwner);
        $this->check('[IDENTITY] is_recursive fuera del control del solicitante (baseline 0)', (int) $req->fields['is_recursive'] === 0);

        // (4) Departamento inexistente → rechazado.
        $this->check('[IDENTITY] groups_id_department inexistente → rechazado', $this->throws(fn() => $rm->createDraft([
            'entities_id' => $this->entityA, 'groups_id_department' => 99999999, 'reason' => 'nogrp',
        ])));

        // (5) Departamento de OTRA entidad (no visible desde A) → rechazado.
        $this->check('[IDENTITY] groups_id_department de otra entidad → rechazado', $this->throws(fn() => $rm->createDraft([
            'entities_id' => $this->entityA, 'groups_id_department' => $gB, 'reason' => 'crossgrp',
        ])));

        // Departamento válido y visible → aceptado.
        $reqOk = $rm->createDraft(['entities_id' => $this->entityA, 'groups_id_department' => $this->gDept, 'reason' => 'okgrp']);
        $reqO = new Request();
        $reqO->getFromDB($reqOk);
        $this->check('[IDENTITY] departamento válido/visible → aceptado', (int) $reqO->fields['groups_id_department'] === $this->gDept);
    }

    // ---------------------------------------------------------------- [SCOPE-SEMANTIC]

    /**
     * Invariante B: validación SEMÁNTICA del vocabulario de scopes. `assertVersionComplete()` rechaza
     * typo/clave desconocida, duplicados, REQUEST_SCOPE contaminado con claves comerciales, incumplir
     * baseline y scope vacío; `selectFields()` es fail-closed ante una clave protegida no producible.
     */
    private function scenarioScopeSemantic(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [SCOPE-SEMANTIC] validación semántica del vocabulario de scopes ==');
        $table  = ScopeDef::getTable();
        $reqKey = ScopeCatalog::SCOPE_REQUEST;
        $orig   = json_encode(ScopeCatalog::defaultFields($reqKey, 1));

        $setReq = static function (array $fields) use ($DB, $table, $reqKey): void {
            $DB->update($table, ['fields_json' => json_encode($fields)], ['scopes_version' => 1, 'scope_key' => $reqKey]);
        };

        $setReq(['requester', 'quantitty', 'lines']); // typo
        $this->check('[SCOPE-SEMANTIC] clave typo/desconocida → assertVersionComplete lanza', $this->throws(fn() => ScopeCatalog::assertVersionComplete(1)));

        $setReq(['requester', 'requester', 'lines']); // duplicada
        $this->check('[SCOPE-SEMANTIC] clave duplicada → lanza', $this->throws(fn() => ScopeCatalog::assertVersionComplete(1)));

        $setReq(['requester', 'lines', 'total']); // clave comercial en REQUEST_SCOPE
        $this->check('[SCOPE-SEMANTIC] REQUEST_SCOPE con clave comercial → lanza', $this->throws(fn() => ScopeCatalog::assertVersionComplete(1)));

        $setReq(['lines', 'category']); // incumple baseline (falta requester)
        $this->check('[SCOPE-SEMANTIC] REQUEST_SCOPE sin baseline (requester) → lanza', $this->throws(fn() => ScopeCatalog::assertVersionComplete(1)));

        $setReq([]); // vacío
        $this->check('[SCOPE-SEMANTIC] scope vacío → lanza', $this->throws(fn() => ScopeCatalog::assertVersionComplete(1)));

        // Restaurar la definición válida y confirmar que vuelve a validar (no queda envenenada).
        $DB->update($table, ['fields_json' => $orig], ['scopes_version' => 1, 'scope_key' => $reqKey]);
        $restored = false;
        try {
            ScopeCatalog::assertVersionComplete(1);
            $restored = true;
        } catch (\Throwable) {
        }
        $this->check('[SCOPE-SEMANTIC] definición restaurada → válida de nuevo', $restored);

        // selectFields fail-closed: una clave protegida que el builder NO produce → lanza (no snapshot parcial).
        $this->check('[SCOPE-SEMANTIC] selectFields con clave no producible → lanza (fail-closed)', $this->throws(fn() => ScopeSnapshotBuilder::selectFields(['requester' => 1], ['requester', 'suppliers_id_selected'])));
    }

    // ---------------------------------------------------------------- [CONCURRENCY-MUT]

    /**
     * Invariante A: TODAS las mutaciones de una solicitud se serializan bajo el lock común `request_<id>`.
     * Nunca se edita un DRAFT que otro worker ya envió (efecto parcial post-PENDING) ni queda un total
     * stale respecto de las líneas. Procesos REALES en paralelo.
     */
    private function scenarioConcurrentMutations(): void
    {
        $this->out->writeln('== [CONCURRENCY-MUT] mutaciones serializadas por el lock común de la solicitud ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();

        // (1) editar-vs-enviar en PARALELO.
        $r1 = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'edit-vs-submit', 'observations' => 'orig']);
        $rm->addLine($r1, ['description' => 'x', 'quantity' => '1', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0]);
        $editCmd = 'php bin/console plugins:companypurchasing:concurrency-probe --op=edit --request=' . $r1 . ' --no-interaction';
        $subCmd1 = 'php bin/console plugins:companypurchasing:concurrency-probe --op=submit --request=' . $r1 . ' --no-interaction';
        $o1 = $this->runParallel([$editCmd, $subCmd1]);
        $this->out->writeln('  edit|submit: ' . implode(' | ', $o1));
        $req1 = new Request();
        $req1->getFromDB($r1);
        $this->check('[CONCURRENCY-MUT] edit-vs-submit: termina PENDING con número', (string) $req1->fields['domain_state'] === Request::STATE_PENDING && (int) $req1->fields['number_seq'] > 0);
        $this->check('[CONCURRENCY-MUT] edit-vs-submit: un solo REQUEST_SUBMITTED', $this->countEvents($r1, PurchasingEvent::EV_REQUEST_SUBMITTED) === 1);
        // Si el edit fue OK (ganó el lock antes del submit), su efecto es COMPLETO; si perdió, el DRAFT ya
        // no era editable (ERR) y no dejó rastro parcial. Jamás un edit a medias tras quedar PENDING.
        $editOk = str_contains($o1[0] ?? '', 'OK:edited');
        $this->check('[CONCURRENCY-MUT] edit-vs-submit: si el edit fue OK, su efecto es completo (no parcial)', !$editOk || str_starts_with((string) $req1->fields['observations'], 'edited-'));
        $this->check('[CONCURRENCY-MUT] edit-vs-submit: si el edit perdió, no hubo cambio parcial', $editOk || (string) $req1->fields['observations'] === 'orig');

        // (2) agregar-línea-vs-enviar: el total nunca queda stale respecto de las líneas.
        $r2 = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'addline-vs-submit']);
        $rm->addLine($r2, ['description' => 'base', 'quantity' => '1', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0]);
        $addCmd  = 'php bin/console plugins:companypurchasing:concurrency-probe --op=addline --request=' . $r2 . ' --no-interaction';
        $subCmd2 = 'php bin/console plugins:companypurchasing:concurrency-probe --op=submit --request=' . $r2 . ' --no-interaction';
        $o2 = $this->runParallel([$addCmd, $subCmd2]);
        $this->out->writeln('  addline|submit: ' . implode(' | ', $o2));
        $this->check('[CONCURRENCY-MUT] addline-vs-submit: total consistente con las líneas', $this->totalMatchesLines($r2));
        $this->check('[CONCURRENCY-MUT] addline-vs-submit: un solo REQUEST_SUBMITTED', $this->countEvents($r2, PurchasingEvent::EV_REQUEST_SUBMITTED) === 1);

        // (3) dos addLine SIMULTÁNEOS: ambas líneas persisten (line_no serializado) y el total cuadra.
        $r3 = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'addline-x2']);
        $rm->addLine($r3, ['description' => 'base', 'quantity' => '1', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0]);
        $addC = 'php bin/console plugins:companypurchasing:concurrency-probe --op=addline --request=' . $r3 . ' --no-interaction';
        $o3 = $this->runParallel([$addC, $addC]);
        $this->out->writeln('  addline x2: ' . implode(' | ', $o3));
        $bothOk = count(array_filter($o3, static fn($o) => str_starts_with((string) $o, 'OK:'))) === 2;
        $this->check('[CONCURRENCY-MUT] dos addLine simultáneos: ambos OK (line_no serializado)', $bothOk);
        $this->check('[CONCURRENCY-MUT] dos addLine simultáneos: 3 líneas persistidas', count($rm->loadItems($r3)) === 3);
        $this->check('[CONCURRENCY-MUT] dos addLine simultáneos: total cuadra con líneas', $this->totalMatchesLines($r3));
    }

    // ---------------------------------------------------------------- [CRASH-SAFE]

    /**
     * Invariante D: la frontera submit + auditoría es ATÓMICA y DURABLE (PENDING ⇔ existe exactamente un
     * REQUEST_SUBMITTED durable). Si el evento no persiste, ROLLBACK: la solicitud NO queda PENDING. El
     * reintento completa y deja exactamente un REQUEST_SUBMITTED (idempotency_key durable).
     */
    private function scenarioSubmitCrashSafe(): void
    {
        $this->out->writeln('== [CRASH-SAFE] submit + auditoría atómicos y durables ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);
        $rm = new RequestManager();
        $reqId = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'crashsafe']);
        $rm->addLine($reqId, ['description' => 'x', 'quantity' => '1', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0]);

        // Auditoría que FALLA deliberadamente SÓLO al registrar REQUEST_SUBMITTED (simula una caída entre
        // el UPDATE DRAFT→PENDING y el INSERT del evento). La frontera atómica debe hacer ROLLBACK.
        $failingAudit = new class extends Audit {
            public function record(int $requestsId, string $event, int $entitiesId, array $detail = [], string $correlationId = '', ?string $idempotencyKey = null): void
            {
                if ($event === PurchasingEvent::EV_REQUEST_SUBMITTED) {
                    throw new \RuntimeException('fallo deliberado al registrar REQUEST_SUBMITTED (crash simulado)');
                }
                parent::record($requestsId, $event, $entitiesId, $detail, $correlationId, $idempotencyKey);
            }
        };
        $rmFail = new RequestManager(null, $failingAudit);
        $this->check('[CRASH-SAFE] submit lanza si el evento no persiste', $this->throws(fn() => $rmFail->submitDraft($reqId)));

        $req = new Request();
        $req->getFromDB($reqId);
        $this->check('[CRASH-SAFE] la solicitud NO queda PENDING (rollback)', $req->isDraft() && $req->fields['number'] === null && (int) ($req->fields['number_seq'] ?? 0) === 0);
        $this->check('[CRASH-SAFE] 0 eventos REQUEST_SUBMITTED tras el fallo', $this->countEvents($reqId, PurchasingEvent::EV_REQUEST_SUBMITTED) === 0);

        // Reintento con auditoría normal: completa y deja EXACTAMENTE un REQUEST_SUBMITTED durable.
        $seq = $rm->submitDraft($reqId);
        $req2 = new Request();
        $req2->getFromDB($reqId);
        $this->check('[CRASH-SAFE] reintento → PENDING con número', (string) $req2->fields['domain_state'] === Request::STATE_PENDING && (int) $req2->fields['number_seq'] === $seq && $seq > 0);
        $this->check('[CRASH-SAFE] exactamente un REQUEST_SUBMITTED tras recuperar', $this->countEvents($reqId, PurchasingEvent::EV_REQUEST_SUBMITTED) === 1);
    }

    // ---------------------------------------------------------------- [REF-ENTITY]

    /**
     * Punto 3: las referencias nativas se validan contra la ENTIDAD de la solicitud (no contra la
     * sesión). Un usuario con acceso a A+B NO puede adjuntar a una solicitud de A un maestro exclusivo de
     * la rama B; un maestro RECURSIVO de una entidad ANCESTRO sí aplica a una solicitud de la entidad hija.
     */
    private function scenarioReferenceEntityScope(): void
    {
        $this->out->writeln('== [REF-ENTITY] referencias válidas PARA la entidad de la solicitud ==');
        // Entidad HIJA. GLPI puede IGNORAR el `entities_id` de entrada al crear una Entity y parentarla en
        // la raíz; `makeChildEntity()` intenta forzar el padre. El test NO asume dónde queda: lee el
        // ANCESTRO REAL de la hija y coloca allí los suppliers del caso recursivo, así ejercita una
        // relación de ancestro genuina tanto si quedó bajo A como bajo la raíz.
        $this->applySession($this->uOwner, [$this->entityA, $this->entityB], ['plugin_companypurchasing' => self::FULL], 1);
        $childA = $this->makeChildEntity($this->entityA);
        $childRow = new Entity();
        $childRow->getFromDB($childA);
        $ancestor = (int) ($childRow->fields['entities_id'] ?? 0); // A si el reparent funcionó; si no, la raíz (0)
        $this->applySession($this->uOwner, [$this->entityA, $this->entityB, $childA], ['plugin_companypurchasing' => self::FULL], 1);

        $supInA     = $this->makeSupplier('CP-SUPA-' . $this->suffix, $this->entityA, false);  // A (misma entidad)
        $supBrec    = $this->makeSupplier('CP-SUPBR-' . $this->suffix, $this->entityB, true);   // B recursivo (otra rama)
        $supAncRec  = $this->makeSupplier('CP-SUPANR-' . $this->suffix, $ancestor, true);       // ancestro real, recursivo
        $supAncFlat = $this->makeSupplier('CP-SUPANF-' . $this->suffix, $ancestor, false);      // ancestro real, no recursivo
        $this->check('[REF-ENTITY] fixtures (entidad hija + suppliers) creados', $childA > 0 && $supInA > 0 && $supBrec > 0 && $supAncRec > 0 && $supAncFlat > 0);

        $rm = new RequestManager();

        // (1) Sesión A+B; solicitud en A; Supplier EXCLUSIVO de la rama B → RECHAZO (aunque el usuario ve B).
        $this->check('[REF-ENTITY] Supplier de OTRA rama (B) para solicitud en A, con sesión A+B → rechazo', $this->throws(fn() => $rm->createDraft([
            'entities_id' => $this->entityA, 'suppliers_id_suggested' => $supBrec, 'reason' => 'refent-b',
        ])));

        // (2) Solicitud en A; Supplier de la MISMA entidad A → aceptado.
        $okSame = false;
        try {
            $okSame = $rm->createDraft(['entities_id' => $this->entityA, 'suppliers_id_suggested' => $supInA, 'reason' => 'refent-a']) > 0;
        } catch (\Throwable) {
        }
        $this->check('[REF-ENTITY] Supplier de la MISMA entidad (A) → aceptado', $okSame);

        // (3) Solicitud en la hija; Supplier RECURSIVO del ANCESTRO real → aceptado (hereda hacia abajo).
        $okAncestor = false;
        try {
            $okAncestor = $rm->createDraft(['entities_id' => $childA, 'suppliers_id_suggested' => $supAncRec, 'reason' => 'refent-anc']) > 0;
        } catch (\Throwable) {
        }
        $this->check('[REF-ENTITY] Supplier RECURSIVO del ancestro para solicitud en la hija → aceptado', $okAncestor);

        // (4) Solicitud en la hija; Supplier NO recursivo del ancestro → RECHAZO (no se hereda).
        $this->check('[REF-ENTITY] Supplier NO recursivo del ancestro para solicitud en la hija → rechazo', $this->throws(fn() => $rm->createDraft([
            'entities_id' => $childA, 'suppliers_id_suggested' => $supAncFlat, 'reason' => 'refent-flat',
        ])));

        // (5) updateDraft también valida contra la entidad de la solicitud: solicitud en A, referenciar B → rechazo.
        $base = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'refent-upd']);
        $this->check('[REF-ENTITY] updateDraft con Supplier de otra rama (B) → rechazo', $this->throws(fn() => $rm->updateDraft($base, ['suppliers_id_suggested' => $supBrec])));
    }

    // ---------------------------------------------------------------- [REF-MOVE]

    /**
     * Prueba de autoridad end-to-end: mover una entidad entre ramas invalida la relación aunque la CACHÉ
     * del árbol de GLPI (`getSonsOf`/`getAncestorsOf`) quede stale. Se precargan las cachés, se mueve la
     * entidad por el modelo soportado y se comprueba que el resolver AUTORITATIVO (cadena viva
     * `entities_id`) rechaza al padre viejo y acepta al nuevo. Best-effort y NO flaky: la aserción fuerte
     * sólo corre si GLPI reparentó de verdad (fuera de la raíz); si no, la propiedad ya está cubierta por el
     * unit test determinista de `isEntityApplicableInChain`.
     */
    private function scenarioEntityMoveAuthoritative(): void
    {
        $this->out->writeln('== [REF-MOVE] autoridad por cadena viva: mover una entidad invalida la caché ==');
        $this->applySession($this->uOwner, [$this->entityA, $this->entityB], ['plugin_companypurchasing' => self::FULL], 1);

        // Resolver AUTORITATIVO idéntico al del plugin: padre ACTUAL vía Entity::getFromDB (columna viva).
        $liveParent = static function (int $id): ?int {
            $e = new Entity();
            if (!$e->getFromDB($id)) {
                return null;
            }
            if (!array_key_exists('entities_id', $e->fields)) {
                return null;
            }
            return (int) $e->fields['entities_id'];
        };

        $X = $this->makeChildEntity($this->entityA);
        $this->applySession($this->uOwner, [$this->entityA, $this->entityB, $X], ['plugin_companypurchasing' => self::FULL], 1);
        $xr = new Entity();
        $xr->getFromDB($X);
        $p1 = (int) ($xr->fields['entities_id'] ?? 0);
        $this->check('[REF-MOVE] entidad X creada', $X > 0);

        // Precargar las cachés del árbol de GLPI para la posición ACTUAL de X (si las utilidades existen).
        if (function_exists('getSonsOf')) {
            getSonsOf('glpi_entities', $p1);
        }
        if (function_exists('getAncestorsOf')) {
            getAncestorsOf('glpi_entities', $X);
        }

        // Mover X a la rama B por el modelo soportado (no SQL directo).
        (new Entity())->update(['id' => $X, 'entities_id' => $this->entityB]);
        $xr2 = new Entity();
        $xr2->getFromDB($X);
        $p2 = (int) ($xr2->fields['entities_id'] ?? 0);

        // La aserción fuerte sólo aplica si GLPI reparentó de verdad a B saliendo de una rama NO-raíz.
        $reparented = ($p2 === $this->entityB) && ($p2 !== $p1) && ($p1 !== 0);
        if ($reparented) {
            $this->check('[REF-MOVE] X movida A→B: el padre VIEJO (A) ya NO autoriza (cadena viva, no caché)', RequestManager::isEntityApplicableInChain($p1, true, $X, $liveParent) === false);
            $this->check('[REF-MOVE] X movida A→B: el padre NUEVO (B) sí autoriza recursivo', RequestManager::isEntityApplicableInChain($this->entityB, true, $X, $liveParent) === true);
        } else {
            $this->out->writeln('  [REF-MOVE] nota: el árbol de GLPI no reparentó en este stack (p1=' . $p1 . ', p2=' . $p2 . '); propiedad cubierta por el unit test determinista de isEntityApplicableInChain.');
            // Consistencia mínima: el resolver autoritativo concuerda con la cadena viva ACTUAL de X.
            $this->check('[REF-MOVE] resolver autoritativo consistente con la cadena viva de X', RequestManager::isEntityApplicableInChain($p2, true, $X, $liveParent) === true || $p2 === $X);
        }
    }

    // ---------------------------------------------------------------- [ROLLBACK]

    /**
     * Punto 5: cada mutación + su auditoría son ATÓMICAS. Forzando un fallo determinista de `Audit` en el
     * evento de cada operación se demuestra: la operación LANZA, el dato anterior queda intacto y NO queda
     * un evento parcial. Para líneas se verifica además que `amount_estimated` no cambió tras el rollback.
     */
    private function scenarioMutationRollback(): void
    {
        $this->out->writeln('== [ROLLBACK] mutación + auditoría atómicas (fallo de Audit → rollback) ==');
        $this->applySession($this->uOwner, [$this->entityA], ['plugin_companypurchasing' => self::FULL]);

        $mkFail = static function (string $failEvent): Audit {
            return new class($failEvent) extends Audit {
                private string $failEvent;
                public function __construct(string $failEvent)
                {
                    $this->failEvent = $failEvent;
                }
                public function record(int $requestsId, string $event, int $entitiesId, array $detail = [], string $correlationId = '', ?string $idempotencyKey = null): void
                {
                    if ($event === $this->failEvent) {
                        throw new \RuntimeException('fallo deliberado de auditoría: ' . $event);
                    }
                    parent::record($requestsId, $event, $entitiesId, $detail, $correlationId, $idempotencyKey);
                }
            };
        };
        $rmOk = new RequestManager();

        // (A) createDraft: fallo en REQUEST_CREATED → no debe quedar una solicitud huérfana.
        $marker = 'rollback-create-' . $this->suffix;
        $rmC = new RequestManager(null, $mkFail(PurchasingEvent::EV_REQUEST_CREATED));
        $this->check('[ROLLBACK] createDraft con Audit fallando → lanza', $this->throws(fn() => $rmC->createDraft(['entities_id' => $this->entityA, 'reason' => $marker])));
        $this->check('[ROLLBACK] createDraft: NO quedó solicitud huérfana', $this->countRequestsByReason($marker) === 0);

        // Base para las mutaciones sobre una solicitud existente (2 × 1000 = 2000).
        $reqId = $rmOk->createDraft(['entities_id' => $this->entityA, 'observations' => 'obs-orig', 'reason' => 'rollback-base']);
        $l1 = $rmOk->addLine($reqId, ['description' => 'base', 'quantity' => '2', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0]);
        $baseTotal = $this->reqAmount($reqId);
        $this->check('[ROLLBACK] base: total inicial = 2000', $baseTotal === 2000);

        // (B) updateDraft: fallo en REQUEST_UPDATED → observations intacto, sin evento parcial.
        $updBaseline = $this->countEvents($reqId, PurchasingEvent::EV_REQUEST_UPDATED);
        $rmU = new RequestManager(null, $mkFail(PurchasingEvent::EV_REQUEST_UPDATED));
        $this->check('[ROLLBACK] updateDraft con Audit fallando → lanza', $this->throws(fn() => $rmU->updateDraft($reqId, ['observations' => 'obs-cambiado'])));
        $req = new Request();
        $req->getFromDB($reqId);
        $this->check('[ROLLBACK] updateDraft: dato anterior intacto (observations)', (string) $req->fields['observations'] === 'obs-orig');
        $this->check('[ROLLBACK] updateDraft: sin evento REQUEST_UPDATED parcial', $this->countEvents($reqId, PurchasingEvent::EV_REQUEST_UPDATED) === $updBaseline);

        // (C) addLine: fallo en LINE_ADDED → línea no persiste, total intacto, sin evento parcial.
        $addBaseline = $this->countEvents($reqId, PurchasingEvent::EV_LINE_ADDED);
        $linesBefore = count($rmOk->loadItems($reqId));
        $rmA = new RequestManager(null, $mkFail(PurchasingEvent::EV_LINE_ADDED));
        $this->check('[ROLLBACK] addLine con Audit fallando → lanza', $this->throws(fn() => $rmA->addLine($reqId, ['description' => 'x', 'quantity' => '5', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0])));
        $this->check('[ROLLBACK] addLine: línea NO persistida', count($rmOk->loadItems($reqId)) === $linesBefore);
        $this->check('[ROLLBACK] addLine: amount_estimated intacto (2000)', $this->reqAmount($reqId) === $baseTotal);
        $this->check('[ROLLBACK] addLine: sin evento LINE_ADDED parcial', $this->countEvents($reqId, PurchasingEvent::EV_LINE_ADDED) === $addBaseline);

        // (D) updateLine: fallo en LINE_UPDATED → cantidad/total intactos, sin evento parcial.
        $updLineBaseline = $this->countEvents($reqId, PurchasingEvent::EV_LINE_UPDATED);
        $rmUL = new RequestManager(null, $mkFail(PurchasingEvent::EV_LINE_UPDATED));
        $this->check('[ROLLBACK] updateLine con Audit fallando → lanza', $this->throws(fn() => $rmUL->updateLine($l1, ['quantity' => '9'])));
        $it = new RequestItem();
        $it->getFromDB($l1);
        $this->check('[ROLLBACK] updateLine: cantidad intacta (2)', (int) $it->fields['quantity'] === 2);
        $this->check('[ROLLBACK] updateLine: amount_estimated intacto (2000)', $this->reqAmount($reqId) === $baseTotal);
        $this->check('[ROLLBACK] updateLine: sin evento LINE_UPDATED parcial', $this->countEvents($reqId, PurchasingEvent::EV_LINE_UPDATED) === $updLineBaseline);

        // (E) removeLine: fallo en LINE_REMOVED → la línea sigue, total intacto, sin evento parcial.
        $rmvBaseline = $this->countEvents($reqId, PurchasingEvent::EV_LINE_REMOVED);
        $rmR = new RequestManager(null, $mkFail(PurchasingEvent::EV_LINE_REMOVED));
        $this->check('[ROLLBACK] removeLine con Audit fallando → lanza', $this->throws(fn() => $rmR->removeLine($l1)));
        $it2 = new RequestItem();
        $this->check('[ROLLBACK] removeLine: la línea sigue existiendo', $it2->getFromDB($l1) && (int) $it2->fields['requests_id'] === $reqId);
        $this->check('[ROLLBACK] removeLine: amount_estimated intacto (2000)', $this->reqAmount($reqId) === $baseTotal);
        $this->check('[ROLLBACK] removeLine: sin evento LINE_REMOVED parcial', $this->countEvents($reqId, PurchasingEvent::EV_LINE_REMOVED) === $rmvBaseline);
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

    /** Cuenta las solicitudes con un `reason` dado (marcador único para pruebas de rollback de create). */
    private function countRequestsByReason(string $reason): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request(['FROM' => Request::getTable(), 'WHERE' => ['reason' => $reason]]) as $ignored) {
            $n++;
        }
        return $n;
    }

    /** `amount_estimated` de una solicitud como entero (exacto para PYG escala 0); -1 si no existe. */
    private function reqAmount(int $reqId): int
    {
        $req = new Request();
        if (!$req->getFromDB($reqId)) {
            return -1;
        }
        return (int) $req->fields['amount_estimated'];
    }

    /**
     * ¿El total almacenado de la solicitud coincide con la suma de los totales de línea? En PYG (escala 0)
     * los importes son enteros, por lo que la comparación por `(int)` sobre el DECIMAL almacenado es exacta.
     */
    private function totalMatchesLines(int $reqId): bool
    {
        $rm = new RequestManager();
        $sum = 0;
        foreach ($rm->loadItems($reqId) as $it) {
            $sum += (int) $it->fields['estimated_line_total'];
        }
        $req = new Request();
        if (!$req->getFromDB($reqId)) {
            return false;
        }
        return (int) $req->fields['amount_estimated'] === $sum;
    }

    /**
     * Lanza comandos en PARALELO (procesos reales) y devuelve el stdout de cada uno. Habilita el probe
     * de concurrencia vía env (`COMPANYPURCHASING_ALLOW_PROBE=1`).
     *
     * @param array<int,string> $cmds
     * @return array<int,string>
     */
    private function runParallel(array $cmds): array
    {
        $env = getenv();
        $env['COMPANYPURCHASING_ALLOW_PROBE'] = '1';
        $procs = [];
        $pipes = [];
        foreach ($cmds as $i => $cmd) {
            $descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $pp = [];
            $p = @proc_open($cmd, $descr, $pp, getcwd(), $env);
            if (is_resource($p)) {
                $procs[$i] = $p;
                $pipes[$i] = $pp;
            }
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $out[$i] = trim((string) stream_get_contents($pipes[$i][1]));
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($p);
        }
        return $out;
    }

    /** Inserta una fila `requests` (para probar los UNIQUE multi-entidad). Devuelve true si persistió. */
    private function insertReqRow(int $entity, ?string $number, int $seq, int $year): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        $now = date('Y-m-d H:i:s');
        try {
            $ok = $DB->insert(Request::getTable(), [
                'entities_id'      => $entity,
                'number'           => $number,
                'number_scope'     => 'request',
                'number_year'      => $year,
                'number_seq'       => $seq,
                'domain_state'     => Request::STATE_PENDING,
                'currency_code'    => 'PYG',
                'amount_estimated' => 0,
                'date_creation'    => $now,
                'date_mod'         => $now,
            ]);
            return $ok !== false;
        } catch (\Throwable) {
            return false; // violación de UNIQUE (u otro error) → no persistió
        }
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

    private function makeGroup(string $tag, int $entity): int
    {
        $id = (int) (new \Group())->add(['name' => 'CP-GRP-' . $tag . '-' . $this->suffix, 'entities_id' => $entity, 'is_recursive' => 0]);
        if ($id > 0) {
            $this->createdGroups[] = $id;
        }
        return $id;
    }

    /**
     * Crea un Supplier fixture con el `is_recursive` EXACTO pedido. GLPI puede no honrar `is_recursive` en
     * `add()` según el contexto de recursión de la sesión, así que se FUERZA el estado por la interfaz
     * SOPORTADA del modelo (`Supplier::update()`, no SQL directo) y se re-lee para dejarlo consistente.
     */
    private function makeSupplier(string $name, int $entity, bool $recursive): int
    {
        $want = $recursive ? 1 : 0;
        $sup = new Supplier();
        $id = (int) $sup->add(['name' => $name, 'entities_id' => $entity, 'is_recursive' => $want]);
        if ($id <= 0) {
            return 0;
        }
        $this->createdSuppliers[] = $id;
        $cur = new Supplier();
        if ($cur->getFromDB($id) && (int) ($cur->fields['is_recursive'] ?? 0) !== $want) {
            $cur->update(['id' => $id, 'is_recursive' => $want]);
        }
        return $id;
    }

    /**
     * Crea una Entity HIJA de `$parent`. GLPI puede ignorar el `entities_id` de entrada al crear una
     * Entity (parentándola en la raíz); se intenta FORZAR el padre por la interfaz del modelo. El llamador
     * NO debe asumir el padre resultante: debe leerlo (`getFromDB`).
     */
    private function makeChildEntity(int $parent): int
    {
        $id = (int) (new \Entity())->add(['name' => 'CP-ENT-AC-' . $this->suffix, 'entities_id' => $parent]);
        if ($id <= 0) {
            return 0;
        }
        $this->createdEntities[] = $id;
        $cur = new \Entity();
        if ($cur->getFromDB($id) && (int) ($cur->fields['entities_id'] ?? -1) !== $parent) {
            (new \Entity())->update(['id' => $id, 'entities_id' => $parent]);
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
            foreach ($this->createdGroups as $g) {
                (new \Group())->delete(['id' => $g], true);
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
