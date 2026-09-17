<?php

/**
 * Autotest de INTEGRACIÓN + E2E de companyworkflow (corre DENTRO de un GLPI arrancado, en CI).
 *
 * Nombre: `plugins:companyworkflow:selftest`. Fail-closed → exit 1 si algo falla.
 *
 * Cobertura (workflow NEUTRAL de demostración; sin dominio de Compras):
 *   [PERSIST]     tablas propias creadas por la migración.
 *   [ROUTES]      POST /instance/{id}/{action} AUTHENTICATED (CSRF nativo).
 *   [E2E]         crear → submit → aprobar etapa 1 (quórum) → aprobar etapa 2 → finalizar.
 *   [QUORUM]      quórum incompleto NO avanza.
 *   [DELEGATION]  un delegado vigente cuenta como aprobador válido.
 *   [MULTI-ENT]   🔒 aprobador de entidad A no actúa sobre instancia de entidad B; y aprobadores
 *                 de otra entidad NO cuentan para el denominador del quórum.
 *   [ACL]         🔒 usuario sin RIGHT_ACT no puede aprobar.
 *   [NEG]         inválida · doble submit · doble approve/replay · versión antigua · comentario ·
 *                 condición · rechazo · devolución.
 *   [RECOVERY]    fallo durante el avance tras el último voto → instancia recuperable (no bloqueada)
 *                 y ballot+estado atómicos (rollback conjunto).
 *   [DEFBUILD]    publicación fail-closed/transaccional: fallo creando estado → versión anterior
 *                 sigue activa; transición a estado inexistente → rechazo sin escrituras; colisión
 *                 de versión → versiones consistentes.
 *   [STARTVAL]    startInstance valida def activa/itemtype/objeto/entidad (fail-closed).
 *   [VERSION]     una instancia conserva la versión de definición con que fue iniciada.
 *   [AUDIT]       historial append-only.
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
use GlpiPlugin\Companyworkflow\Service\ApproverResolver;
use GlpiPlugin\Companyworkflow\Service\DefinitionBuilder;
use GlpiPlugin\Companyworkflow\Service\Engine;
use GlpiPlugin\Companyworkflow\Service\PluginConfig;
use GlpiPlugin\Companyworkflow\Service\SlaService;
use GlpiPlugin\Companyworkflow\Service\TransitionResult;
use Profile_User;
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

    /** @var array<int,int> */
    private array $createdUsers = [];
    private array $createdGroups = [];
    private array $createdEntities = [];

    private int $entityA = 0;
    private int $entityB = 0;
    private int $g1 = 0;
    private int $g2 = 0;
    private int $uA1 = 0;
    private int $uA2 = 0;
    private int $uA3 = 0;
    private int $uB1 = 0;
    private int $uNoPerm = 0;
    private int $requester = 0;
    private WorkflowDef $def;

    protected function configure(): void
    {
        $this->setName('plugins:companyworkflow:selftest')
            ->setDescription('Pruebas de integración + E2E del motor de workflow (concurrencia, versión, multi-entidad, SLA).');
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
        $this->scenarioInvalidateApprovals();
        $this->scenarioMultiEntityApprovers();
        $this->scenarioRecovery();
        $this->scenarioDefinitionBuilderHardening();
        $this->scenarioStartInstanceValidation();
        $this->scenarioSla();
        $this->scenarioVersioning();

        $this->cleanup();

        if ($this->failures > 0) {
            $output->writeln(sprintf('<error>SELFTEST: %d comprobación(es) fallida(s).</error>', $this->failures));
            return Command::FAILURE;
        }
        $output->writeln('<info>SELFTEST: todas las comprobaciones pasaron.</info>');
        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------ [PERSIST] / [ROUTES]

    private function checkPersistence(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [PERSIST] tablas propias ==');
        foreach (['defs', 'statedefs', 'transitions', 'steps', 'instances', 'assignments', 'delegations', 'history'] as $t) {
            $this->check("tabla glpi_plugin_companyworkflow_{$t} existe", $DB->tableExists("glpi_plugin_companyworkflow_{$t}"));
        }
    }

    private function checkRoutes(): void
    {
        $this->out->writeln('== [ROUTES] superficie HTTP ==');
        try {
            $m = (new \ReflectionClass(WorkflowActionController::class))->getMethod('act');
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
            $this->check('ruta es AUTHENTICATED', $strategy === Firewall::STRATEGY_AUTHENTICATED);
        } catch (\Throwable $e) {
            $this->check('reflexión de WorkflowActionController: ' . $e->getMessage(), false);
        }
    }

    // ------------------------------------------------------------------ fixtures

    private function buildFixtures(): void
    {
        $this->out->writeln('== fixtures (entidades/grupos/usuarios + Profile_User reales) ==');
        $this->applySession(2, [0], [
            'entity' => ALLSTANDARDRIGHT, 'user' => ALLSTANDARDRIGHT,
            'group' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT, 'profile' => ALLSTANDARDRIGHT,
        ], 1);

        $this->entityA = (int) (new Entity())->add(['name' => 'WF-A-' . $this->suffix, 'entities_id' => 0]);
        $this->entityB = (int) (new Entity())->add(['name' => 'WF-B-' . $this->suffix, 'entities_id' => 0]);
        $this->createdEntities = array_filter([$this->entityA, $this->entityB]);
        $this->check('entidades A y B creadas', $this->entityA > 0 && $this->entityB > 0);

        // Aprobadores y solicitante viven en entidad B (Profile_User).
        $this->uA1 = $this->makeUserInEntity('a1', $this->entityB);
        $this->uA2 = $this->makeUserInEntity('a2', $this->entityB);
        $this->uA3 = $this->makeUserInEntity('a3', $this->entityB);
        $this->uB1 = $this->makeUserInEntity('b1', $this->entityB);
        $this->requester = $this->makeUserInEntity('req', $this->entityB);
        $this->uNoPerm = $this->makeUserInEntity('noperm', $this->entityB);
        $this->check('usuarios de prueba creados con Profile_User', min($this->uA1, $this->uA2, $this->uA3, $this->uB1, $this->requester, $this->uNoPerm) > 0);

        $this->g1 = (int) (new Group())->add(['name' => 'WF-G1-' . $this->suffix, 'entities_id' => $this->entityB]);
        $this->g2 = (int) (new Group())->add(['name' => 'WF-G2-' . $this->suffix, 'entities_id' => $this->entityB]);
        $this->createdGroups = array_filter([$this->g1, $this->g2]);
        foreach ([$this->uA1, $this->uA2] as $u) {
            (new Group_User())->add(['groups_id' => $this->g1, 'users_id' => $u]);
        }
        (new Group_User())->add(['groups_id' => $this->g2, 'users_id' => $this->uB1]);

        (new Delegation())->add([
            'users_id_from' => $this->uA2, 'users_id_to' => $this->uA3, 'workflowdefs_id' => 0, 'entities_id' => 0,
            'date_start' => date('Y-m-d H:i:s', time() - 3600), 'date_end' => date('Y-m-d H:i:s', time() + 86400),
            'reason' => 'selftest', 'is_active' => 1,
        ]);
        $this->check('grupos + membresías + delegación uA2→uA3 creadas', $this->g1 > 0 && $this->g2 > 0);
    }

    private function makeUserInEntity(string $tag, int $entityId, int $recursive = 0): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $id = (int) (new User())->add(['name' => 'wf_' . $tag . '_' . $this->suffix, 'realname' => 'WF ' . $tag, '_no_history' => true]);
        if ($id > 0) {
            $this->createdUsers[] = $id;
            // Determinismo: eliminar cualquier Profile_User autogenerado y dejar SÓLO la asignación
            // intencional a la entidad (profile 1 existe por defecto). Así el filtro de entidad es
            // exacto para el test multi-entidad.
            $pu = new Profile_User();
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $id]]) as $row) {
                $pu->delete(['id' => (int) $row['id']], true);
            }
            (new Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => $entityId, 'is_recursive' => $recursive]);
        }
        return $id;
    }

    private function buildDemoWorkflow(): void
    {
        $this->out->writeln('== definición NEUTRAL de demostración ==');
        $this->def = (new WorkflowApi())->builder()->createVersion($this->demoSpec('wf_demo_' . $this->suffix));
        $this->check('definición demo v1 creada y activa', $this->def->getID() > 0 && (int) $this->def->fields['version'] === 1);
    }

    /** @return array<string,mixed> */
    private function demoSpec(string $code): array
    {
        return [
            'code' => $code, 'name' => 'Demo neutral', 'itemtype_target' => 'Computer',
            'entities_id' => 0, 'is_recursive' => 1,
            'states' => [
                ['code' => 'DRAFT', 'kind' => StateDef::KIND_INITIAL, 'is_editable' => 1],
                ['code' => 'PENDING_L1', 'kind' => StateDef::KIND_INTERMEDIATE, 'sla_hours' => 1],
                ['code' => 'PENDING_L2', 'kind' => StateDef::KIND_INTERMEDIATE],
                ['code' => 'RETURNED', 'kind' => StateDef::KIND_INTERMEDIATE, 'is_editable' => 1],
                ['code' => 'DONE', 'kind' => StateDef::KIND_FINAL],
                ['code' => 'REJECTED', 'kind' => StateDef::KIND_FINAL],
                ['code' => 'CANCELLED', 'kind' => StateDef::KIND_FINAL],
            ],
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PENDING_L1', 'action' => 'submit'],
                ['from' => 'RETURNED', 'to' => 'PENDING_L1', 'action' => 'submit'],
                ['from' => 'PENDING_L1', 'to' => 'PENDING_L2', 'action' => 'approve',
                 'required_right' => WorkflowDef::RIGHT_ACT,
                 'steps' => [['level' => 1, 'quorum_type' => Step::QUORUM_COUNT, 'quorum_value' => 2, 'approver_kind' => Step::APPROVER_GROUP, 'approver_ref' => $this->g1]]],
                ['from' => 'PENDING_L1', 'to' => 'REJECTED', 'action' => 'reject', 'required_right' => WorkflowDef::RIGHT_ACT],
                ['from' => 'PENDING_L1', 'to' => 'RETURNED', 'action' => 'return', 'required_right' => WorkflowDef::RIGHT_ACT],
                ['from' => 'PENDING_L1', 'to' => 'CANCELLED', 'action' => 'cancel', 'required_right' => WorkflowDef::RIGHT_ACT],
                ['from' => 'PENDING_L2', 'to' => 'DONE', 'action' => 'approve',
                 'required_right' => WorkflowDef::RIGHT_ACT, 'requires_comment' => 1,
                 'condition' => ['field' => 'amount', 'op' => 'gte', 'value' => 1000],
                 'steps' => [['level' => 1, 'quorum_type' => Step::QUORUM_COUNT, 'quorum_value' => 1, 'approver_kind' => Step::APPROVER_GROUP, 'approver_ref' => $this->g2]]],
                ['from' => 'PENDING_L2', 'to' => 'RETURNED', 'action' => 'return', 'required_right' => WorkflowDef::RIGHT_ACT],
            ],
        ];
    }

    // ------------------------------------------------------------------ [E2E] happy path

    private function scenarioHappyPath(): void
    {
        $this->out->writeln('== [E2E] ciclo feliz: crear → submit → L1 (quórum+delegación) → L2 → DONE ==');
        $api = new WorkflowApi();
        $itemsId = $this->makeComputer();

        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $instance = $api->startInstance($this->def, 'Computer', $itemsId, $this->entityB, 0);
        $this->check('instancia creada en DRAFT', $instance !== null && $this->stateCodeOf($instance) === 'DRAFT');
        if ($instance === null) {
            return;
        }

        $r = $api->transition($instance, 'submit', ['requester_users_id' => $this->requester]);
        $this->check('submit → PENDING_L1', $r->success && ($r->data['to'] ?? '') === 'PENDING_L1');
        $this->check('[NOTIF] destinatarios incluyen G1', in_array($this->uA1, $r->data['recipients'] ?? [], true) && in_array($this->uA2, $r->data['recipients'] ?? [], true));

        $r = $api->transition($instance, 'submit', []);
        $this->check('[NEG] doble submit → INVALID_ACTION', !$r->success && $r->code === TransitionResult::INVALID_ACTION);

        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($instance, 'approve', ['comment' => 'ok a1']);
        $this->check('[QUORUM] primer approve → RECORDED', $r->success && $r->code === TransitionResult::RECORDED);
        $this->check('[QUORUM] sigue en PENDING_L1', $this->stateCodeOf($instance) === 'PENDING_L1');

        $r = $api->transition($instance, 'approve', ['comment' => 'otra vez a1']);
        $this->check('[NEG] doble approve mismo actor sin quórum → DUPLICATE', !$r->success && $r->code === TransitionResult::DUPLICATE);

        $this->applySession($this->uA3, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($instance, 'approve', ['comment' => 'ok a3 (delegado)']);
        $this->check('[DELEGATION] delegado aprueba → quórum → PENDING_L2', $r->success && ($r->data['to'] ?? '') === 'PENDING_L2');

        $this->applySession($this->uB1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($instance, 'approve', ['fields' => ['amount' => 5000]]);
        $this->check('[NEG] approve sin comentario → COMMENT_REQUIRED', !$r->success && $r->code === TransitionResult::COMMENT_REQUIRED);
        $r = $api->transition($instance, 'approve', ['comment' => 'rev', 'fields' => ['amount' => 10]]);
        $this->check('[NEG] condición no cumplida → CONDITION_FAILED', !$r->success && $r->code === TransitionResult::CONDITION_FAILED);
        $r = $api->transition($instance, 'approve', ['comment' => 'aprobado', 'fields' => ['amount' => 5000]]);
        $this->check('[E2E] approve L2 → DONE', $r->success && ($r->data['to'] ?? '') === 'DONE');

        $instance->getFromDB($instance->getID());
        $this->check('[E2E] final: closed + DONE', ($instance->fields['status'] ?? '') === Instance::STATUS_CLOSED && $this->stateCodeOf($instance) === 'DONE');
        $this->check('[AUDIT] historial con started/quorum_reached', $this->hasHistoryEvent($instance->getID(), HistoryEvent::EVENT_STARTED) && $this->hasHistoryEvent($instance->getID(), HistoryEvent::EVENT_QUORUM_REACHED));
    }

    // ------------------------------------------------------------------ [NEG]

    private function scenarioNegatives(): void
    {
        $this->out->writeln('== [NEG] inválida / ACL / entidad / versión / rechazo ==');
        $api = new WorkflowApi();
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[NEG] instancia fresca', false);
            return;
        }

        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($inst, 'approve', ['comment' => 'x']);
        $this->check('[NEG] approve desde DRAFT → INVALID_ACTION', !$r->success && $r->code === TransitionResult::INVALID_ACTION);

        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $api->transition($inst, 'submit', []);

        $this->applySession($this->uNoPerm, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $r = $api->transition($inst, 'approve', ['comment' => 'x']);
        $this->check('[ACL] 🔒 sin RIGHT_ACT → DENIED_ACL', !$r->success && $r->code === TransitionResult::DENIED_ACL);

        $this->applySession($this->uA1, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($inst, 'approve', ['comment' => 'x']);
        $this->check('[MULTI-ENT] 🔒 entidad A sobre instancia B → DENIED_ENTITY', !$r->success && $r->code === TransitionResult::DENIED_ENTITY);

        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $stale = (int) $inst->fields['lock_version'] - 1;
        $r = $api->transition($inst, 'approve', ['comment' => 'x', 'expected_lock_version' => $stale]);
        $this->check('[NEG] versión antigua → CONFLICT_VERSION', !$r->success && $r->code === TransitionResult::CONFLICT_VERSION);

        $r = $api->transition($inst, 'reject', ['comment' => 'no']);
        $this->check('[NEG] reject → REJECTED', $r->success && ($r->data['to'] ?? '') === 'REJECTED');
        $inst->getFromDB($inst->getID());
        $this->check('[NEG] cerrada tras reject', ($inst->fields['status'] ?? '') === Instance::STATUS_CLOSED);
        $r = $api->transition($inst, 'approve', ['comment' => 'x']);
        $this->check('[NEG] acción sobre cerrada → CLOSED', !$r->success && $r->code === TransitionResult::CLOSED);
    }

    // ------------------------------------------------------------------ devolución

    private function scenarioReturnInvalidation(): void
    {
        $this->out->writeln('== [NEG] devolución invalida votos y permite reenvío ==');
        $api = new WorkflowApi();
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[RETURN] instancia', false);
            return;
        }
        $rSubmit = $api->transition($inst, 'submit', []);
        $this->diagTransition('RETURN-submit', 'submit', (int) $inst->getID(), $this->requester, 'DRAFT', 0, $rSubmit);
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $approvalsBefore = $this->approvalsCount((int) $inst->getID());
        $rApprove = $api->transition($inst, 'approve', ['comment' => 'ok a1']);
        $this->diagTransition('RETURN', 'approve', (int) $inst->getID(), $this->uA1, 'PENDING_L1', $approvalsBefore, $rApprove);
        $this->check('[RETURN] 1 voto antes de devolver', $this->approvalsCount($inst->getID()) === 1);
        $r = $api->transition($inst, 'return', ['comment' => 'corregir']);
        $this->check('[RETURN] return → RETURNED', $r->success && ($r->data['to'] ?? '') === 'RETURNED');
        $this->check('[RETURN] votos invalidados (0)', $this->approvalsCount($inst->getID()) === 0);
        $this->check('[RETURN] evento approval_invalidated', $this->hasHistoryEvent($inst->getID(), HistoryEvent::EVENT_APPROVAL_INVALIDATED));
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $r = $api->transition($inst, 'submit', []);
        $this->check('[RETURN] resubmit → PENDING_L1', $r->success && ($r->data['to'] ?? '') === 'PENDING_L1');
    }

    // ------------------------------------------------------------------ [INVALIDATE] extensión D2

    /**
     * Invalidación GENÉRICA de aprobaciones (extensión reutilizable por plugins de dominio, p. ej.
     * companysignature). Domain-agnostic · fail-closed · idempotente · concurrencia · reabre checkpoint.
     */
    private function scenarioInvalidateApprovals(): void
    {
        $this->out->writeln('== [INVALIDATE] invalidación genérica de aprobaciones (extensión D2) ==');
        $api = new WorkflowApi();

        // (a) Feliz: PENDING_L1 con 1 voto → invalidar → reabre al estado inicial (DRAFT), 0 votos, OPEN.
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[INVALIDATE] instancia', false);
            return;
        }
        $api->transition($inst, 'submit', []);
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $approvalsBefore = $this->approvalsCount((int) $inst->getID());
        $rApprove = $api->transition($inst, 'approve', ['comment' => 'a1']);
        $this->diagTransition('INVALIDATE', 'approve', (int) $inst->getID(), $this->uA1, 'PENDING_L1', $approvalsBefore, $rApprove);
        $this->check('[INVALIDATE] preludio: 1 voto en PENDING_L1', $this->approvalsCount((int) $inst->getID()) === 1 && $this->stateCodeOf($inst) === 'PENDING_L1');
        // El voto individual dejó una decisión DURABLE en el ledger (evidencia por aprobador).
        $this->check('[INVALIDATE] decision_recorded en historial tras el voto', $this->hasHistoryEvent((int) $inst->getID(), HistoryEvent::EVENT_DECISION_RECORDED));

        // La invalidación es una acción de decisión → exige RIGHT_ACT (no basta READ).
        $idem = 'sig:demo:' . $this->suffix . ':v1';
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->invalidateApprovals((int) $inst->getID(), 'contenido aprobado cambió', [
            'idempotency_key' => $idem,
            'subject_type'    => 'Computer',
            'subject_id'      => (int) $inst->fields['items_id'],
            'document_version' => 2,
        ]);
        $this->check('[INVALIDATE] OK + advanced', $r->success && ($r->data['advanced'] ?? false) === true);
        $inst->getFromDB((int) $inst->getID());
        $this->check('[INVALIDATE] reabre al estado inicial (DRAFT)', $this->stateCodeOf($inst) === 'DRAFT');
        $this->check('[INVALIDATE] votos limpiados (0)', $this->approvalsCount((int) $inst->getID()) === 0);
        $this->check('[INVALIDATE] instancia sigue OPEN', ($inst->fields['status'] ?? '') === Instance::STATUS_OPEN);
        $this->check('[INVALIDATE] evento approval_invalidated en historial', $this->hasHistoryEvent((int) $inst->getID(), HistoryEvent::EVENT_APPROVAL_INVALIDATED));

        // (b) IDEMPOTENCIA: misma idempotency_key → no-op OK, sin nuevo avance ni cambio de versión.
        $lockBefore = (int) $inst->fields['lock_version'];
        $r2 = $api->invalidateApprovals((int) $inst->getID(), 'contenido aprobado cambió', ['idempotency_key' => $idem]);
        $inst->getFromDB((int) $inst->getID());
        $this->check('[INVALIDATE] idempotente: OK sin avanzar', $r2->success && ($r2->data['idempotent'] ?? false) === true && ($r2->data['advanced'] ?? true) === false);
        $this->check('[INVALIDATE] idempotente: lock_version intacto', (int) $inst->fields['lock_version'] === $lockBefore);

        // (c) Checkpoint CONFIGURABLE: reopen_to_code respetado (reabre a RETURNED, no al inicial).
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst2 = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        $api->transition($inst2, 'submit', []);
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $api->transition($inst2, 'approve', ['comment' => 'a1']);
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r3 = $api->invalidateApprovals((int) $inst2->getID(), 'reabrir a RETURNED', ['reopen_to_code' => 'RETURNED', 'idempotency_key' => 'k2-' . $this->suffix]);
        $inst2->getFromDB((int) $inst2->getID());
        $this->check('[INVALIDATE] reopen_to_code=RETURNED respetado', $r3->success && $this->stateCodeOf($inst2) === 'RETURNED');

        // (d) CONCURRENCIA: expectedVersion incorrecto → CONFLICT_VERSION (sin mutar).
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst3 = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        $api->transition($inst3, 'submit', []);
        $inst3->getFromDB((int) $inst3->getID());
        $wrong = (int) $inst3->fields['lock_version'] + 5;
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r4 = $api->invalidateApprovals((int) $inst3->getID(), 'stale', ['idempotency_key' => 'k3-' . $this->suffix], $wrong);
        $this->check('[INVALIDATE] expectedVersion incorrecto → CONFLICT_VERSION', !$r4->success && $r4->code === TransitionResult::CONFLICT_VERSION);

        // (e) FAIL-CLOSED sobre instancia cerrada → CLOSED.
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst4 = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        $api->transition($inst4, 'submit', []);
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $api->transition($inst4, 'reject', ['comment' => 'no']);
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r5 = $api->invalidateApprovals((int) $inst4->getID(), 'sobre cerrada', ['idempotency_key' => 'k4-' . $this->suffix]);
        $this->check('[INVALIDATE] instancia cerrada → CLOSED', !$r5->success && $r5->code === TransitionResult::CLOSED);

        // (f) Instancia inexistente → ERROR (fail-closed).
        $r6 = $api->invalidateApprovals(999999999, 'fantasma', []);
        $this->check('[INVALIDATE] instancia inexistente → ERROR', !$r6->success && $r6->code === TransitionResult::ERROR);

        // (g) ACL: sólo READ → DENIED_ACL; RIGHT_ACT + entidad → permitido.
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst6 = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        $api->transition($inst6, 'submit', []);
        $rAcl = $api->invalidateApprovals((int) $inst6->getID(), 'acl', ['idempotency_key' => 'kacl-' . $this->suffix]);
        $this->check('[INVALIDATE] 🔒 sólo READ → DENIED_ACL', !$rAcl->success && $rAcl->code === TransitionResult::DENIED_ACL);
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $rOk = $api->invalidateApprovals((int) $inst6->getID(), 'acl ok', ['idempotency_key' => 'kacl2-' . $this->suffix]);
        $this->check('[INVALIDATE] RIGHT_ACT + entidad → permitido', $rOk->success);
    }

    // ------------------------------------------------------------------ [MULTI-ENT] denominador de quórum

    private function scenarioMultiEntityApprovers(): void
    {
        $this->out->writeln('== [MULTI-ENT] aprobadores de otra entidad NO cuentan al quórum ==');
        $this->applySession(2, [0], [
            'user' => ALLSTANDARDRIGHT, 'group' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT, 'profile' => ALLSTANDARDRIGHT,
        ], 1);
        // Grupo con un usuario en A y otro en B; workflow/instancia en entidad A; quórum 100%.
        $gAB = (int) (new Group())->add(['name' => 'WF-GAB-' . $this->suffix, 'entities_id' => 0, 'is_recursive' => 1]);
        $this->createdGroups[] = $gAB;
        $userInA = $this->makeUserInEntity('inA', $this->entityA);
        $userInB = $this->makeUserInEntity('inB', $this->entityB);
        (new Group_User())->add(['groups_id' => $gAB, 'users_id' => $userInA]);
        (new Group_User())->add(['groups_id' => $gAB, 'users_id' => $userInB]);

        $api = new WorkflowApi();
        $this->applySession(2, [0], ['plugin_companyworkflow' => ALLSTANDARDRIGHT], 1);
        $def = $api->builder()->createVersion([
            'code' => 'wf_me_' . $this->suffix, 'name' => 'multi-ent', 'itemtype_target' => 'Computer',
            'states' => [
                ['code' => 'DRAFT', 'kind' => StateDef::KIND_INITIAL, 'is_editable' => 1],
                ['code' => 'APPROVAL', 'kind' => StateDef::KIND_INTERMEDIATE],
                ['code' => 'DONE', 'kind' => StateDef::KIND_FINAL],
            ],
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'APPROVAL', 'action' => 'submit'],
                ['from' => 'APPROVAL', 'to' => 'DONE', 'action' => 'approve', 'required_right' => WorkflowDef::RIGHT_ACT,
                 'steps' => [['level' => 1, 'quorum_type' => Step::QUORUM_PERCENT, 'quorum_value' => 100, 'approver_kind' => Step::APPROVER_GROUP, 'approver_ref' => $gAB]]],
            ],
        ]);

        // Assertion directa: el resolver deja SOLO al usuario de la entidad A.
        $step = ['approver_kind' => Step::APPROVER_GROUP, 'approver_ref' => $gAB];
        $effective = (new ApproverResolver())->resolveForStep($step, (int) $def->getID(), $this->entityA);
        $this->check('[MULTI-ENT] efectivos = sólo usuario de A', $effective === [$userInA]);
        $this->check('[MULTI-ENT] usuario de B EXCLUIDO del denominador', !in_array($userInB, $effective, true));

        // E2E: instancia en A; userInA aprueba (100% de 1) → avanza. Si B contara, 1/2=50% no avanzaría.
        $comp = $this->makeComputerInEntity($this->entityA);
        $this->applySession($userInA, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($def, 'Computer', $comp, $this->entityA, 0);
        $api->transition($inst, 'submit', []);
        $this->applySession($userInA, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($inst, 'approve', ['comment' => 'ok']);
        $this->check('[MULTI-ENT] quórum 100% con total efectivo=1 → DONE', $r->success && ($r->data['to'] ?? '') === 'DONE');

        // userInB no puede actuar sobre la instancia de A.
        $comp2 = $this->makeComputerInEntity($this->entityA);
        $this->applySession($userInA, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst2 = $api->startInstance($def, 'Computer', $comp2, $this->entityA, 0);
        $api->transition($inst2, 'submit', []);
        // userInB con su entidad real (B): no tiene acceso a la instancia de A → fail-closed.
        $this->applySession($userInB, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $api->transition($inst2, 'approve', ['comment' => 'x']);
        $this->check('[MULTI-ENT] 🔒 usuario de B NO puede aprobar en A', !$r->success && $r->code === TransitionResult::DENIED_ENTITY);
    }

    // ------------------------------------------------------------------ [RECOVERY]

    private function scenarioRecovery(): void
    {
        $this->out->writeln('== [RECOVERY] fallo tras último voto → recuperable, sin bloqueo ==');

        // (a) Fallo durante el avance: ballot + estado son atómicos (rollback conjunto).
        $normal = new WorkflowApi();
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $normal->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[RECOVERY] instancia', false);
            return;
        }
        $normal->transition($inst, 'submit', []);
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $approvalsBefore = $this->approvalsCount((int) $inst->getID());
        $rApprove = $normal->transition($inst, 'approve', ['comment' => 'a1']); // 1 voto, RECORDED
        $this->diagTransition('RECOVERY', 'approve', (int) $inst->getID(), $this->uA1, 'PENDING_L1', $approvalsBefore, $rApprove);

        // Motor que revienta justo antes de aplicar el avance (tras alcanzar quórum).
        $faultyEngine = new class extends Engine {
            protected function afterQuorumBeforeAdvance(): void
            {
                throw new \RuntimeException('crash simulado antes del avance');
            }
        };
        $faultyApi = new WorkflowApi($faultyEngine);
        $this->applySession($this->uA3, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $faultyApi->transition($inst, 'approve', ['comment' => 'a3']);
        $this->check('[RECOVERY] avance con fallo → ERROR', !$r->success && $r->code === TransitionResult::ERROR);
        $inst->getFromDB($inst->getID());
        $this->check('[RECOVERY] ballot NO persistido (sigue 1 voto)', $this->approvalsCount($inst->getID()) === 1);
        $this->check('[RECOVERY] estado NO avanzó (PENDING_L1)', $this->stateCodeOf($inst) === 'PENDING_L1');

        // Reintento con el motor normal → recupera y avanza (no queda bloqueada).
        $r = $normal->transition($inst, 'approve', ['comment' => 'a3 retry']);
        $this->check('[RECOVERY] reintento → PENDING_L2 (recuperada)', $r->success && ($r->data['to'] ?? '') === 'PENDING_L2');

        // (b) Recovery-safe DUPLICATE: voto aprobado ya persistido + quórum + estado sin avanzar
        //     → un reintento AVANZA (no devuelve DUPLICATE ni queda bloqueado).
        $inst2 = $normal->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        $normal->transition($inst2, 'submit', []);
        $this->applySession($this->uA1, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $normal->transition($inst2, 'approve', ['comment' => 'a1']); // 1 voto
        // Simular voto de uA2 confirmado pero sin avance (crash entre commit del voto y avance).
        $stateId = (int) $inst2->fields['current_statedefs_id'];
        (new Assignment())->add([
            'instances_id' => $inst2->getID(), 'statedefs_id' => $stateId, 'steps_id' => 0, 'level' => 1,
            'users_id' => $this->uA2, 'group_ref' => $this->g1, 'decision' => Assignment::DECISION_APPROVED,
            'comment' => 'a2 stuck', 'date' => date('Y-m-d H:i:s'),
        ]);
        $this->check('[RECOVERY] estado atascado: 2 votos, aún PENDING_L1', $this->approvalsCount($inst2->getID()) === 2 && $this->stateCodeOf($inst2) === 'PENDING_L1');
        // uA2 reintenta su acción → detecta voto existente (dup) pero quórum alcanzado → AVANZA.
        $this->applySession($this->uA2, [$this->entityB], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $r = $normal->transition($inst2, 'approve', ['comment' => 'a2 retry']);
        $this->check('[RECOVERY] retry de voto atascado → AVANZA (no DUPLICATE)', $r->success && ($r->data['to'] ?? '') === 'PENDING_L2');
    }

    // ------------------------------------------------------------------ [DEFBUILD]

    private function scenarioDefinitionBuilderHardening(): void
    {
        $this->out->writeln('== [DEFBUILD] publicación fail-closed / transaccional / versionado ==');
        $api = new WorkflowApi();
        $this->applySession(2, [0], ['plugin_companyworkflow' => ALLSTANDARDRIGHT], 1);

        $code = 'wf_hard_' . $this->suffix;
        // v1 válida y activa.
        $v1 = $api->builder()->createVersion($this->miniSpec($code, 'v1'));
        $this->check('[DEFBUILD] v1 activa', (int) $v1->fields['is_active'] === 1);

        // Fallo creando estado (subclase que revienta tras crear hijos) → v1 sigue activa, sin v2.
        $faulty = new class extends DefinitionBuilder {
            protected function afterChildrenCreated(): void
            {
                throw new \RuntimeException('fallo simulado durante la creación');
            }
        };
        $threw = false;
        try {
            $faulty->createVersion($this->miniSpec($code, 'v2-fail'));
        } catch (\Throwable) {
            $threw = true;
        }
        $this->check('[DEFBUILD] fallo creando versión → excepción', $threw);
        $active = $api->builder()->activeByCode($code);
        $this->check('[DEFBUILD] la versión anterior sigue activa (v1)', $active !== null && $active->getID() === $v1->getID());
        $this->check('[DEFBUILD] no quedó v2 a medias', $this->maxVersion($code) === 1);

        // Transición a estado inexistente → rechazo por validación, sin escrituras.
        $before = $this->maxVersion($code);
        $threw = false;
        try {
            $api->builder()->createVersion([
                'code' => $code, 'states' => [['code' => 'A', 'kind' => 'initial']],
                'transitions' => [['from' => 'A', 'to' => 'GHOST', 'action' => 'submit']],
            ]);
        } catch (\InvalidArgumentException) {
            $threw = true;
        }
        $this->check('[DEFBUILD] transición a estado inexistente → InvalidArgument', $threw);
        $this->check('[DEFBUILD] sin escrituras parciales (versión no cambió)', $this->maxVersion($code) === $before);

        // Colisión de versión: un builder que fuerza en el PRIMER intento una versión ya existente
        // (simula dos publicaciones concurrentes que calcularon la misma versión). Debe reintentar
        // con la versión siguiente y dejar exactamente UNA activa (nunca cero).
        $existing = $this->maxVersion($code); // v1 existe en esta versión
        $collider = new class extends DefinitionBuilder {
            public bool $firstCall = true;
            public int $collideWith = 0;
            protected function nextVersion(string $code): int
            {
                if ($this->firstCall) {
                    $this->firstCall = false;
                    return $this->collideWith; // fuerza colisión UNIQUE(code,version)
                }
                return parent::nextVersion($code);
            }
        };
        $collider->collideWith = $existing;
        $vRetry = $collider->createVersion($this->miniSpec($code, 'vRetry'));
        $this->check('[DEFBUILD] colisión en 1er intento → reintenta con versión mayor', (int) $vRetry->fields['version'] > $existing);
        $this->check('[DEFBUILD] la colisión se detectó (firstCall consumido)', $collider->firstCall === false);
        // Nunca cero versiones activas: exactamente una activa para el code.
        $this->check('[DEFBUILD] exactamente una versión activa', $this->activeCount($code) === 1 && (int) $vRetry->fields['is_active'] === 1);
    }

    /** @return array<string,mixed> */
    private function miniSpec(string $code, string $name): array
    {
        return [
            'code' => $code, 'name' => $name,
            'states' => [['code' => 'A', 'kind' => StateDef::KIND_INITIAL], ['code' => 'B', 'kind' => StateDef::KIND_FINAL]],
            'transitions' => [['from' => 'A', 'to' => 'B', 'action' => 'submit']],
        ];
    }

    // ------------------------------------------------------------------ [STARTVAL]

    private function scenarioStartInstanceValidation(): void
    {
        $this->out->writeln('== [STARTVAL] startInstance fail-closed ==');
        $api = new WorkflowApi();
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ, 'computer' => READ]);

        // itemtype mismatch (def objetivo Computer, pasamos Monitor).
        $this->check('[STARTVAL] itemtype mismatch → excepción', $this->expectStartThrows($api, $this->def, 'Monitor', $this->makeComputer(), $this->entityB));
        // objeto inexistente.
        $this->check('[STARTVAL] objeto inexistente → excepción', $this->expectStartThrows($api, $this->def, 'Computer', 999999999, $this->entityB));
        // entidad incoherente (computer en B, pasamos A).
        $this->check('[STARTVAL] entidad incoherente → excepción', $this->expectStartThrows($api, $this->def, 'Computer', $this->makeComputer(), $this->entityA));

        // definición inactiva: crear code con v2 (v1 queda inactiva) y arrancar sobre v1.
        $this->applySession(2, [0], ['plugin_companyworkflow' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        $codeInact = 'wf_inact_' . $this->suffix;
        $v1 = $api->builder()->createVersion($this->miniSpecComputer($codeInact));
        $api->builder()->createVersion($this->miniSpecComputer($codeInact)); // v2 → v1 inactiva
        $v1->getFromDB($v1->getID());
        $comp = $this->makeComputerInEntity($this->entityB);
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $this->check('[STARTVAL] def inactiva → excepción', $this->expectStartThrows($api, $v1, 'Computer', $comp, $this->entityB));
    }

    /** @return array<string,mixed> */
    private function miniSpecComputer(string $code): array
    {
        return [
            'code' => $code, 'itemtype_target' => 'Computer',
            'states' => [['code' => 'A', 'kind' => StateDef::KIND_INITIAL], ['code' => 'B', 'kind' => StateDef::KIND_FINAL]],
            'transitions' => [['from' => 'A', 'to' => 'B', 'action' => 'submit']],
        ];
    }

    private function expectStartThrows(WorkflowApi $api, WorkflowDef $def, string $itemtype, int $itemsId, int $entity): bool
    {
        try {
            $api->startInstance($def, $itemtype, $itemsId, $entity, 0);
            return false;
        } catch (\Throwable) {
            return true;
        }
    }

    // ------------------------------------------------------------------ [SLA]

    private function scenarioSla(): void
    {
        $this->out->writeln('== [SLA] vencimiento + escalamiento ==');
        /** @var \DBmysql $DB */
        global $DB;
        $api = new WorkflowApi();
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($this->def, 'Computer', $this->makeComputer(), $this->entityB, 0);
        if ($inst === null) {
            $this->check('[SLA] instancia', false);
            return;
        }
        $api->transition($inst, 'submit', []);
        $DB->update('glpi_plugin_companyworkflow_history', ['date' => date('Y-m-d H:i:s', time() - 7200)], ['instances_id' => $inst->getID(), 'to_code' => 'PENDING_L1']);

        $sla = new SlaService();
        $this->check('[SLA] isOverdue puro true', $sla->isOverdue(1, date('Y-m-d H:i:s', time() - 7200)) === true);
        $escalated = $sla->processOverdue();
        $this->check('[SLA] escaló ≥1', $escalated >= 1);
        $this->check('[SLA] eventos sla_breached + escalated', $this->hasHistoryEvent($inst->getID(), HistoryEvent::EVENT_SLA_BREACHED) && $this->hasHistoryEvent($inst->getID(), HistoryEvent::EVENT_ESCALATED));
        $inst->getFromDB($inst->getID());
        $this->check('[SLA] estado NO cambió (PENDING_L1)', $this->stateCodeOf($inst) === 'PENDING_L1');
        $this->check('[SLA] config habilitada', PluginConfig::boolean('sla_check_enabled'));
    }

    // ------------------------------------------------------------------ [VERSION]

    private function scenarioVersioning(): void
    {
        $this->out->writeln('== [VERSION] instancia conserva su versión ==');
        $api = new WorkflowApi();
        // Code propio para no perturbar el workflow principal.
        $code = 'wf_ver_' . $this->suffix;
        $this->applySession(2, [0], ['plugin_companyworkflow' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        $v1 = $api->builder()->createVersion($this->miniSpecComputer($code));
        $v1DefId = (int) $v1->getID();

        $comp = $this->makeComputerInEntity($this->entityB);
        $this->applySession($this->requester, [$this->entityB], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($v1, 'Computer', $comp, $this->entityB, 0);
        if ($inst === null) {
            $this->check('[VERSION] instancia v1', false);
            return;
        }

        $this->applySession(2, [0], ['plugin_companyworkflow' => ALLSTANDARDRIGHT], 1);
        $v2 = $api->builder()->createVersion($this->miniSpecComputer($code));
        $this->check('[VERSION] v2 activa', (int) $v2->fields['version'] === 2 && (int) $v2->fields['is_active'] === 1);
        $this->check('[VERSION] activeByCode = v2', ($api->builder()->activeByCode($code)?->getID()) === $v2->getID());

        $inst->getFromDB($inst->getID());
        $this->check('[VERSION] instancia sigue en v1', (int) $inst->fields['workflowdefs_id'] === $v1DefId && (int) $inst->fields['def_version'] === 1 && $v1DefId !== $v2->getID());
    }

    // ------------------------------------------------------------------ helpers

    private function makeComputer(): int
    {
        return $this->makeComputerInEntity($this->entityB);
    }

    private function makeComputerInEntity(int $entityId): int
    {
        // Crear el Computer requiere derechos NATIVOS de 'computer'. Este helper NO debe contaminar la
        // sesión del llamador: varios escenarios evalúan makeComputer() en línea DENTRO de startInstance
        // (después de applySession), y la transición siguiente (p. ej. submit) correría con la sesión
        // pisada → DENIED_ACL. Se aísla con snapshot → cambio → restore (helper sin efectos colaterales).
        $sessionSnapshot = $_SESSION;
        try {
            $this->applySession(2, [0, $entityId], ['computer' => ALLSTANDARDRIGHT], 1);
            return (int) (new Computer())->add(['name' => 'WF-ITEM-' . $this->suffix . '-' . random_int(1000, 9999), 'entities_id' => $entityId]);
        } finally {
            $_SESSION = $sessionSnapshot;
        }
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
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => Assignment::getTable(), 'WHERE' => ['instances_id' => $instanceId, 'decision' => Assignment::DECISION_APPROVED]]) as $row) {
            $n = (int) $row['c'];
        }
        return $n;
    }

    /**
     * Diagnóstico DETERMINISTA de una transición (sin secretos): explica por qué `approve()` no dejó
     * el estado esperado (p. ej. 0 votos). Imprime el TransitionResult REAL (code/message) más el
     * contexto necesario para clasificar la causa (ACL/entidad, aprobador/grupo, versión, rollback).
     */
    private function diagTransition(string $scenario, string $action, int $instanceId, int $actor, string $stateBefore, int $approvalsBefore, TransitionResult $r): void
    {
        $inst = new Instance();
        $entity = -1;
        $lockVersion = -1;
        $stateAfter = '?';
        if ($inst->getFromDB($instanceId)) {
            $entity      = (int) ($inst->fields['entities_id'] ?? -1);
            $lockVersion = (int) ($inst->fields['lock_version'] ?? -1);
            $stateAfter  = $this->stateCodeOf($inst);
        }
        $this->out->writeln(sprintf(
            '    [DIAG] scenario=%s action=%s instance=%d entity=%d actor=%d state_before=%s '
            . 'code=%s success=%d msg="%s" approvals_before=%d approvals_after=%d state_after=%s '
            . 'lock_version=%d actor_in_g1=%d g1_members=%d',
            $scenario,
            $action,
            $instanceId,
            $entity,
            $actor,
            $stateBefore,
            $r->code,
            $r->success ? 1 : 0,
            str_replace('"', "'", $r->message),
            $approvalsBefore,
            $this->approvalsCount($instanceId),
            $stateAfter,
            $lockVersion,
            $this->userInGroup($actor, $this->g1) ? 1 : 0,
            $this->groupMemberCount($this->g1)
        ));
    }

    private function userInGroup(int $userId, int $groupId): bool
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $groupId, 'users_id' => $userId]]) as $row) {
            return ((int) $row['c']) > 0;
        }
        return false;
    }

    private function groupMemberCount(int $groupId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $groupId]]) as $row) {
            return (int) $row['c'];
        }
        return 0;
    }

    private function maxVersion(string $code): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $v = 0;
        foreach ($DB->request(['SELECT' => 'version', 'FROM' => WorkflowDef::getTable(), 'WHERE' => ['code' => $code], 'ORDER' => 'version DESC', 'LIMIT' => 1]) as $row) {
            $v = (int) $row['version'];
        }
        return $v;
    }

    private function activeCount(string $code): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => WorkflowDef::getTable(), 'WHERE' => ['code' => $code, 'is_active' => 1]]) as $row) {
            $n = (int) $row['c'];
        }
        return $n;
    }

    private function hasHistoryEvent(int $instanceId, string $event): bool
    {
        // Chequeo de EXISTENCIA tolerante a múltiples filas (p. ej. hay 2 `quorum_reached` en el
        // ciclo feliz L1+L2): getFromDBByCrit lanza excepción si el criterio devuelve >1.
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => HistoryEvent::getTable(), 'WHERE' => ['instances_id' => $instanceId, 'event' => $event]]) as $row) {
            return ((int) $row['c']) > 0;
        }
        return false;
    }

    /**
     * @param array<int>        $entities
     * @param array<string,int> $rights
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
        $_SESSION['glpiactiveprofile']           = array_merge(['id' => 1, 'interface' => 'central', 'entities_id' => $entities[0] ?? 0], $rights);
    }

    private function cleanup(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => WorkflowDef::getTable(), 'WHERE' => ['code' => ['LIKE', 'wf\\_%' . $this->suffix]]]) as $row) {
                $DB->delete(WorkflowDef::getTable(), ['id' => (int) $row['id']]);
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
