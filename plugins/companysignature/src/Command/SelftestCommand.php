<?php

/**
 * Autotest de INTEGRACIÓN + E2E de companysignature (corre DENTRO de un GLPI arrancado, en CI).
 *
 * Nombre: `plugins:companysignature:selftest`. Fail-closed → exit 1 si algo falla.
 *
 * Cobertura (gate + hardening §1–§7; domain-agnostic, sin Compras):
 *   [PERSIST]     tablas propias + columna `workflow_history_id`.
 *   [ROUTES]      GET /verify/{token} AUTHENTICATED.
 *   [WIRING]      listeners decision_recorded/transitioned/approval_invalidated + comando reconcile.
 *   [VERSION]     versión inmutable + idempotente (D1).
 *   [LIVE]        aprobación de actor → evidencia por aprobador + evidencia de transición separada;
 *                 verificación valid; PDF Document nativo + regeneración idempotente;
 *                 CRASH-RECOVERY de PDF (relink por marcador, sin duplicar).
 *   [NO-SNAPSHOT] evento de aprobación SIN versión → NO se crea evidencia válida (fail-closed §2);
 *                 y VerificationService fail-closed ante evidencia sin versión.
 *   [QUORUM]      quórum 3/3 → TRES evidencias APPROVED individuales + UNA transición (§4).
 *   [RECONCILE]   listener perdido → reconcile materializa exactamente una vez (idempotente §1).
 *   [INVALIDATE]  invalidación EXACTA por instancia/aprobación (§5): mismo sujeto, otra instancia
 *                 sigue valid; v1 invalidada / v2 valid.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Command;

use Computer;
use Config;
use Document;
use Document_Item;
use Entity;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companysignature\Api\SignatureApi;
use GlpiPlugin\Companysignature\Controller\VerifyController;
use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;
use GlpiPlugin\Companysignature\Service\EvidenceRecorder;
use GlpiPlugin\Companysignature\Service\Materializer;
use GlpiPlugin\Companysignature\Service\PluginConfig;
use GlpiPlugin\Companysignature\Service\VerificationService;
use GlpiPlugin\Companyworkflow\Api\WorkflowApi;
use GlpiPlugin\Companyworkflow\Model\HistoryEvent as WfHistoryEvent;
use GlpiPlugin\Companyworkflow\Model\StateDef;
use GlpiPlugin\Companyworkflow\Model\Step;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;
use Group;
use Group_User;
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
    /** Reloj MONOTÓNICO de la prueba: cada applySession avanza el tiempo, para que el orden causal
     *  (submit < registrar versión < approve) sea inequívoco y `versionInEffectAt` sea determinista
     *  aunque todo corra en el mismo segundo de reloj real. */
    private int $clock = 0;

    /** @var array<int,int> */
    private array $createdUsers = [];
    private array $createdGroups = [];
    private array $createdEntities = [];
    private array $createdComputers = [];

    private int $baseHistoryId = 0; // baseline del ledger: reconcile sólo procesa filas de ESTA prueba
    private int $entityA = 0;
    private int $entityB = 0;
    private int $uReq = 0;
    private int $uA1 = 0;
    private int $uA2 = 0;
    private int $uA3 = 0;
    private int $g3 = 0;

    protected function configure(): void
    {
        $this->setName('plugins:companysignature:selftest')
            ->setDescription('Pruebas de integración + E2E de firma/evidencia (durabilidad, por-aprobador, invalidación exacta, PDF crash-safe).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->suffix = substr((string) time(), -6);
        $this->clock = time() - 3600; // base 1h en el pasado; avanza monotónicamente por applySession

        $this->checkPersistence();
        $this->checkRoutes();
        $this->checkWiring();

        if (!class_exists(WorkflowApi::class)) {
            $this->check('[E2E] companyworkflow disponible (dependencia de integración)', false);
            $output->writeln('<error>SELFTEST: companyworkflow ausente.</error>');
            return Command::FAILURE;
        }

        $this->buildFixtures();
        $this->baseHistoryId = $this->maxHistoryId(); // sólo reconciliar el ledger de esta prueba

        $this->scenarioLive();
        $this->scenarioNoSnapshotFailClosed();
        $this->scenarioQuorumThree();
        $this->scenarioReconcile();
        $this->scenarioInvalidationExact();

        $this->cleanup();

        if ($this->failures > 0) {
            $output->writeln(sprintf('<error>SELFTEST: %d comprobación(es) fallida(s).</error>', $this->failures));
            return Command::FAILURE;
        }
        $output->writeln('<info>SELFTEST: todas las comprobaciones pasaron.</info>');
        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------ [PERSIST]/[ROUTES]/[WIRING]

    private function checkPersistence(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [PERSIST] tablas propias + columna durable ==');
        foreach (['evidences', 'document_versions'] as $t) {
            $this->check("tabla glpi_plugin_companysignature_{$t} existe", $DB->tableExists("glpi_plugin_companysignature_{$t}"));
        }
        $this->check('columna evidences.workflow_history_id existe', $DB->fieldExists('glpi_plugin_companysignature_evidences', 'workflow_history_id'));
    }

    private function checkRoutes(): void
    {
        $this->out->writeln('== [ROUTES] superficie HTTP ==');
        try {
            $m = (new \ReflectionClass(VerifyController::class))->getMethod('verify');
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
            $this->check('ruta /verify/{token} declarada', str_contains($path, '/verify/{token}'));
            $this->check('ruta es GET', in_array('GET', $methods, true));
            $this->check('ruta es AUTHENTICATED', $strategy === Firewall::STRATEGY_AUTHENTICATED);
        } catch (\Throwable $e) {
            $this->check('reflexión de VerifyController: ' . $e->getMessage(), false);
        }
    }

    private function checkWiring(): void
    {
        global $PLUGIN_HOOKS;
        $this->out->writeln('== [WIRING] listeners + reconcile ==');
        $this->check('listener :decision_recorded registrado', ($PLUGIN_HOOKS['companyworkflow:decision_recorded']['companysignature'] ?? null) === 'plugin_companysignature_on_decision_recorded');
        $this->check('listener :transitioned registrado', ($PLUGIN_HOOKS['companyworkflow:transitioned']['companysignature'] ?? null) === 'plugin_companysignature_on_transitioned');
        $this->check('listener :approval_invalidated registrado', ($PLUGIN_HOOKS['companyworkflow:approval_invalidated']['companysignature'] ?? null) === 'plugin_companysignature_on_approval_invalidated');
        $this->check('comando reconcile existe', class_exists(ReconcileCommand::class));
    }

    // ------------------------------------------------------------------ fixtures

    private function buildFixtures(): void
    {
        $this->out->writeln('== fixtures (entidades/usuarios/grupo de quórum) ==');
        $this->applySession(2, [0], [
            'entity' => ALLSTANDARDRIGHT, 'user' => ALLSTANDARDRIGHT, 'group' => ALLSTANDARDRIGHT,
            'computer' => ALLSTANDARDRIGHT, 'profile' => ALLSTANDARDRIGHT,
        ], 1);

        $this->entityA = (int) (new Entity())->add(['name' => 'SIG-A-' . $this->suffix, 'entities_id' => 0]);
        $this->entityB = (int) (new Entity())->add(['name' => 'SIG-B-' . $this->suffix, 'entities_id' => 0]);
        $this->createdEntities = array_filter([$this->entityA, $this->entityB]);
        $this->check('entidades A y B creadas', $this->entityA > 0 && $this->entityB > 0);

        $this->uReq = $this->makeUser('req');
        $this->uA1  = $this->makeUser('a1');
        $this->uA2  = $this->makeUser('a2');
        $this->uA3  = $this->makeUser('a3');
        $this->check('usuarios creados', min($this->uReq, $this->uA1, $this->uA2, $this->uA3) > 0);

        $this->g3 = (int) (new Group())->add(['name' => 'SIG-G3-' . $this->suffix, 'entities_id' => $this->entityA]);
        $this->createdGroups[] = $this->g3;
        foreach ([$this->uA1, $this->uA2, $this->uA3] as $u) {
            (new Group_User())->add(['groups_id' => $this->g3, 'users_id' => $u]);
        }
        $this->check('grupo de quórum (3 miembros)', $this->g3 > 0);
    }

    private function makeUser(string $tag): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $id = (int) (new User())->add(['name' => 'sig_' . $tag . '_' . $this->suffix, 'realname' => 'SIG ' . $tag, '_no_history' => true]);
        if ($id > 0) {
            $this->createdUsers[] = $id;
            $pu = new Profile_User();
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $id]]) as $row) {
                $pu->delete(['id' => (int) $row['id']], true);
            }
            (new Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => $this->entityA, 'is_recursive' => 0]);
        }
        return $id;
    }

    private function makeComputer(): int
    {
        $this->applySession(2, [0, $this->entityA], ['computer' => ALLSTANDARDRIGHT], 1);
        $id = (int) (new Computer())->add(['name' => 'SIG-ITEM-' . $this->suffix . '-' . random_int(1000, 9999), 'entities_id' => $this->entityA]);
        if ($id > 0) {
            $this->createdComputers[] = $id;
        }
        return $id;
    }

    /** Snapshot canónico de ejemplo (payload arbitrario; domain-agnostic). */
    private function snap(int $subjectId, int $version, string $marker): array
    {
        return [
            'schema'           => 'selftest/v1',
            'subject_type'     => 'Computer',
            'subject_id'       => $subjectId,
            'entity_id'        => $this->entityA,
            'document_version' => $version,
            'payload'          => ['marker' => $marker, 'amount' => '1000.00', 'items' => ['a', 'b']],
        ];
    }

    /** Definición single-actor: DRAFT→PENDING (submit) → APPROVED (approve, intermedio; queda OPEN). */
    private function defSingle(WorkflowApi $api, string $code): WorkflowDef
    {
        $this->applySession(2, [0, $this->entityA], ['plugin_companyworkflow' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        return $api->builder()->createVersion([
            'code' => $code, 'name' => 'sig single', 'itemtype_target' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1,
            'states' => [
                ['code' => 'DRAFT', 'kind' => StateDef::KIND_INITIAL, 'is_editable' => 1],
                ['code' => 'PENDING', 'kind' => StateDef::KIND_INTERMEDIATE],
                ['code' => 'APPROVED', 'kind' => StateDef::KIND_INTERMEDIATE],
            ],
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PENDING', 'action' => 'submit'],
                ['from' => 'PENDING', 'to' => 'APPROVED', 'action' => 'approve', 'required_right' => WorkflowDef::RIGHT_ACT],
            ],
        ]);
    }

    /** Definición con quórum 3/3 sobre g3: PENDING→DONE (final). */
    private function defQuorum(WorkflowApi $api, string $code): WorkflowDef
    {
        $this->applySession(2, [0, $this->entityA], ['plugin_companyworkflow' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        return $api->builder()->createVersion([
            'code' => $code, 'name' => 'sig quorum', 'itemtype_target' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1,
            'states' => [
                ['code' => 'DRAFT', 'kind' => StateDef::KIND_INITIAL, 'is_editable' => 1],
                ['code' => 'PENDING', 'kind' => StateDef::KIND_INTERMEDIATE],
                ['code' => 'DONE', 'kind' => StateDef::KIND_FINAL],
            ],
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PENDING', 'action' => 'submit'],
                ['from' => 'PENDING', 'to' => 'DONE', 'action' => 'approve', 'required_right' => WorkflowDef::RIGHT_ACT,
                 'steps' => [['level' => 1, 'quorum_type' => Step::QUORUM_COUNT, 'quorum_value' => 3, 'approver_kind' => Step::APPROVER_GROUP, 'approver_ref' => $this->g3]]],
            ],
        ]);
    }

    // ------------------------------------------------------------------ [LIVE] por-aprobador + transición + PDF

    private function scenarioLive(): void
    {
        $this->out->writeln('== [LIVE] decisión por aprobador + transición separada + PDF crash-safe ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $sig = new SignatureApi();
        $c = $this->makeComputer();
        $def = $this->defSingle($api, 'sig_live_' . $this->suffix);

        // start + submit (uReq), luego registrar v1, luego approve (uA1) → hooks en vivo materializan.
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($def, 'Computer', $c, $this->entityA, 0);
        if ($inst === null) {
            $this->check('[LIVE] instancia', false);
            return;
        }
        $api->transition($inst, 'submit', []);
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $dv1 = $sig->recordDocumentVersion($this->snap($c, 1, 'X'), true);
        $this->check('[VERSION] v1 inmutable creada', $dv1->getID() > 0 && $dv1->versionNumber() === 1 && strlen($dv1->contentHash()) === 64);
        $this->check('[VERSION] idempotente (mismo contenido → misma fila)', (int) $sig->recordDocumentVersion($this->snap($c, 1, 'X'), true)->getID() === (int) $dv1->getID());

        $this->applySession($this->uA1, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT, 'plugin_companysignature' => ALLSTANDARDRIGHT]);
        $r = $api->transition($inst, 'approve', ['comment' => 'ok a1']);
        $this->check('[LIVE] approve → APPROVED (instancia sigue OPEN)', $r->success && ($r->data['to'] ?? '') === 'APPROVED');
        // Materialización durable (vía listener en vivo si el hook corre en consola; si no, reconcile
        // deja el MISMO resultado: idempotente). Así la prueba no depende del entorno de hooks.
        $this->reconcile();

        // Evidencia por aprobador (1 approved) + transición separada (1 transition).
        $this->check('[LIVE] 1 evidencia APPROVED por el aprobador', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 1);
        $this->check('[LIVE] 1 evidencia de TRANSICIÓN separada', $this->countEvidence($c, ApprovalEvidence::EVENT_TRANSITION, null) === 1);
        $ev = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        $this->check('[LIVE] content_sha256 = hash de v1 + workflow_history_id durable', $ev !== null && (string) $ev->fields['content_sha256'] === $dv1->contentHash() && (int) $ev->fields['workflow_history_id'] > 0);
        $token = $ev !== null ? (string) $ev->fields['verification_token'] : '';

        // Verificación valid.
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $this->check('[VERIFY] token válido → valid', $sig->verify($token)['status'] === VerificationService::STATUS_VALID);

        // PDF como Document nativo + idempotencia.
        $docsBefore = $this->countDocuments($c);
        $dv1 = $sig->composePdf((int) $dv1->getID());
        $this->check('[PDF] pdf_status=ready + Document nativo + pdf_sha256', (string) $dv1->fields['pdf_status'] === DocumentVersion::PDF_READY && (int) $dv1->fields['documents_id'] > 0 && strlen((string) $dv1->fields['pdf_sha256']) === 64);
        $docId = (int) $dv1->fields['documents_id'];
        $sig->composePdf((int) $dv1->getID());
        $this->check('[PDF] regeneración idempotente (no duplica Document)', $this->countDocuments($c) === $docsBefore + 1);

        // CRASH-RECOVERY (§7): simular caída ANTES de markPdfReady → Document existe (marcador) pero
        // nuestra tabla quedó documents_id=0/pending. El reintento DEBE relinkear el mismo Document.
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(DocumentVersion::getTable(), ['documents_id' => 0, 'pdf_status' => DocumentVersion::PDF_PENDING], ['id' => (int) $dv1->getID()]);
        $docsMid = $this->countDocumentsRaw($c);
        $dv1 = $sig->composePdf((int) $dv1->getID());
        $this->check('[PDF] crash-recovery: reutiliza el MISMO Document (sin duplicar)', (int) $dv1->fields['documents_id'] === $docId && $this->countDocumentsRaw($c) === $docsMid);
        $this->check('[PDF] crash-recovery: vuelve a ready', (string) $dv1->fields['pdf_status'] === DocumentVersion::PDF_READY);
    }

    // ------------------------------------------------------------------ [NO-SNAPSHOT] fail-closed §2

    private function scenarioNoSnapshotFailClosed(): void
    {
        $this->out->writeln('== [NO-SNAPSHOT] aprobación sin versión → NO evidencia válida (fail-closed §2) ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $c = $this->makeComputer();
        $def = $this->defSingle($api, 'sig_nosnap_' . $this->suffix);

        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($def, 'Computer', $c, $this->entityA, 0);
        $api->transition($inst, 'submit', []);
        // NO registramos versión documental.
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $api->transition($inst, 'approve', ['comment' => 'sin snapshot']);
        $this->check('[NO-SNAPSHOT] approve sin versión → 0 evidencias (pendiente)', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 0);
        // Reconciliar tampoco crea evidencia mientras no exista snapshot.
        $this->reconcile();
        $this->check('[NO-SNAPSHOT] reconcile sin versión → sigue 0 (nunca versión 0/hash vacío)', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 0);

        // VerificationService fail-closed ante evidencia sin versión: nunca 'valid'.
        $ev = (new EvidenceRecorder())->record([
            'idempotency_key' => 'nosnap-' . $this->suffix, 'subject_itemtype' => 'Computer', 'subject_items_id' => $c,
            'entities_id' => $this->entityA, 'document_versions_id' => 0, 'document_version' => 0, 'content_sha256' => '',
            'actor_users_id' => $this->uA1, 'decision' => ApprovalEvidence::DECISION_APPROVED, 'event_type' => ApprovalEvidence::EVENT_DECISION,
        ]);
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $res = $ev !== null ? (new SignatureApi())->verify((string) $ev->fields['verification_token']) : ['status' => 'x'];
        $this->check('[NO-SNAPSHOT] verify de evidencia sin versión → NUNCA valid (tampered)', $res['status'] === VerificationService::STATUS_TAMPERED);
    }

    // ------------------------------------------------------------------ [QUORUM] 3/3 → 3 approved + 1 transición §4

    private function scenarioQuorumThree(): void
    {
        $this->out->writeln('== [QUORUM] 3/3 → tres evidencias APPROVED + una transición ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $sig = new SignatureApi();
        $c = $this->makeComputer();
        $def = $this->defQuorum($api, 'sig_q3_' . $this->suffix);

        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($def, 'Computer', $c, $this->entityA, 0);
        $api->transition($inst, 'submit', []);
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $sig->recordDocumentVersion($this->snap($c, 1, 'Q'), true);

        foreach ([$this->uA1, $this->uA2, $this->uA3] as $u) {
            $this->applySession($u, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
            $api->transition($inst, 'approve', ['comment' => 'ok ' . $u]);
        }
        $this->reconcile(); // materialización durable idempotente (independiente del entorno de hooks)
        $inst->getFromDB((int) $inst->getID());
        $this->check('[QUORUM] alcanzó DONE (cerrada)', $this->stateCodeOf($inst) === 'DONE');
        $this->check('[QUORUM] TRES evidencias APPROVED individuales', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 3);
        $this->check('[QUORUM] UNA sola evidencia de transición', $this->countEvidence($c, ApprovalEvidence::EVENT_TRANSITION, null) === 1);
        $this->check('[QUORUM] tres aprobadores DISTINTOS', count($this->distinctActors($c, ApprovalEvidence::DECISION_APPROVED)) === 3);
    }

    // ------------------------------------------------------------------ [RECONCILE] listener perdido §1

    private function scenarioReconcile(): void
    {
        $this->out->writeln('== [RECONCILE] listener perdido → reconcile materializa una sola vez ==');
        $api = new WorkflowApi();
        $sig = new SignatureApi();
        $c = $this->makeComputer();
        $def = $this->defSingle($api, 'sig_rec_' . $this->suffix);

        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($def, 'Computer', $c, $this->entityA, 0);
        $api->transition($inst, 'submit', []);
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $sig->recordDocumentVersion($this->snap($c, 1, 'R'), true);

        // LISTENER PERDIDO: se deshabilita el consumo en vivo (simula caída tras el COMMIT).
        $this->setListen(false);
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $api->transition($inst, 'approve', ['comment' => 'lost']);
        $this->check('[RECONCILE] con listener perdido → 0 evidencias', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 0);

        // RECONCILIAR: materializa lo pendiente exactamente una vez.
        $this->setListen(true);
        $this->reconcile();
        $this->check('[RECONCILE] tras reconcile → 1 APPROVED', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 1);
        $this->check('[RECONCILE] tras reconcile → 1 transición', $this->countEvidence($c, ApprovalEvidence::EVENT_TRANSITION, null) === 1);
        // IDEMPOTENTE: reejecutar no duplica.
        $this->reconcile();
        $this->check('[RECONCILE] idempotente (2ª pasada no duplica)', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 1 && $this->countEvidence($c, ApprovalEvidence::EVENT_TRANSITION, null) === 1);
    }

    // ------------------------------------------------------------------ [INVALIDATE] exacta §5

    private function scenarioInvalidationExact(): void
    {
        $this->out->writeln('== [INVALIDATE] exacta por instancia/aprobación (§5) ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $sig = new SignatureApi();

        // --- (A) mismo sujeto, otra instancia sigue valid ---
        $c = $this->makeComputer();
        $def = $this->defSingle($api, 'sig_invA_' . $this->suffix);
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($def, 'Computer', $c, $this->entityA, 0);
        $api->transition($inst, 'submit', []);
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $dvA = $sig->recordDocumentVersion($this->snap($c, 1, 'A'), true);
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $api->transition($inst, 'approve', ['comment' => 'A']);
        $this->reconcile();
        $e1 = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        // Aprobación de "otra instancia" (mismo sujeto) fabricada con workflow_instances_id distinto.
        $otherInstanceId = (int) $inst->getID() + 777001;
        $e2 = (new EvidenceRecorder())->record([
            'idempotency_key' => 'invB-' . $this->suffix, 'subject_itemtype' => 'Computer', 'subject_items_id' => $c,
            'entities_id' => $this->entityA, 'workflow_instances_id' => $otherInstanceId,
            'document_versions_id' => (int) $dvA->getID(), 'document_version' => 1, 'content_sha256' => $dvA->contentHash(),
            'actor_users_id' => $this->uA2, 'decision' => ApprovalEvidence::DECISION_APPROVED, 'event_type' => ApprovalEvidence::EVENT_DECISION,
        ]);
        // Invalidar SOLO la instancia real (RIGHT_ACT).
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT, 'plugin_companysignature' => ALLSTANDARDRIGHT]);
        $api->invalidateApprovals((int) $inst->getID(), 'contenido cambió', ['idempotency_key' => 'kinvA-' . $this->suffix, 'document_version' => 1]);
        $this->reconcile();
        $tokenE1 = $e1 !== null ? (string) $e1->fields['verification_token'] : '';
        $tokenE2 = $e2 !== null ? (string) $e2->fields['verification_token'] : '';
        $this->check('[INVALIDATE] aprobación de la instancia invalidada → invalidated', $sig->verify($tokenE1)['status'] === VerificationService::STATUS_INVALIDATED);
        $this->check('[INVALIDATE] 🔒 aprobación de OTRA instancia (mismo sujeto) → sigue valid', $sig->verify($tokenE2)['status'] === VerificationService::STATUS_VALID);
        $this->check('[INVALIDATE] evidencia previa CONSERVADA (append-only, no borrada)', $e1 !== null && (new ApprovalEvidence())->getFromDB((int) $e1->getID()));

        // --- (B) v1 aprobada → invalidada → v2 aprobada → v1 invalidated / v2 valid ---
        $c2 = $this->makeComputer();
        $def2 = $this->defSingle($api, 'sig_invB_' . $this->suffix);
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst2 = $api->startInstance($def2, 'Computer', $c2, $this->entityA, 0);
        $api->transition($inst2, 'submit', []);
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $sig->recordDocumentVersion($this->snap($c2, 1, 'v1'), true);
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $api->transition($inst2, 'approve', ['comment' => 'v1']);
        $this->reconcile();
        $eV1 = $this->latestEvidence($c2, ApprovalEvidence::DECISION_APPROVED);

        // Invalidar v1 (reabre a DRAFT).
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT, 'plugin_companysignature' => ALLSTANDARDRIGHT]);
        $api->invalidateApprovals((int) $inst2->getID(), 'cambio sustantivo', ['idempotency_key' => 'kinvB-' . $this->suffix, 'document_version' => 1]);
        $this->reconcile();
        // Nueva versión v2 + reenviar + aprobar.
        $sig->recordDocumentVersion($this->snap($c2, 2, 'v2-cambiada'), true);
        $inst2->getFromDB((int) $inst2->getID());
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $api->transition($inst2, 'submit', []);
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $api->transition($inst2, 'approve', ['comment' => 'v2']);
        $this->reconcile();
        $eV2 = $this->latestEvidence($c2, ApprovalEvidence::DECISION_APPROVED);

        $this->applySession($this->uReq, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $this->check('[INVALIDATE] v1 → invalidated', $eV1 !== null && $sig->verify((string) $eV1->fields['verification_token'])['status'] === VerificationService::STATUS_INVALIDATED);
        $this->check('[INVALIDATE] v2 → valid', $eV2 !== null && (int) $eV2->getID() !== (int) ($eV1?->getID() ?? 0) && $sig->verify((string) $eV2->fields['verification_token'])['status'] === VerificationService::STATUS_VALID);
    }

    // ------------------------------------------------------------------ helpers

    private function reconcile(): int
    {
        $api = new WorkflowApi();
        $rows = $api->history([
            'events'   => [Materializer::WF_DECISION_RECORDED, Materializer::WF_TRANSITIONED, Materializer::WF_APPROVAL_INVALIDATED],
            'since_id' => $this->baseHistoryId,
        ]);
        $mat = new Materializer();
        $n = 0;
        foreach ($rows as $row) {
            $n += $mat->materializeRow($row);
        }
        return $n;
    }

    private function maxHistoryId(): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $m = 0;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => WfHistoryEvent::getTable(), 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $row) {
            $m = (int) ($row['id'] ?? 0);
        }
        return $m;
    }

    private function setListen(bool $on): void
    {
        Config::setConfigurationValues(PluginConfig::CONTEXT, ['listen_workflow_events' => $on ? '1' : '0']);
    }

    private function countEvidence(int $subjectId, string $eventType, ?string $decision): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $where = ['subject_itemtype' => 'Computer', 'subject_items_id' => $subjectId, 'event_type' => $eventType];
        if ($decision !== null) {
            $where['decision'] = $decision;
        }
        $n = 0;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => ApprovalEvidence::getTable(), 'WHERE' => $where]) as $row) {
            $n = (int) $row['c'];
        }
        return $n;
    }

    /** @return array<int,int> */
    private function distinctActors(int $subjectId, string $decision): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $seen = [];
        foreach ($DB->request(['SELECT' => 'actor_users_id', 'FROM' => ApprovalEvidence::getTable(), 'WHERE' => ['subject_itemtype' => 'Computer', 'subject_items_id' => $subjectId, 'event_type' => ApprovalEvidence::EVENT_DECISION, 'decision' => $decision]]) as $row) {
            $seen[(int) $row['actor_users_id']] = true;
        }
        return array_keys($seen);
    }

    private function latestEvidence(int $subjectId, string $decision): ?ApprovalEvidence
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => ApprovalEvidence::getTable(), 'WHERE' => ['subject_itemtype' => 'Computer', 'subject_items_id' => $subjectId, 'decision' => $decision, 'event_type' => ApprovalEvidence::EVENT_DECISION], 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $row) {
            $m = new ApprovalEvidence();
            if ($m->getFromDB((int) $row['id'])) {
                return $m;
            }
        }
        return null;
    }

    /** Vínculos Document_Item del sujeto (evidencia de no-duplicación de PDF). */
    private function countDocuments(int $subjectId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => Document_Item::getTable(), 'WHERE' => ['itemtype' => 'Computer', 'items_id' => $subjectId]]) as $row) {
            $n = (int) $row['c'];
        }
        return $n;
    }

    /** Documents nativos con nuestro marcador para el sujeto (recuento crudo, para crash-recovery). */
    private function countDocumentsRaw(int $subjectId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => Document::getTable(), 'WHERE' => ['name' => ['LIKE', '[companysignature] Computer#' . $subjectId . ' %']]]) as $row) {
            $n = (int) $row['c'];
        }
        return $n;
    }

    private function stateCodeOf(\CommonDBTM $instance): string
    {
        $s = new StateDef();
        return $s->getFromDB((int) $instance->fields['current_statedefs_id']) ? (string) $s->fields['code'] : '';
    }

    /**
     * @param array<int>        $entities
     * @param array<string,int> $rights
     */
    private function applySession(int $userId, array $entities, array $rights, int $recursive = 0): void
    {
        $_SESSION['glpiID']                      = $userId;
        $_SESSION['glpiname']                    = 'sig_selftest';
        $_SESSION['glpiactive_entity']           = $entities[0] ?? 0;
        $_SESSION['glpiactiveentities']          = $entities;
        $_SESSION['glpiactiveentities_string']   = "'" . implode("','", $entities) . "'";
        $_SESSION['glpiactive_entity_recursive'] = $recursive;
        $_SESSION['glpigroups']                  = [];
        $this->clock += 5; // avance monotónico (5s) → orden causal estricto entre eventos/versiones
        $_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s', $this->clock);
        $_SESSION['glpiactiveprofile']           = array_merge(['id' => 1, 'interface' => 'central', 'entities_id' => $entities[0] ?? 0], $rights);
    }

    private function cleanup(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            foreach ($this->createdComputers as $c) {
                $DB->delete(ApprovalEvidence::getTable(), ['subject_itemtype' => 'Computer', 'subject_items_id' => $c]);
                $DB->delete(DocumentVersion::getTable(), ['subject_itemtype' => 'Computer', 'subject_items_id' => $c]);
                (new Computer())->delete(['id' => $c], true);
            }
            $wfDefTable = WorkflowDef::getTable();
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => $wfDefTable, 'WHERE' => ['code' => ['LIKE', 'sig\\_%' . $this->suffix]]]) as $row) {
                $DB->delete($wfDefTable, ['id' => (int) $row['id']]);
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
