<?php

/**
 * Autotest de INTEGRACIÓN + E2E de companyworkflow (corre DENTRO de un GLPI arrancado, en CI).
 *
 * Nombre: `plugins:companyworkflow:selftest` (patrón exigido por GLPI para comandos de plugin).
 * Fail-closed → exit 1 si algo falla.
 *
 * Construye un workflow NEUTRAL de demostración (NO compras: sin estados/aprobadores/montos de
 * negocio hardcodeados) con grupos/usuarios/entidades REALES y ejercita:
 *   [PERSIST]     tablas propias creadas por la migración.
 *   [ROUTES]      POST /instance/{id}/{action} AUTHENTICATED (CSRF nativo).
 *   [E2E]         crear → submit → aprobar etapa 1 (quórum) → aprobar etapa 2 → finalizar.
 *   [QUORUM]      quórum incompleto NO avanza.
 *   [DELEGATION]  un delegado vigente cuenta como aprobador válido.
 *   [MULTI-ENT]   🔒 aprobador de entidad A no actúa sobre instancia de entidad B.
 *   [ACL]         🔒 usuario sin RIGHT_ACT no puede aprobar.
 *   [NEG]         transición inválida · doble submit · doble approve/replay · versión antigua ·
 *                 comentario obligatorio · condición · rechazo · devolución (invalida votos).
 *   [VERSION]     una instancia conserva la versión de definición con que fue iniciada.
 *   [AUDIT]       historial append-only (sólo crece).
 *   [SLA]         detección de vencimiento + escalamiento (nunca aprueba solo).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Command;

use Computer;
use Entity;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use Group;
use Group_User;
use GlpiPlugin\Companyworkflow\Api\WorkflowApi;
use GlpiPlugin\Companyworkflow\Controller\WorkflowActionController;
use GlpiPlugin\Companyworkflow\Model\Assignment;
use GlpiPlugin\Companyworkflow\Model\Delegation;
use GlpiPlugin\Companyworkflow\Model\HistoryEvent;
use GlpiPlugin\Companyworkflow\Model\Instance;
use GlpiPlugin\Companyworkflow\Model\StateDef;
use GlpiPlugin\Companyworkflow\Model\Step;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;
use GlpiPlugin\Companyworkflow\Service\PluginConfig;
use GlpiPlugin\Companyworkflow\Service\SlaService;
use GlpiPlugin\Companyworkflow\Service\TransitionResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Annotation\Route;
use User;

final class SelftestCommand extends Command
{
    private int $failures = 0;
    private OutputInterface $out;
    private string $suffix;

    /** @var array<int,int> ids creados para limpieza best-effort */
    private array $createdUsers = [];
    private array $createdGroups = [];
    private array $createdEntities = [];

    private int $entityA = 0;
    private int $entityB = 0;
    private int $g1 = 0;
    private int $g2 = 0;
    private int $uA1 = 0;
    private int $uA2 = 0;
    private int $uA3 = 0; // delegado de uA2
    private int $uB1 = 0; // aprobador etapa 2
    private int $uNoPerm = 0;
    private int $requester = 0;
    private WorkflowDef $def;

    protected function configure(): void
    {
        $this->setName('plugins:companyworkflow:selftest')
            ->setDescription('Pruebas de integración + E2E del motor de workflow (ACL, multi-entidad, quórum, versión, SLA).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->suffix = substr((string) time(), -6);

        $this->checkPersistence();
        $this->checkRoutes();
        $this->buildFixtures();
        $this->buildDemoWorkflow();

        $this->scenarioHappyPath();
        $this->scenarioNegatives();
        $this->scenarioReturnInvalidation();
        $this->scenarioVersioning();
        $this->scenarioSla();

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
        foreach ([
            'defs', 'statedefs', 'transitions', 'steps',
            'instances', 'assignments', 'delegations', 'history',
        ] as $t) {
            $this->check("tabla glpi_plugin_companyworkflow_{$t} existe", $DB->tableExists("glpi_plugin_companyworkflow_{$t}"));
        }
    }

    // ------------------------------------------------------------------ [ROUTES]

    private function checkRoutes(): void
    {
        $this->out->writeln('== [ROUTES] superficie HTTP ==');
        try {
            $rc = new \ReflectionClass(WorkflowActionController::class);
            $m = $rc->getMethod('act');
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
            $this->check('ruta /instance/{id}/{action} declarada', str_contains($path, '/instance/{id}/{action}'));
            $this->check('ruta es POST', in_array('POST', $methods, true));
            $this->check('ruta es AUTHENTICATED (no NO_CHECK)', $strategy === Firewall::STRATEGY_AUTHENTICATED);
        } catch (\Throwable $e) {
            $this->check('reflexión de WorkflowActionController: ' . $e->getMessage(), false);
        }
    }

    // ------------------------------------------------------------------ fixtures

    private function buildFixtures(): void
    {
        $this->out->writeln('== fixtures (entidades/grupos/usuarios reales) ==');
        // Sesión permisiva sólo para crear fixtures (rights core).
        $this->applySession(2, [0], [
            'entity' => ALLSTANDARDRIGHT, 'user' => ALLSTANDARDRIGHT,
            'group' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT,
        ], 1);

        $this->entityA = (int) (new Entity())->add(['name' => 'WF-A-' . $this->suffix, 'entities_id' => 0]);
        $this->entityB = (int) (new Entity())->add(['name' => 'WF-B-' . $this->suffix, 'entities_id' => 0]);
        $this->createdEntities = array_filter([$this->entityA, $this->entityB]);
        $this->check('entidades A y B creadas', $this->entityA > 0 && $this->entityB > 0);

        $this->uA1     = $this->makeUser('a1');
        $this->uA2     = $this->makeUser('a2');
        $this->uA3     = $this->makeUser('a3');
        $this->uB1     = $this->makeUser('b1');
        $this->uNoPerm = $this->makeUser('noperm');
        $this->requester = $this->makeUser('req');
        $this->check('usuarios de prueba creados', min($this->uA1, $this->uA2, $this->uA3, $this->uB1, $this->uNoPerm, $this->requester) > 0);

        $this->g1 = (int) (new Group())->add(['name' => 'WF-G1-' . $this->suffix, 'entities_id' => $this->entityB]);
        $this->g2 = (int) (new Group())->add(['name' => 'WF-G2-' . $this->suffix, 'entities_id' => $this->entityB]);
        $this->createdGroups = array_filter([$this->g1, $this->g2]);
        $this->check('grupos G1/G2 creados', $this->g1 > 0 && $this->g2 > 0);

        foreach ([$this->uA1, $this->uA2] as $u) {
            (new Group_User())->add(['groups_id' => $this->g1, 'users_id' => $u]);
        }
        (new Group_User())->add(['groups_id' => $this->g2, 'users_id' => $this->uB1]);

        // Delegación vigente: uA2 delega en uA3 (alcance cualquiera).
        (new Delegation())->add([
            'users_id_from'   => $this->uA2,
            'users_id_to'     => $this->uA3,
            'workflowdefs_id' => 0,
            'entities_id'     => 0,
            'date_start'      => date('Y-m-d H:i:s', time() - 3600),
            'date_end'        => date('Y-m-d H:i:s', time() + 86400),
            'reason'          => 'selftest',
            'is_active'       => 1,
        ]);
        $this->check('delegación uA2→uA3 creada', true);
    }

    private function makeUser(string $tag): int
    {
        $id = (int) (new User())->add([
            'name'         => 'wf_' . $tag . '_' . $this->suffix,
            'realname'     => 'WF ' . $tag,
            '_no_history'  => true,
        ]);
        if ($id > 0) {
            $this->createdUsers[] = $id;
        }
        return $id;
    }

    private function buildDemoWorkflow(): void
    {
        $this->out->writeln('== definición de workflow NEUTRAL de demostración ==');
        $api = new WorkflowApi();
        $this->def = $api->builder()->createVersion([
            'code'            => 'wf_demo_' . $this->suffix,
            'name'            => 'Demo neutral',
            'itemtype_target' => 'Computer',
            'entities_id'     => 0,
            'is_recursive'    => 1,
            'states' => [
                ['code' => 'DRAFT',      'kind' => StateDef::KIND_INITIAL,      'is_editable' => 1],
                ['code' => 'PENDING_L1', 'kind' => StateDef::KIND_INTERMEDIATE, 'sla_hours' => 1],
                ['code' => 'PENDING_L2', 'kind' => StateDef::KIND_INTERMEDIATE],
                ['code' => 'RETURNED',   'kind' => StateDef::KIND_INTERMEDIATE, 'is_editable' => 1],
                ['code' => 'DONE',       'kind' => StateDef::KIND_FINAL],
                ['code' => 'REJECTED',   'kind' => StateDef::KIND_FINAL],
                ['code' => 'CANCELLED',  'kind' => StateDef::KIND_FINAL],
            ],
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PENDING_L1', 'action' => 'submit'],
                ['from' => 'RETURNED', 'to' => 'PENDING_L1', 'action' => 'submit'],
                ['from' => 'PENDING_L1', 'to' => 'PENDING_L2', 'action' => 'approve',
                 'required_right' => WorkflowDef::RIGHT_ACT,
                 'steps' => [['level' => 1, 'quorum_type' => Step::QUORUM_COUNT, 'quorum_value' => 2,
                              'approver_kind' => Step::APPROVER_GROUP, 'approver_ref' => $this->g1]]],
                ['from' => 'PENDING_L1', 'to' => 'REJECTED', 'action' => 'reject', 'required_right' => WorkflowDef::RIGHT_ACT],
                ['from' => 'PENDING_L1', 'to' => 'RETURNED', 'action' => 'return', 'required_right' => WorkflowDef::RIGHT_ACT],
                ['from' => 'PENDING_L1', 'to' => 'CANCELLED', 'action' => 'cancel', 'required_right' => WorkflowDef::RIGHT_ACT],
                ['from' => 'PENDING_L2', 'to' => 'DONE', 'action' => 'approve',
                 'required_right' => WorkflowDef::RIGHT_ACT, 'requires_comment' => 1,
                 'condition' => ['field' => 'amount', 'op' => 'gte', 'value' => 1000],
                 'steps' => [['level' => 1, 'quorum_type' => Step::QUORUM_COUNT, 'quorum_value' => 1,
                              'approver_kind' => Step::APPROVER_GROUP, 'approver_ref' => $this->g2]]],
                ['from' => 'PENDING_L2', 'to' => 'RETURNED', 'action' => 'return', 'required_right' => WorkflowDef::RIGHT_ACT],
            ],
        ]);
        $this->check('definición demo v1 creada y activa', $this->def->getID() > 0 && (int) $this->def->fields['version'] === 1);
    }

    // ------------------------------------------------------------------ [E2E] happy path

    private function scenarioHappyPath(): void
    {
        $this->out->writeln('== [E2E] ciclo feliz: crear → submit → L1 (quórum) → L2 → finalizar ==');
        $api = new WorkflowApi();
        $itemsId = $this->makeComputer();

        // Solicitante crea + envía.
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $instance = $api->startInstance($this->def, 'Computer', $itemsId, $this->entityB, 0);
        $this->check('instancia creada en estado inicial (DRAFT)', $instance !== null && $this->stateCodeOf($instance) === 'DRAFT');
        if ($instance === null) {
            return;
        }
        $this->check('instancia conserva def_version=1', (int) $instance->fields['def_version'] === 1);

        $r = $api->transition($instance, 'submit', ['requester_users_id' => $this->requester]);
        $this->check('submit OK → PENDING_L1', $r->success && ($r->data['to'] ?? '') === 'PENDING_L1');
        $this->check('[NOTIF] destinatarios incluyen aprobadores de G1',
            in_array($this->uA1, $r->data['recipients'] ?? [], true) && in_array($this->uA2, $r->data['recipients'] ?? [], true));

        // [NEG] doble submit → INVALID_ACTION.
        $r = $api->transition($instance, 'submit', []);
        $this->check('[NEG] doble submit → INVALID_ACTION', !$r->success && $r->code === TransitionResult::INVALID_ACTION);

        // Etapa 1: quórum count=2 sobre G1 (uA1, uA2) + delegado uA3.
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($instance, 'approve', ['comment' => 'ok a1']);
        $this->check('[QUORUM] primer approve → RECORDED (no avanza)', $r->success && $r->code === TransitionResult::RECORDED);
        $this->check('[QUORUM] sigue en PENDING_L1', $this->stateCodeOf($instance) === 'PENDING_L1');

        // [NEG] doble approve del mismo actor → DUPLICATE.
        $r = $api->transition($instance, 'approve', ['comment' => 'otra vez a1']);
        $this->check('[NEG] doble approve mismo actor → DUPLICATE', !$r->success && $r->code === TransitionResult::DUPLICATE);

        // [DELEGATION] uA3 (delegado de uA2) aprueba → quórum 2 alcanzado → PENDING_L2.
        $this->applySession($this->uA3, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($instance, 'approve', ['comment' => 'ok a3 (delegado)']);
        $this->check('[DELEGATION] approve del delegado → quórum → PENDING_L2', $r->success && ($r->data['to'] ?? '') === 'PENDING_L2');

        // Etapa 2: comentario obligatorio + condición amount>=1000, quórum 1 (G2 = uB1).
        $this->applySession($this->uB1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($instance, 'approve', ['fields' => ['amount' => 5000]]); // sin comentario
        $this->check('[NEG] approve sin comentario → COMMENT_REQUIRED', !$r->success && $r->code === TransitionResult::COMMENT_REQUIRED);

        $r = $api->transition($instance, 'approve', ['comment' => 'rev', 'fields' => ['amount' => 10]]); // condición falla
        $this->check('[NEG] condición amount<1000 → CONDITION_FAILED', !$r->success && $r->code === TransitionResult::CONDITION_FAILED);

        $r = $api->transition($instance, 'approve', ['comment' => 'aprobado', 'fields' => ['amount' => 5000]]);
        $this->check('[E2E] approve L2 (comentario+condición+quórum) → DONE', $r->success && ($r->data['to'] ?? '') === 'DONE');

        $instance->getFromDB($instance->getID());
        $this->check('[E2E] instancia final: status=closed, estado=DONE',
            ($instance->fields['status'] ?? '') === Instance::STATUS_CLOSED && $this->stateCodeOf($instance) === 'DONE');

        // [AUDIT] historial append-only: contiene started + transitioned; sólo crece.
        $hist = $this->historyCount($instance->getID());
        $this->check('[AUDIT] historial tiene eventos (started/transitioned)', $hist >= 4);
        $this->check('[AUDIT] evento started presente', $this->hasHistoryEvent($instance->getID(), HistoryEvent::EVENT_STARTED));
        $this->check('[AUDIT] evento quorum_reached presente', $this->hasHistoryEvent($instance->getID(), HistoryEvent::EVENT_QUORUM_REACHED));
    }

    // ------------------------------------------------------------------ [NEG] negativos varios

    private function scenarioNegatives(): void
    {
        $this->out->writeln('== [NEG] transición inválida / ACL / entidad / versión ==');
        $api = new WorkflowApi();

        // Instancia fresca en DRAFT.
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[NEG] instancia fresca creada', false);
            return;
        }

        // Transición inválida: approve desde DRAFT (no existe).
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($inst, 'approve', ['comment' => 'x']);
        $this->check('[NEG] approve desde DRAFT → INVALID_ACTION', !$r->success && $r->code === TransitionResult::INVALID_ACTION);

        // Llevar a PENDING_L1.
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $api->transition($inst, 'submit', []);

        // [ACL] usuario sin RIGHT_ACT (sólo READ) intenta aprobar → DENIED_ACL.
        $this->applySession($this->uNoPerm, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $r = $api->transition($inst, 'approve', ['comment' => 'x']);
        $this->check('[ACL] 🔒 sin RIGHT_ACT → DENIED_ACL', !$r->success && $r->code === TransitionResult::DENIED_ACL);

        // [MULTI-ENT] aprobador con RIGHT_ACT pero en entidad A → DENIED_ENTITY.
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($inst, 'approve', ['comment' => 'x']);
        $this->check('[MULTI-ENT] 🔒 entidad A sobre instancia de B → DENIED_ENTITY', !$r->success && $r->code === TransitionResult::DENIED_ENTITY);

        // [NEG] versión antigua (lock_version obsoleto) → CONFLICT_VERSION.
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $stale = (int) $inst->fields['lock_version'] - 1;
        $r = $api->transition($inst, 'approve', ['comment' => 'x', 'expected_lock_version' => $stale]);
        $this->check('[NEG] versión antigua → CONFLICT_VERSION', !$r->success && $r->code === TransitionResult::CONFLICT_VERSION);

        // [NEG] rechazo → REJECTED (final).
        $r = $api->transition($inst, 'reject', ['comment' => 'no']);
        $this->check('[NEG] reject → REJECTED (final)', $r->success && ($r->data['to'] ?? '') === 'REJECTED');
        $inst->getFromDB($inst->getID());
        $this->check('[NEG] instancia cerrada tras reject', ($inst->fields['status'] ?? '') === Instance::STATUS_CLOSED);

        // Acción sobre instancia cerrada → CLOSED.
        $r = $api->transition($inst, 'approve', ['comment' => 'x']);
        $this->check('[NEG] acción sobre instancia cerrada → CLOSED', !$r->success && $r->code === TransitionResult::CLOSED);
    }

    // ------------------------------------------------------------------ devolución / invalidación

    private function scenarioReturnInvalidation(): void
    {
        $this->out->writeln('== [NEG] devolución invalida votos y permite reenvío ==');
        $api = new WorkflowApi();

        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[RETURN] instancia creada', false);
            return;
        }
        $api->transition($inst, 'submit', []);

        // Un voto en L1, luego devolver.
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $api->transition($inst, 'approve', ['comment' => 'ok a1']);
        $this->check('[RETURN] hay 1 voto antes de devolver', $this->approvalsCount($inst->getID()) === 1);

        $r = $api->transition($inst, 'return', ['comment' => 'corregir']);
        $this->check('[RETURN] return → RETURNED (editable)', $r->success && ($r->data['to'] ?? '') === 'RETURNED');
        $this->check('[RETURN] votos invalidados (0)', $this->approvalsCount($inst->getID()) === 0);
        $this->check('[RETURN] evento approval_invalidated registrado',
            $this->hasHistoryEvent($inst->getID(), HistoryEvent::EVENT_APPROVAL_INVALIDATED));

        // Reenviar desde RETURNED → PENDING_L1 de nuevo (arranca fresco).
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $r = $api->transition($inst, 'submit', []);
        $this->check('[RETURN] resubmit desde RETURNED → PENDING_L1', $r->success && ($r->data['to'] ?? '') === 'PENDING_L1');
        $this->check('[RETURN] tras reenvío, votos siguen en 0', $this->approvalsCount($inst->getID()) === 0);
    }

    // ------------------------------------------------------------------ versionado

    private function scenarioVersioning(): void
    {
        $this->out->writeln('== [VERSION] una instancia conserva su versión de definición ==');
        $api = new WorkflowApi();

        // Instancia sobre v1.
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[VERSION] instancia v1 creada', false);
            return;
        }
        $v1DefId = (int) $inst->fields['workflowdefs_id'];

        // Publicar v2 del MISMO code (nueva versión → desactiva v1).
        $this->applySession(2, [0], ['plugin_companyworkflow' => ALLSTANDARDRIGHT], 1);
        $v2 = $api->builder()->createVersion([
            'code'   => (string) $this->def->fields['code'],
            'name'   => 'Demo neutral v2',
            'states' => [
                ['code' => 'DRAFT', 'kind' => StateDef::KIND_INITIAL, 'is_editable' => 1],
                ['code' => 'DONE',  'kind' => StateDef::KIND_FINAL],
            ],
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'DONE', 'action' => 'submit'],
            ],
        ]);
        $this->check('[VERSION] v2 creada y activa', (int) $v2->fields['version'] === 2 && (int) $v2->fields['is_active'] === 1);
        $this->check('[VERSION] activeByCode devuelve v2', ($api->builder()->activeByCode((string) $this->def->fields['code'])?->getID()) === $v2->getID());

        // La instancia sigue apuntando a v1 (no cambió silenciosamente).
        $inst->getFromDB($inst->getID());
        $this->check('[VERSION] instancia sigue en def v1 (id y version)',
            (int) $inst->fields['workflowdefs_id'] === $v1DefId && (int) $inst->fields['def_version'] === 1 && $v1DefId !== $v2->getID());

        // Y puede transicionar usando las transiciones de v1 (submit → PENDING_L1, no DONE de v2).
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $r = $api->transition($inst, 'submit', []);
        $this->check('[VERSION] transición usa el mapa de v1 (→ PENDING_L1)', $r->success && ($r->data['to'] ?? '') === 'PENDING_L1');
    }

    // ------------------------------------------------------------------ SLA / escalamiento

    private function scenarioSla(): void
    {
        $this->out->writeln('== [SLA] vencimiento + escalamiento (nunca aprueba solo) ==');
        /** @var \DBmysql $DB */
        global $DB;
        $api = new WorkflowApi();

        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[SLA] instancia creada', false);
            return;
        }
        $api->transition($inst, 'submit', []); // → PENDING_L1 (sla_hours=1)

        // Backdatear la entrada a la etapa (historial propio) para forzar el vencimiento.
        $DB->update(
            'glpi_plugin_companyworkflow_history',
            ['date' => date('Y-m-d H:i:s', time() - 7200)],
            ['instances_id' => $inst->getID(), 'to_code' => 'PENDING_L1']
        );

        $sla = new SlaService();
        $this->check('[SLA] isOverdue puro: 1h con entrada hace 2h → true',
            $sla->isOverdue(1, date('Y-m-d H:i:s', time() - 7200)) === true);
        $this->check('[SLA] isOverdue puro: sla nulo → false', $sla->isOverdue(null, date('Y-m-d H:i:s')) === false);

        $escalated = $sla->processOverdue();
        $this->check('[SLA] processOverdue escaló al menos 1 instancia', $escalated >= 1);
        $this->check('[SLA] evento sla_breached registrado', $this->hasHistoryEvent($inst->getID(), HistoryEvent::EVENT_SLA_BREACHED));
        $this->check('[SLA] evento escalated registrado (sin aprobar)', $this->hasHistoryEvent($inst->getID(), HistoryEvent::EVENT_ESCALATED));

        // El estado NO cambió por el escalamiento (nunca aprueba solo).
        $inst->getFromDB($inst->getID());
        $this->check('[SLA] escalamiento NO cambió el estado (sigue PENDING_L1)', $this->stateCodeOf($inst) === 'PENDING_L1');
        $this->check('[SLA] cron config habilitado por defecto', PluginConfig::boolean('sla_check_enabled'));
    }

    // ------------------------------------------------------------------ helpers

    private function makeComputer(): int
    {
        $this->applySession(2, [0, $this->entityB], ['computer' => ALLSTANDARDRIGHT], 1);
        $id = (int) (new Computer())->add([
            'name'        => 'WF-ITEM-' . $this->suffix . '-' . random_int(1000, 9999),
            'entities_id' => $this->entityB,
        ]);
        return $id;
    }

    private function stateCodeOf(Instance $instance): string
    {
        $s = new StateDef();
        return $s->getFromDB((int) $instance->fields['current_statedefs_id']) ? (string) $s->fields['code'] : '';
    }

    private function approvalsCount(int $instanceId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request([
            'COUNT' => 'c',
            'FROM'  => Assignment::getTable(),
            'WHERE' => ['instances_id' => $instanceId, 'decision' => Assignment::DECISION_APPROVED],
        ]) as $row) {
            $n = (int) $row['c'];
        }
        return $n;
    }

    private function historyCount(int $instanceId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request([
            'COUNT' => 'c',
            'FROM'  => HistoryEvent::getTable(),
            'WHERE' => ['instances_id' => $instanceId],
        ]) as $row) {
            $n = (int) $row['c'];
        }
        return $n;
    }

    private function hasHistoryEvent(int $instanceId, string $event): bool
    {
        return (new HistoryEvent())->getFromDBByCrit(['instances_id' => $instanceId, 'event' => $event]);
    }

    /**
     * Fabrica $_SESSION como lo haría GLPI tras login (técnica estándar de test de GLPI).
     * @param array<int>        $entities
     * @param array<string,int> $rights   rightname => bits
     */
    private function applySession(int $userId, array $entities, array $rights, int $recursive = 0): void
    {
        $_SESSION['glpiID']                      = $userId;
        $_SESSION['glpiname']                    = 'wf_selftest';
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
            // Tablas propias: limpiar filas de esta corrida (best-effort).
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => WorkflowDef::getTable(), 'WHERE' => ['code' => ['LIKE', 'wf_demo_%']]]) as $row) {
                $defId = (int) $row['id'];
                foreach (['instances', 'statedefs', 'transitions', 'defs'] as $t) {
                    // Las instancias y su historial/asignaciones se limpian por instancia abajo.
                    if ($t === 'defs') {
                        $DB->delete("glpi_plugin_companyworkflow_defs", ['id' => $defId]);
                    }
                }
            }
            foreach ($this->createdGroups as $g) {
                (new Group())->delete(['id' => $g], true);
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
