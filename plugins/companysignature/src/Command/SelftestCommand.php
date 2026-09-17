<?php

/**
 * Autotest de INTEGRACIÓN + E2E de companysignature (corre DENTRO de un GLPI arrancado, en CI).
 *
 * Nombre: `plugins:companysignature:selftest`. Fail-closed → exit 1 si algo falla.
 *
 * Cobertura (gate + hardening §1–§7; domain-agnostic, sin Compras):
 *   [PERSIST]     tablas propias + columnas `workflow_history_id`/`materialized_at` + cola durable.
 *   [WIRING]      listeners + comando + CronTask reconcile.
 *   [LIVE/PDF]    decisión por aprobador + transición separada; PDF Document nativo concurrency-safe
 *                 (FOR UPDATE) + idempotente + crash-recovery + relink de Document_Item.
 *   [EVENT-DATE]  §1: event_date = fecha ORIGINAL del ledger; materialized_at = materialización.
 *   [EVIDENCE-REF]§2: identidad por evidence_ref explícita; v1/v2 en el MISMO segundo → materializa v1.
 *   [FAIL-CLOSED] sin evidence_ref/snapshot → pending, sin evidencia; verify fail-closed.
 *   [QUORUM]      3/3 → 3 APPROVED + 1 transición; contexto histórico del aprobador (§5).
 *   [DELEGATION]  §5: delegado → delegated_from histórico conservado aunque cambie el grupo.
 *   [QUEUE]       §3: cron/queue durable; pendiente no bloquea a posteriores; idempotente; restart.
 *   [INVALIDATE]  §4: idempotency_key obligatoria; actor DURABLE reconstruido; exacta A/B.
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
use GlpiPlugin\Companysignature\Model\ReconcileTask;
use GlpiPlugin\Companysignature\Service\EvidenceRecorder;
use GlpiPlugin\Companysignature\Service\PluginConfig;
use GlpiPlugin\Companysignature\Service\ReconcileService;
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
    private int $clock = 0;

    /** @var array<int,int> */
    private array $createdUsers = [];
    private array $createdGroups = [];
    private array $createdEntities = [];
    private array $createdComputers = [];

    private int $entityA = 0;
    private int $uReq = 0;
    private int $uA1 = 0;
    private int $uA2 = 0;
    private int $uA3 = 0;
    private int $uDel = 0;
    private int $g3 = 0;
    private int $gDel = 0;

    protected function configure(): void
    {
        $this->setName('plugins:companysignature:selftest')
            ->setDescription('Pruebas de integración + E2E de firma/evidencia (durabilidad, evidence_ref, cola cron, PDF concurrency).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->suffix = substr((string) time(), -6);
        $this->clock = time() - 3600;

        $this->checkPersistence();
        $this->checkRoutes();
        $this->checkWiring();

        if (!class_exists(WorkflowApi::class)) {
            $this->check('[E2E] companyworkflow disponible', false);
            return Command::FAILURE;
        }

        $this->buildFixtures();

        $this->scenarioLiveAndPdf();
        $this->scenarioEventDateDurable();
        $this->scenarioExplicitRefSameSecond();
        $this->scenarioFailClosed();
        $this->scenarioQuorumAndContext();
        $this->scenarioDelegationContext();
        $this->scenarioQueueDurable();
        $this->scenarioInvalidation();

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
        $this->out->writeln('== [PERSIST] tablas + columnas + cola durable ==');
        foreach (['evidences', 'document_versions', 'reconcile_queue'] as $t) {
            $this->check("tabla glpi_plugin_companysignature_{$t} existe", $DB->tableExists("glpi_plugin_companysignature_{$t}"));
        }
        $this->check('evidences.workflow_history_id existe', $DB->fieldExists('glpi_plugin_companysignature_evidences', 'workflow_history_id'));
        $this->check('evidences.materialized_at existe', $DB->fieldExists('glpi_plugin_companysignature_evidences', 'materialized_at'));
        $this->check('reconcile_queue.workflow_history_id existe', $DB->fieldExists('glpi_plugin_companysignature_reconcile_queue', 'workflow_history_id'));
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
            $this->check('ruta /verify/{token} GET AUTHENTICATED', str_contains($path, '/verify/{token}') && in_array('GET', $methods, true) && $strategy === Firewall::STRATEGY_AUTHENTICATED);
        } catch (\Throwable $e) {
            $this->check('reflexión de VerifyController: ' . $e->getMessage(), false);
        }
    }

    private function checkWiring(): void
    {
        global $PLUGIN_HOOKS;
        $this->out->writeln('== [WIRING] listeners + reconcile + cron ==');
        $this->check('listener :decision_recorded', ($PLUGIN_HOOKS['companyworkflow:decision_recorded']['companysignature'] ?? null) === 'plugin_companysignature_on_decision_recorded');
        $this->check('listener :transitioned', ($PLUGIN_HOOKS['companyworkflow:transitioned']['companysignature'] ?? null) === 'plugin_companysignature_on_transitioned');
        $this->check('listener :approval_invalidated', ($PLUGIN_HOOKS['companyworkflow:approval_invalidated']['companysignature'] ?? null) === 'plugin_companysignature_on_approval_invalidated');
        $this->check('comando reconcile existe', class_exists(ReconcileCommand::class));
        $this->check('CronTask reconcile (cronInfo + cronReconcile)', is_array(ReconcileTask::cronInfo('reconcile')) && method_exists(ReconcileTask::class, 'cronReconcile'));
    }

    // ------------------------------------------------------------------ fixtures

    private function buildFixtures(): void
    {
        $this->out->writeln('== fixtures (entidad/usuarios/grupos/delegación) ==');
        $this->applySession(2, [0], [
            'entity' => ALLSTANDARDRIGHT, 'user' => ALLSTANDARDRIGHT, 'group' => ALLSTANDARDRIGHT,
            'computer' => ALLSTANDARDRIGHT, 'profile' => ALLSTANDARDRIGHT,
        ], 1);

        $this->entityA = (int) (new Entity())->add(['name' => 'SIG-A-' . $this->suffix, 'entities_id' => 0]);
        $this->createdEntities = array_filter([$this->entityA]);
        $this->check('entidad A creada', $this->entityA > 0);

        $this->uReq = $this->makeUser('req');
        $this->uA1  = $this->makeUser('a1');
        $this->uA2  = $this->makeUser('a2');
        $this->uA3  = $this->makeUser('a3');
        $this->uDel = $this->makeUser('del');
        $this->check('usuarios creados', min($this->uReq, $this->uA1, $this->uA2, $this->uA3, $this->uDel) > 0);

        $this->g3 = (int) (new Group())->add(['name' => 'SIG-G3-' . $this->suffix, 'entities_id' => $this->entityA]);
        $this->gDel = (int) (new Group())->add(['name' => 'SIG-GDEL-' . $this->suffix, 'entities_id' => $this->entityA]);
        $this->createdGroups = [$this->g3, $this->gDel];
        foreach ([$this->uA1, $this->uA2, $this->uA3] as $u) {
            (new Group_User())->add(['groups_id' => $this->g3, 'users_id' => $u]);
        }
        (new Group_User())->add(['groups_id' => $this->gDel, 'users_id' => $this->uA1]);

        // Delegación uA1 → uDel (abierta, cualquier def/entidad).
        (new \GlpiPlugin\Companyworkflow\Model\Delegation())->add([
            'users_id_from' => $this->uA1, 'users_id_to' => $this->uDel, 'workflowdefs_id' => 0, 'entities_id' => 0,
            'date_start' => date('Y-m-d H:i:s', time() - 3600), 'date_end' => date('Y-m-d H:i:s', time() + 86400),
            'reason' => 'selftest', 'is_active' => 1,
        ]);
        $this->check('grupos + delegación uA1→uDel', $this->g3 > 0 && $this->gDel > 0);

        // Baseline del ledger: la reconciliación sólo procesa lo de ESTA prueba.
        Config::setConfigurationValues(PluginConfig::CONTEXT, ['last_seen_history_id' => (string) $this->maxHistoryId()]);
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

    /** @return array<string,mixed> */
    private function snap(int $subjectId, int $version, string $marker): array
    {
        return [
            'schema' => 'selftest/v1', 'subject_type' => 'Computer', 'subject_id' => $subjectId,
            'entity_id' => $this->entityA, 'document_version' => $version,
            'payload' => ['marker' => $marker, 'amount' => '1000.00', 'items' => ['a', 'b']],
        ];
    }

    /** evidence_ref EXPLÍCITA (§2) que el dominio adjunta a la transición. @return array<string,mixed> */
    private function evref(DocumentVersion $dv): array
    {
        return ['document_versions_id' => (int) $dv->getID(), 'document_version' => $dv->versionNumber(), 'content_sha256' => $dv->contentHash()];
    }

    private function defSingle(WorkflowApi $api, string $code): WorkflowDef
    {
        $this->applySession(2, [0, $this->entityA], ['plugin_companyworkflow' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        return $api->builder()->createVersion([
            'code' => $code, 'name' => 'single', 'itemtype_target' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1,
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

    /** Quórum sobre $group con $count aprobadores; PENDING→DONE (final). */
    private function defQuorum(WorkflowApi $api, string $code, int $group, int $count): WorkflowDef
    {
        $this->applySession(2, [0, $this->entityA], ['plugin_companyworkflow' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        return $api->builder()->createVersion([
            'code' => $code, 'name' => 'quorum', 'itemtype_target' => 'Computer', 'entities_id' => 0, 'is_recursive' => 1,
            'states' => [
                ['code' => 'DRAFT', 'kind' => StateDef::KIND_INITIAL, 'is_editable' => 1],
                ['code' => 'PENDING', 'kind' => StateDef::KIND_INTERMEDIATE],
                ['code' => 'DONE', 'kind' => StateDef::KIND_FINAL],
            ],
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PENDING', 'action' => 'submit'],
                ['from' => 'PENDING', 'to' => 'DONE', 'action' => 'approve', 'required_right' => WorkflowDef::RIGHT_ACT,
                 'steps' => [['level' => 1, 'quorum_type' => Step::QUORUM_COUNT, 'quorum_value' => $count, 'approver_kind' => Step::APPROVER_GROUP, 'approver_ref' => $group]]],
            ],
        ]);
    }

    /** start + submit (uReq) → devuelve la instancia. */
    private function startSubmit(WorkflowApi $api, WorkflowDef $def, int $c): ?\CommonDBTM
    {
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $inst = $api->startInstance($def, 'Computer', $c, $this->entityA, 0);
        if ($inst === null) {
            return null;
        }
        $api->transition($inst, 'submit', []);
        return $inst;
    }

    /** approve con evidence_ref (o sin ella si $dv es null → provoca pending). */
    private function approve(WorkflowApi $api, \CommonDBTM $inst, int $user, ?DocumentVersion $dv, string $comment): void
    {
        $this->applySession($user, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $ctx = ['comment' => $comment];
        if ($dv !== null) {
            $ctx['evidence_ref'] = $this->evref($dv);
        }
        $api->transition($inst, 'approve', $ctx);
    }

    private function recordVersion(SignatureApi $sig, int $c, int $ver, string $marker): DocumentVersion
    {
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        return $sig->recordDocumentVersion($this->snap($c, $ver, $marker), true);
    }

    // ------------------------------------------------------------------ [LIVE/PDF]

    private function scenarioLiveAndPdf(): void
    {
        $this->out->writeln('== [LIVE/PDF] por-aprobador + transición + PDF concurrency/crash-safe ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $sig = new SignatureApi();
        $c = $this->makeComputer();
        $def = $this->defSingle($api, 'sig_live_' . $this->suffix);
        $inst = $this->startSubmit($api, $def, $c);
        if ($inst === null) {
            $this->check('[LIVE] instancia', false);
            return;
        }
        $dv1 = $this->recordVersion($sig, $c, 1, 'X');
        $this->approve($api, $inst, $this->uA1, $dv1, 'ok a1');
        $this->reconcile();

        $this->check('[LIVE] 1 evidencia APPROVED por aprobador', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 1);
        $this->check('[LIVE] 1 evidencia de TRANSICIÓN separada', $this->countEvidence($c, ApprovalEvidence::EVENT_TRANSITION, null) === 1);
        $ev = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        $this->check('[LIVE] content_sha256=v1 + workflow_history_id durable', $ev !== null && (string) $ev->fields['content_sha256'] === $dv1->contentHash() && (int) $ev->fields['workflow_history_id'] > 0);
        $token = $ev !== null ? (string) $ev->fields['verification_token'] : '';
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $this->check('[VERIFY] valid', $sig->verify($token)['status'] === VerificationService::STATUS_VALID);

        // PDF concurrency-safe + idempotente.
        $docsBefore = $this->countDocuments($c);
        $dv1 = $sig->composePdf((int) $dv1->getID());
        $pdfReady = (string) $dv1->fields['pdf_status'] === DocumentVersion::PDF_READY && (int) $dv1->fields['documents_id'] > 0 && strlen((string) $dv1->fields['pdf_sha256']) === 64;
        if (!$pdfReady) {
            // Motivo REAL (no sólo pdf_status=error): clase + mensaje de la excepción capturada, sin secretos.
            $this->out->writeln(sprintf(
                '    [DIAG PDF] pdf_status=%s documents_id=%d pdf_sha256_len=%d lastError="%s"',
                (string) $dv1->fields['pdf_status'],
                (int) $dv1->fields['documents_id'],
                strlen((string) $dv1->fields['pdf_sha256']),
                str_replace('"', "'", (string) (\GlpiPlugin\Companysignature\Service\ApprovedPdfComposer::$lastError ?? '(sin excepción capturada)'))
            ));
        }
        $this->check('[PDF] ready + Document nativo + pdf_sha256', $pdfReady);
        $docId = (int) $dv1->fields['documents_id'];
        $sig->composePdf((int) $dv1->getID());
        $this->check('[PDF] idempotente (no duplica Document)', $this->countDocuments($c) === $docsBefore + 1);

        // Crash-recovery: reset a pending (Document existe por marcador) → reintento relinkea.
        /** @var \DBmysql $DB */
        global $DB;
        $DB->update(DocumentVersion::getTable(), ['documents_id' => 0, 'pdf_status' => DocumentVersion::PDF_PENDING], ['id' => (int) $dv1->getID()]);
        $rawBefore = $this->countDocumentsRaw($c);
        $dv1 = $sig->composePdf((int) $dv1->getID());
        $this->check('[PDF] crash-recovery: MISMO Document (sin duplicar)', (int) $dv1->fields['documents_id'] === $docId && $this->countDocumentsRaw($c) === $rawBefore && (string) $dv1->fields['pdf_status'] === DocumentVersion::PDF_READY);

        // Document_Item ausente → reintento relinkea sin duplicar Document.
        $DB->delete(Document_Item::getTable(), ['documents_id' => $docId, 'itemtype' => 'Computer', 'items_id' => $c]);
        $DB->update(DocumentVersion::getTable(), ['documents_id' => 0, 'pdf_status' => DocumentVersion::PDF_PENDING], ['id' => (int) $dv1->getID()]);
        $dv1 = $sig->composePdf((int) $dv1->getID());
        $this->check('[PDF] Document_Item ausente → relink sin duplicar', (int) $dv1->fields['documents_id'] === $docId && $this->countDocumentsRaw($c) === $rawBefore && $this->countDocuments($c) === 1);
    }

    // ------------------------------------------------------------------ [EVENT-DATE] §1

    private function scenarioEventDateDurable(): void
    {
        $this->out->writeln('== [EVENT-DATE] event_date=ledger vs materialized_at (§1) ==');
        $this->setListen(false); // listener perdido: sólo reconcile materializa (en T2)
        $api = new WorkflowApi();
        $sig = new SignatureApi();
        $c = $this->makeComputer();
        $def = $this->defSingle($api, 'sig_evd_' . $this->suffix);
        $inst = $this->startSubmit($api, $def, $c);
        $dv1 = $this->recordVersion($sig, $c, 1, 'D');
        $this->approve($api, $inst, $this->uA1, $dv1, 'T1'); // decisión en T1 (reloj del ledger)
        $ledgerDate = $this->latestLedgerDate((int) $inst->getID(), WfHistoryEvent::EVENT_DECISION_RECORDED);
        $this->check('[EVENT-DATE] con listener perdido → 0 evidencias aún', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 0);

        $this->reconcile(); // materializa en T2 (ahora)
        $ev = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        $this->check('[EVENT-DATE] materializada tras reconcile', $ev !== null);
        $this->check('[EVENT-DATE] event_date == fecha ORIGINAL del ledger (T1)', $ev !== null && $ledgerDate !== '' && (string) $ev->fields['event_date'] === $ledgerDate);
        $this->check('[EVENT-DATE] materialized_at != event_date (T2 != T1)', $ev !== null && (string) $ev->fields['materialized_at'] !== '' && (string) $ev->fields['materialized_at'] !== (string) $ev->fields['event_date']);
        $this->check('[EVENT-DATE] date_creation >= event_date', $ev !== null && (string) $ev->fields['date_creation'] >= (string) $ev->fields['event_date']);
    }

    // ------------------------------------------------------------------ [EVIDENCE-REF] §2

    private function scenarioExplicitRefSameSecond(): void
    {
        $this->out->writeln('== [EVIDENCE-REF] v1/v2 mismo segundo; decisión referencia v1 (§2) ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $sig = new SignatureApi();
        $c = $this->makeComputer();
        $def = $this->defSingle($api, 'sig_ref_' . $this->suffix);
        $inst = $this->startSubmit($api, $def, $c);

        // v1 y v2 creadas en el MISMO segundo (misma sesión → mismo reloj → misma date_creation).
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $dv1 = $sig->recordDocumentVersion($this->snap($c, 1, 'v1'), true);
        $dv2 = $sig->recordDocumentVersion($this->snap($c, 2, 'v2'), true);
        $this->check('[EVIDENCE-REF] v1 y v2 con la MISMA date_creation (mismo segundo)', (string) $dv1->fields['date_creation'] === (string) $dv2->fields['date_creation'] && $dv1->contentHash() !== $dv2->contentHash());

        // La decisión referencia EXPLÍCITAMENTE v1.
        $this->approve($api, $inst, $this->uA1, $dv1, 'ref v1');
        $this->reconcile();
        $ev = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        $this->check('[EVIDENCE-REF] materializa v1 (nunca v2) pese al empate de fecha', $ev !== null && (int) $ev->fields['document_version'] === 1 && (string) $ev->fields['content_sha256'] === $dv1->contentHash());
        $this->check('[EVIDENCE-REF] NO usó v2', $ev !== null && (int) $ev->fields['document_versions_id'] === (int) $dv1->getID());
    }

    // ------------------------------------------------------------------ [FAIL-CLOSED]

    private function scenarioFailClosed(): void
    {
        $this->out->writeln('== [FAIL-CLOSED] sin evidence_ref → pending; verify fail-closed ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $c = $this->makeComputer();
        $def = $this->defSingle($api, 'sig_fc_' . $this->suffix);
        $inst = $this->startSubmit($api, $def, $c);
        // approve SIN evidence_ref (y sin versión) → nunca evidencia válida.
        $this->approve($api, $inst, $this->uA1, null, 'sin ref');
        $this->reconcile();
        $this->check('[FAIL-CLOSED] approve sin evidence_ref → 0 evidencias', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 0);

        // VerificationService fail-closed ante evidencia sin versión.
        $ev = (new EvidenceRecorder())->record([
            'idempotency_key' => 'fc-' . $this->suffix, 'subject_itemtype' => 'Computer', 'subject_items_id' => $c,
            'entities_id' => $this->entityA, 'document_versions_id' => 0, 'document_version' => 0, 'content_sha256' => '',
            'actor_users_id' => $this->uA1, 'decision' => ApprovalEvidence::DECISION_APPROVED, 'event_type' => ApprovalEvidence::EVENT_DECISION,
            'event_date' => date('Y-m-d H:i:s', $this->clock),
        ]);
        $this->applySession($this->uA1, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $res = $ev !== null ? (new SignatureApi())->verify((string) $ev->fields['verification_token']) : ['status' => 'x'];
        $this->check('[FAIL-CLOSED] verify sin versión → NUNCA valid (tampered)', $res['status'] === VerificationService::STATUS_TAMPERED);
    }

    // ------------------------------------------------------------------ [QUORUM] §4/§5

    private function scenarioQuorumAndContext(): void
    {
        $this->out->writeln('== [QUORUM] 3/3 → 3 APPROVED + 1 transición + contexto histórico ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $sig = new SignatureApi();
        $c = $this->makeComputer();
        $def = $this->defQuorum($api, 'sig_q3_' . $this->suffix, $this->g3, 3);
        $inst = $this->startSubmit($api, $def, $c);
        $dv1 = $this->recordVersion($sig, $c, 1, 'Q');
        foreach ([$this->uA1, $this->uA2, $this->uA3] as $u) {
            $this->approve($api, $inst, $u, $dv1, 'ok ' . $u);
        }
        $this->reconcile();
        $this->check('[QUORUM] TRES evidencias APPROVED', $this->countEvidence($c, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 3);
        $this->check('[QUORUM] UNA transición', $this->countEvidence($c, ApprovalEvidence::EVENT_TRANSITION, null) === 1);
        $this->check('[QUORUM] tres aprobadores distintos', count($this->distinctActors($c)) === 3);
        $ev = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        $ctx = $ev !== null ? json_decode((string) ($ev->fields['actor_context'] ?? ''), true) : null;
        $this->check('[QUORUM] contexto histórico (approver_kind=group, approver_ref=g3, statedefs_id)', is_array($ctx) && ($ctx['approver_kind'] ?? '') === Step::APPROVER_GROUP && (int) ($ctx['approver_ref'] ?? 0) === $this->g3 && (int) ($ctx['statedefs_id'] ?? 0) > 0);
    }

    // ------------------------------------------------------------------ [DELEGATION] §5

    private function scenarioDelegationContext(): void
    {
        $this->out->writeln('== [DELEGATION] delegated_from histórico conservado (§5) ==');
        $this->setListen(true);
        $api = new WorkflowApi();
        $sig = new SignatureApi();
        $c = $this->makeComputer();
        // Quórum 1 sobre gDel (contiene uA1); uDel es delegado de uA1.
        $def = $this->defQuorum($api, 'sig_del_' . $this->suffix, $this->gDel, 1);
        $inst = $this->startSubmit($api, $def, $c);
        $dv1 = $this->recordVersion($sig, $c, 1, 'DEL');
        $this->approve($api, $inst, $this->uDel, $dv1, 'ok delegado'); // uDel aprueba como delegado de uA1
        $this->reconcile();
        $ev = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        $ctx = $ev !== null ? json_decode((string) ($ev->fields['actor_context'] ?? ''), true) : null;
        $this->check('[DELEGATION] evidencia del delegado (actor=uDel)', $ev !== null && (int) $ev->fields['actor_users_id'] === $this->uDel);
        $this->check('[DELEGATION] delegated_from == uA1 (histórico)', is_array($ctx) && (int) ($ctx['delegated_from'] ?? 0) === $this->uA1);

        // Cambiar la membresía del grupo NO altera el contexto ya registrado.
        /** @var \DBmysql $DB */
        global $DB;
        $DB->delete(Group_User::getTable(), ['groups_id' => $this->gDel, 'users_id' => $this->uA1]);
        $ev2 = new ApprovalEvidence();
        $ev2->getFromDB((int) $ev->getID());
        $ctx2 = json_decode((string) ($ev2->fields['actor_context'] ?? ''), true);
        $this->check('[DELEGATION] tras cambiar el grupo, el contexto histórico se conserva', is_array($ctx2) && (int) ($ctx2['delegated_from'] ?? 0) === $this->uA1 && (int) ($ctx2['approver_ref'] ?? 0) === $this->gDel);
    }

    // ------------------------------------------------------------------ [QUEUE] §3

    private function scenarioQueueDurable(): void
    {
        $this->out->writeln('== [QUEUE] cola durable: pendiente no bloquea; idempotente; restart ==');
        $this->setListen(false); // sólo la cola/reconcile materializa
        $api = new WorkflowApi();
        $sig = new SignatureApi();

        // Sujeto A: approve SIN evidence_ref → quedará pending.
        $cA = $this->makeComputer();
        $instA = $this->startSubmit($api, $this->defSingle($api, 'sig_qA_' . $this->suffix), $cA);
        $this->approve($api, $instA, $this->uA1, null, 'pending A');
        $hidA = $this->latestLedgerId((int) $instA->getID(), WfHistoryEvent::EVENT_DECISION_RECORDED);

        // Sujeto B: approve CON evidence_ref → materializable.
        $cB = $this->makeComputer();
        $instB = $this->startSubmit($api, $this->defSingle($api, 'sig_qB_' . $this->suffix), $cB);
        $dvB = $this->recordVersion($sig, $cB, 1, 'B');
        $this->approve($api, $instB, $this->uA1, $dvB, 'ok B');

        $this->reconcile();
        $this->check('[QUEUE] pendiente (A) NO bloquea al posterior (B materializa)', $this->countEvidence($cB, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 1);
        $this->check('[QUEUE] A sigue en 0 (pending, sin evidencia válida)', $this->countEvidence($cA, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 0);
        $this->check('[QUEUE] fila de A en estado pending', $this->queueStatus($hidA) === ReconcileTask::STATUS_PENDING);
        $hidB = $this->latestLedgerId((int) $instB->getID(), WfHistoryEvent::EVENT_DECISION_RECORDED);
        $this->check('[QUEUE] fila de B en estado done', $this->queueStatus($hidB) === ReconcileTask::STATUS_DONE);

        // Idempotente + "restart": una nueva ReconcileService (estado en BD) no duplica ni pierde.
        (new ReconcileService())->run();
        (new ReconcileService())->run();
        $this->check('[QUEUE] idempotente/restart: B sigue 1 evidencia', $this->countEvidence($cB, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 1);
        $this->check('[QUEUE] idempotente/restart: A sigue pending (no perdido)', $this->countEvidence($cA, ApprovalEvidence::EVENT_DECISION, ApprovalEvidence::DECISION_APPROVED) === 0 && $this->queueStatus($hidA) === ReconcileTask::STATUS_PENDING);
    }

    // ------------------------------------------------------------------ [INVALIDATE] §4/§5

    private function scenarioInvalidation(): void
    {
        $this->out->writeln('== [INVALIDATE] idempotency obligatoria + actor durable + exacta ==');
        $this->setListen(false); // reconstrucción por reconcile (listener perdido)
        $api = new WorkflowApi();
        $sig = new SignatureApi();

        // (A) idempotency_key obligatoria: sin clave → NO muta.
        $c = $this->makeComputer();
        $inst = $this->startSubmit($api, $this->defSingle($api, 'sig_invA_' . $this->suffix), $c);
        $dv1 = $this->recordVersion($sig, $c, 1, 'A');
        $this->approve($api, $inst, $this->uA1, $dv1, 'A');
        $this->reconcile();
        $e1 = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        $inst->getFromDB((int) $inst->getID());
        $stateBefore = (int) $inst->fields['current_statedefs_id'];
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => WorkflowDef::RIGHT_ACT]);
        $rNoKey = $api->invalidateApprovals((int) $inst->getID(), 'sin clave', []); // sin idempotency_key
        $inst->getFromDB((int) $inst->getID());
        $this->check('[INVALIDATE] sin idempotency_key → ERROR y SIN mutación', !$rNoKey->success && (int) $inst->fields['current_statedefs_id'] === $stateBefore);

        // (B) actor DURABLE: invalidar (actor=uReq) con listener perdido → reconcile reconstruye actor.
        $rOk = $api->invalidateApprovals((int) $inst->getID(), 'cambio', ['idempotency_key' => 'kA-' . $this->suffix, 'document_version' => 1]);
        $this->check('[INVALIDATE] con clave → OK', $rOk->success);
        // misma clave dos veces → una sola invalidación (idempotente en el motor).
        $api->invalidateApprovals((int) $inst->getID(), 'cambio', ['idempotency_key' => 'kA-' . $this->suffix, 'document_version' => 1]);
        $this->reconcile();
        $inv = $this->latestEvidence2($c, ApprovalEvidence::EVENT_INVALIDATION);
        $this->check('[INVALIDATE] evidencia de invalidación materializada', $inv !== null && (int) $inv->fields['references_evidences_id'] === (int) ($e1?->getID() ?? -1));
        $this->check('[INVALIDATE] actor DURABLE reconstruido (uReq) aunque el listener no corrió', $inv !== null && (int) $inv->fields['actor_users_id'] === $this->uReq);
        $this->check('[INVALIDATE] e1 → invalidated', $e1 !== null && $this->verifyAs($this->uReq, $this->entityA, (string) $e1->fields['verification_token']) === VerificationService::STATUS_INVALIDATED);

        // (C) exacta v1→v2: nueva versión + reaprobar → v1 invalidated / v2 valid.
        $dv2 = $this->recordVersion($sig, $c, 2, 'v2');
        $inst->getFromDB((int) $inst->getID());
        $this->applySession($this->uReq, [$this->entityA], ['plugin_companyworkflow' => READ]);
        $api->transition($inst, 'submit', []);
        $this->approve($api, $inst, $this->uA1, $dv2, 'v2');
        $this->reconcile();
        $eV2 = $this->latestEvidence($c, ApprovalEvidence::DECISION_APPROVED);
        $this->check('[INVALIDATE] v2 valid; v1 invalidated (exacta)', $eV2 !== null && (int) $eV2->getID() !== (int) ($e1?->getID() ?? 0)
            && $this->verifyAs($this->uReq, $this->entityA, (string) $eV2->fields['verification_token']) === VerificationService::STATUS_VALID
            && $this->verifyAs($this->uReq, $this->entityA, (string) $e1->fields['verification_token']) === VerificationService::STATUS_INVALIDATED);
    }

    // ------------------------------------------------------------------ helpers

    private function reconcile(): void
    {
        (new ReconcileService())->run();
    }

    /** Verifica un token bajo una sesión concreta (login + ACL + entidad) y devuelve el estado. */
    private function verifyAs(int $user, int $entity, string $token): string
    {
        $this->applySession($user, [$entity], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        return (string) ((new SignatureApi())->verify($token)['status'] ?? '');
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
    private function distinctActors(int $subjectId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $seen = [];
        foreach ($DB->request(['SELECT' => 'actor_users_id', 'FROM' => ApprovalEvidence::getTable(), 'WHERE' => ['subject_itemtype' => 'Computer', 'subject_items_id' => $subjectId, 'event_type' => ApprovalEvidence::EVENT_DECISION, 'decision' => ApprovalEvidence::DECISION_APPROVED]]) as $row) {
            $seen[(int) $row['actor_users_id']] = true;
        }
        return array_keys($seen);
    }

    private function latestEvidence(int $subjectId, string $decision): ?ApprovalEvidence
    {
        return $this->latestBy(['subject_itemtype' => 'Computer', 'subject_items_id' => $subjectId, 'decision' => $decision, 'event_type' => ApprovalEvidence::EVENT_DECISION]);
    }

    private function latestEvidence2(int $subjectId, string $eventType): ?ApprovalEvidence
    {
        return $this->latestBy(['subject_itemtype' => 'Computer', 'subject_items_id' => $subjectId, 'event_type' => $eventType]);
    }

    /** @param array<string,mixed> $where */
    private function latestBy(array $where): ?ApprovalEvidence
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => ApprovalEvidence::getTable(), 'WHERE' => $where, 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $row) {
            $m = new ApprovalEvidence();
            if ($m->getFromDB((int) $row['id'])) {
                return $m;
            }
        }
        return null;
    }

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

    private function queueStatus(int $historyId): string
    {
        $m = new ReconcileTask();
        return $m->getFromDBByCrit(['workflow_history_id' => $historyId]) ? (string) $m->fields['status'] : '';
    }

    private function latestLedgerId(int $instanceId, string $event): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $id = 0;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => WfHistoryEvent::getTable(), 'WHERE' => ['instances_id' => $instanceId, 'event' => $event], 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $row) {
            $id = (int) $row['id'];
        }
        return $id;
    }

    private function latestLedgerDate(int $instanceId, string $event): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $d = '';
        foreach ($DB->request(['SELECT' => 'date', 'FROM' => WfHistoryEvent::getTable(), 'WHERE' => ['instances_id' => $instanceId, 'event' => $event], 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $row) {
            $d = (string) $row['date'];
        }
        return $d;
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
        $this->clock += 5;
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
