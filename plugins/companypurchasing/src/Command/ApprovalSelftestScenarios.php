<?php

/**
 * Escenarios P2D-2 (circuito de aprobación) del selftest OBLIGATORIO `plugins:companypurchasing:selftest`.
 *
 * Es un TRAIT del mismo `SelftestCommand` (NO un selftest paralelo que pueda omitirse): el comando los
 * ejecuta siempre, en el mismo proceso y con el mismo fail-closed. Usa los helpers del comando
 * (`check`, `applySession`, `makeUser`, `makeGroup`, `makeSupplier`, `runParallel`, `throws`, …).
 *
 * Cobertura:
 *   [P2D2-PERSIST]  tablas/columnas nuevas; sin `is_selected`; literales puros == constantes del motor.
 *   [DEFINITION]    publicar fail-closed (sin grupos / sin MANAGE_CONFIG); condición evidence_bound.
 *   [FLOW]          solicitud → jefe (REQUEST_SCOPE) → Compras (cotización) → Gerencia → APPROVED;
 *                   evidence_ref EXACTA; cotización nueva NO invalida al jefe; PDF por etapa.
 *   [REJECT] [RETURN] [QUORUM] [QUORUM-CONC] [DELEGATION]
 *   [QUOTE-CONC]    selección concurrente (procesos reales): una sola seleccionada.
 *   [SCOPE-REQUEST] cantidad cambia tras el jefe → nueva versión → invalida/reabre al jefe → reaprobación.
 *   [SCOPE-COMM]    precio final cambia tras Gerencia → invalida desde COMMERCIAL (el jefe sigue válido).
 *   [DOCVER]        document_version monotónica; concurrente (distinto contenido → contiguas; igual → reuso).
 *   [CRASH-1]       caída tras recordDocumentVersion y antes de transition → reintento sin duplicar.
 *   [CRASH-2]       caída tras transition y antes de la proyección → reconcile corrige (motor gana).
 *   [PDF] [PDF-FAIL] fallo de PDF: aprobación/evidencia permanecen; retry → ready.
 *   [MULTI-ENT] [ACL] [XBRANCH] [MONEY-P2D2]
 *   Pasada de integridad de la saga:
 *   [INTEGRITY-DIRTY]   (1) cambio sustantivo + invalidación fallida ⇒ marca DURABLE; nunca "limpia"; retry
 *                       la resuelve; decide() repara o falla cerrado; prioridad REQUEST_SCOPE.
 *   [REOPEN-CAPABILITY] (1) sin RIGHT_ACT no se aceptan cambios que exigirían reabrir.
 *   [EXPECTED-STATE]    (2) etapa obligatoria; mismo usuario Compras+Finanzas: reintento ⇒ stage_changed.
 *   [POLICY]            (3) política pinneada: R1 conserva A, R2 usa B.
 *   [CRASH-0] [RECONCILE-CURSOR] [SUBMIT-PRECHECK] (5)
 *   [EVIDENCE-TAMPER] [SUPPLIER-MOVED] (6)
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Command;

use Document;
use Group_User;
use Profile_User;
use GlpiPlugin\Companypurchasing\Model\DocVersion;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Quote;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Service\ApprovalOrchestrator;
use GlpiPlugin\Companypurchasing\Service\DocumentVersionAllocator;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;
use GlpiPlugin\Companypurchasing\Service\PurchasingWorkflow;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use GlpiPlugin\Companypurchasing\Service\SignatureGateway;
use GlpiPlugin\Companypurchasing\Service\WorkflowGateway;
use GlpiPlugin\Companysignature\Api\SignatureApi;
use GlpiPlugin\Companysignature\Model\ApprovalEvidence;
use GlpiPlugin\Companysignature\Model\DocumentVersion;
use GlpiPlugin\Companysignature\Model\ReconcileTask;
use GlpiPlugin\Companysignature\Service\ReconcileService;
use GlpiPlugin\Companysignature\Service\VerificationService;
use GlpiPlugin\Companyworkflow\Api\WorkflowApi;
use GlpiPlugin\Companyworkflow\Model\Assignment;
use GlpiPlugin\Companyworkflow\Model\Delegation;
use GlpiPlugin\Companyworkflow\Model\HistoryEvent;
use GlpiPlugin\Companyworkflow\Model\Instance;
use GlpiPlugin\Companyworkflow\Model\StateDef;
use GlpiPlugin\Companyworkflow\Model\Step;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;
use GlpiPlugin\Companyworkflow\Service\TransitionResult;

trait ApprovalSelftestScenarios
{
    private int $uHead1 = 0;
    private int $uHead2 = 0;
    private int $uBuyer = 0;
    private int $uFin = 0;
    private int $uDelegate = 0;
    private int $gHead = 0;
    private int $gBuy = 0;
    private int $gFin = 0;
    private int $supA = 0;
    private int $supA2 = 0;
    private int $supB = 0;
    /** @var array<int,int> */
    private array $createdDocuments = [];
    /** @var array<string,mixed> */
    private array $savedConfig = [];

    // ================================================================ orquestación de escenarios

    private function runApprovalScenarios(): void
    {
        $this->out->writeln('== [P2D-2] circuito de aprobación sobre companyworkflow + companysignature ==');
        if (!class_exists(WorkflowApi::class) || !class_exists(SignatureApi::class)) {
            $this->check('[P2D-2] companyworkflow + companysignature disponibles (obligatorio)', false);
            return;
        }
        $this->p2d2Setup();
        try {
            $this->scenarioP2d2Persist();
            $this->scenarioDefinition();
            $this->scenarioFlowHappy();
            $this->scenarioReject();
            $this->scenarioReturn();
            $this->scenarioDelegation();
            $this->scenarioQuoteConcurrency();
            $this->scenarioScopeRequestInvalidation();
            $this->scenarioScopeCommercialInvalidation();
            $this->scenarioDocVersionConcurrency();
            $this->scenarioCrashBeforeTransition();
            $this->scenarioCrashAfterTransition();
            $this->scenarioPdfFailure();
            $this->scenarioApprovalMultiEntityAcl();
            $this->scenarioCrossBranchAndMoney();
            // Pasada de integridad de la saga (6 puntos).
            $this->scenarioIntegrityDirtyDurable();
            $this->scenarioReopenCapability();
            $this->scenarioExpectedStateMandatory();
            $this->scenarioPolicyPinned();
            $this->scenarioSubmitCrashBeforeStart();
            $this->scenarioReconcileCursor();
            $this->scenarioEvidenceRefTampered();
            $this->scenarioSupplierMovedAfterSelection();
            // P2D-3: upgrade 0.3.0 → 0.4.0 sobre los datos P2D-2 recién creados; luego recepción + outbox.
            $this->scenarioUpgradeP2d3();
            $this->runReceivingScenarios();
            // Al final: republica con quórum 2 (las instancias ya creadas conservan su versión).
            $this->scenarioQuorum();
        } finally {
            $this->p2d2Cleanup();
        }
    }

    // ================================================================ fixtures

    private function p2d2Setup(): void
    {
        $this->asAdmin();
        foreach (['workflow_code', 'approver_group_area_head', 'approver_group_purchasing', 'approver_group_finance',
                  'quorum_area_head', 'quorum_purchasing', 'quorum_finance', 'sync_on_workflow_events',
                  'pdf_stages', 'quote_states', 'reconcile_cursor'] as $k) {
            $this->savedConfig[$k] = PluginConfig::get($k);
        }
        $this->uHead1    = $this->makeActor('head1');
        $this->uHead2    = $this->makeActor('head2');
        $this->uBuyer    = $this->makeActor('buyer');
        $this->uFin      = $this->makeActor('fin');
        $this->uDelegate = $this->makeActor('delegate');
        $this->gHead = $this->makeGroup('head', $this->entityA);
        $this->gBuy  = $this->makeGroup('buy', $this->entityA);
        $this->gFin  = $this->makeGroup('fin', $this->entityA);
        foreach ([[$this->gHead, $this->uHead1], [$this->gHead, $this->uHead2], [$this->gBuy, $this->uBuyer], [$this->gFin, $this->uFin]] as [$g, $u]) {
            (new Group_User())->add(['groups_id' => $g, 'users_id' => $u]);
        }
        // Delegación uFin → uDelegate (vigente; cualquier definición/entidad).
        (new Delegation())->add([
            'users_id_from' => $this->uFin, 'users_id_to' => $this->uDelegate, 'workflowdefs_id' => 0, 'entities_id' => 0,
            'date_start' => date('Y-m-d H:i:s', time() - 86400), 'date_end' => date('Y-m-d H:i:s', time() + 86400 * 30),
            'reason' => 'cp_selftest', 'is_active' => 1,
        ]);
        $this->supA  = $this->makeSupplier('CP-SUP-A-' . $this->suffix, $this->entityA, false);
        $this->supA2 = $this->makeSupplier('CP-SUP-A2-' . $this->suffix, $this->entityA, false);
        $this->supB  = $this->makeSupplier('CP-SUP-B-' . $this->suffix, $this->entityB, false);

        \Config::setConfigurationValues(PluginConfig::CONTEXT, [
            'workflow_code'             => 'cpur_st_' . $this->suffix,
            'approver_group_area_head'  => (string) $this->gHead,
            'approver_group_purchasing' => (string) $this->gBuy,
            'approver_group_finance'    => (string) $this->gFin,
            'quorum_area_head'          => '1',
            'quorum_purchasing'         => '1',
            'quorum_finance'            => '1',
            'sync_on_workflow_events'   => '1',
        ]);
        $this->check('[P2D-2 SETUP] aprobadores/grupos/proveedores/delegación',
            min($this->uHead1, $this->uHead2, $this->uBuyer, $this->uFin, $this->uDelegate, $this->gHead, $this->gBuy, $this->gFin, $this->supA, $this->supB) > 0);
    }

    /** Usuario con perfil en la entidad A (el motor sólo admite aprobadores que pueden actuar en la entidad). */
    private function makeActor(string $tag): int
    {
        $id = $this->makeUser($tag);
        if ($id > 0) {
            (new Profile_User())->add(['users_id' => $id, 'profiles_id' => 1, 'entities_id' => $this->entityA, 'is_recursive' => 0]);
        }
        return $id;
    }

    // ---- sesiones (la AUTORIZACIÓN de cada decisión la da el motor: grupo + quórum) ----

    private function asAdmin(): void
    {
        $this->applySession(2, [0, $this->entityA, $this->entityB], [
            'plugin_companypurchasing' => self::FULL | Request::RIGHT_MANAGE_PURCHASING,
            'plugin_companyworkflow'   => ALLSTANDARDRIGHT,
            'plugin_companysignature'  => ALLSTANDARDRIGHT,
            'document' => ALLSTANDARDRIGHT, 'supplier' => ALLSTANDARDRIGHT, 'group' => ALLSTANDARDRIGHT, 'user' => ALLSTANDARDRIGHT,
        ], 1);
    }

    private function asRequester(): void
    {
        // Mínimo privilegio: el solicitante crea/edita/ve lo propio y sólo LEE en el motor para enviar;
        // NO administra configuración ni registra evidencia.
        $this->applySession($this->uOwner, [$this->entityA], [
            'plugin_companypurchasing' => READ | Request::RIGHT_CREATE_REQUEST | Request::RIGHT_VIEW_OWN | Request::RIGHT_EDIT_DRAFT,
            'plugin_companyworkflow'   => READ,
        ]);
    }

    /** @param array<int>|null $entities */
    private function asApprover(int $user, ?array $entities = null): void
    {
        $this->applySession($user, $entities ?? [$this->entityA], [
            'plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY,
            'plugin_companyworkflow'   => READ | WorkflowDef::RIGHT_ACT,
            'plugin_companysignature'  => ALLSTANDARDRIGHT,
        ]);
    }

    /** @param array<int>|null $entities */
    private function asBuyer(int $user, ?array $entities = null, bool $manage = true): void
    {
        $this->applySession($user, $entities ?? [$this->entityA], [
            'plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY | ($manage ? Request::RIGHT_MANAGE_PURCHASING : 0),
            'plugin_companyworkflow'   => READ | WorkflowDef::RIGHT_ACT,
            'plugin_companysignature'  => ALLSTANDARDRIGHT,
        ]);
    }

    // ---- helpers de dominio ----

    private function orch(): ApprovalOrchestrator
    {
        return new ApprovalOrchestrator();
    }

    /** Crea (como solicitante) una solicitud con 2 líneas y la ENVÍA al circuito. */
    private function newSubmitted(string $tag): int
    {
        $this->asRequester();
        $rm = new RequestManager();
        $id = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'p2d2-' . $tag . '-' . $this->suffix, 'category' => 'IT']);
        $rm->addLine($id, ['description' => 'Notebook', 'quantity' => '2', 'estimated_unit_price' => '1500', 'is_inventoriable' => 1]);
        $rm->addLine($id, ['description' => 'Mouse', 'quantity' => '1', 'estimated_unit_price' => '2500', 'is_inventoriable' => 0]);
        $this->orch()->submit($id, 'envío ' . $tag);
        return $id;
    }

    /** @return array<int,int> ids de línea en orden (line_no, id) */
    private function lineIds(int $reqId): array
    {
        return array_map(static fn ($it): int => (int) $it->getID(), (new RequestManager())->loadItems($reqId));
    }

    /**
     * Cotización (como Compras) que precia TODAS las líneas.
     * @param array<int,string> $prices  precio por posición de línea
     */
    private function makeQuote(int $reqId, int $supplier, array $prices, string $disc = '0', string $tax = '0', string $freight = '0'): int
    {
        $this->asBuyer($this->uBuyer);
        $lines = [];
        foreach ($this->lineIds($reqId) as $i => $lineId) {
            $lines[] = ['items_id' => $lineId, 'final_unit_price' => $prices[$i] ?? '1000'];
        }
        return $this->orch()->createQuote($reqId, [
            'suppliers_id' => $supplier, 'reference' => 'Q-' . $this->suffix . '-' . random_int(100, 999),
            'discounts' => $disc, 'taxes' => $tax, 'freight' => $freight, 'lines' => $lines,
        ]);
    }

    /** Aprueba declarando la etapa (por defecto: la que el actor "ve" ahora). */
    private function approveAs(int $user, int $reqId, bool $buyer = false, ?string $stage = null): array
    {
        $buyer ? $this->asBuyer($user) : $this->asApprover($user);
        return $this->orch()->decide($reqId, 'approve', $stage ?? $this->wfState($reqId), 'ok ' . $user);
    }

    /** Lleva una solicitud recién enviada hasta PENDING_FINANCE (jefe aprueba; Compras cotiza+selecciona+aprueba). */
    private function toFinance(int $reqId): int
    {
        $this->approveAs($this->uHead1, $reqId);
        $q = $this->makeQuote($reqId, $this->supA, ['1400', '2300'], '300', '550', '100');
        $this->orch()->selectQuote($reqId, $q);
        $this->approveAs($this->uBuyer, $reqId, true);
        return $q;
    }

    private function wfState(int $reqId): string
    {
        $req = new Request();
        $req->getFromDB($reqId);
        $gw = new WorkflowGateway();
        $inst = $gw->loadInstance((int) $req->fields['workflow_instances_id']);
        return $inst !== null ? $gw->stateCode($inst) : '';
    }

    private function domainState(int $reqId): string
    {
        $req = new Request();
        return $req->getFromDB($reqId) ? (string) $req->fields['domain_state'] : '';
    }

    private function instanceId(int $reqId): int
    {
        $req = new Request();
        return $req->getFromDB($reqId) ? (int) $req->fields['workflow_instances_id'] : 0;
    }

    /** @return array<int,array<string,mixed>> filas del ledger del motor */
    private function ledger(int $reqId, ?string $event = null): array
    {
        $rows = (new WorkflowApi())->history(['instances_id' => $this->instanceId($reqId)]);
        if ($event === null) {
            return $rows;
        }
        return array_values(array_filter($rows, static fn (array $r): bool => (string) $r['event'] === $event));
    }

    /** @return array<string,mixed> */
    private function metaOf(array $row): array
    {
        $m = json_decode((string) ($row['meta_json'] ?? ''), true);
        return is_array($m) ? $m : [];
    }

    private function countTransitionsTo(int $reqId, string $to): int
    {
        return count(array_filter($this->ledger($reqId, HistoryEvent::EVENT_TRANSITIONED), static fn (array $r): bool => (string) $r['to_code'] === $to));
    }

    private function sigVersions(int $reqId): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request(['FROM' => DocumentVersion::getTable(), 'WHERE' => ['subject_itemtype' => Request::class, 'subject_items_id' => $reqId]]) as $ignored) {
            $n++;
        }
        return $n;
    }

    private function ledgerVersions(int $reqId): int
    {
        return count((new DocumentVersionAllocator())->all($reqId));
    }

    private function docVersionRow(int $reqId, int $version): ?array
    {
        return (new DocumentVersionAllocator())->findByVersion($reqId, $version);
    }

    // ================================================================ [P2D2-PERSIST]

    private function scenarioP2d2Persist(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [P2D2-PERSIST] esquema P2D-2 + contrato con el motor ==');
        foreach (['quotes', 'quote_items', 'doc_versions', 'docseq', 'policies', 'integrity'] as $t) {
            $this->check("[P2D2-PERSIST] tabla glpi_plugin_companypurchasing_{$t}", $this->tableExistsLive("glpi_plugin_companypurchasing_{$t}"));
        }
        foreach (['quotes_id_selected', 'workflow_lock_version', 'workflow_synced_at', 'policies_id', 'integrity_state'] as $c) {
            $this->check("[P2D2-PERSIST] requests.{$c}", $DB->fieldExists(Request::getTable(), $c, false));
        }
        $this->check('[P2D2-PERSIST] quotes SIN is_selected (única fuente: requests.quotes_id_selected)', !$DB->fieldExists(Quote::getTable(), 'is_selected', false));
        $ct = new \CronTask();
        $this->check('[P2D2-PERSIST] Acción automática NATIVA registrada (reconcileprojection)',
            $ct->getFromDBbyName(\GlpiPlugin\Companypurchasing\Model\ProjectionTask::class, \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME)
            && is_array(\GlpiPlugin\Companypurchasing\Model\ProjectionTask::cronInfo(\GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME)));
        // Los literales PUROS de PurchasingWorkflow deben coincidir con las constantes REALES del motor.
        $this->check('[P2D2-PERSIST] literales == constantes del motor (StateDef/Step/WorkflowDef/HistoryEvent/Assignment)',
            PurchasingWorkflow::WF_KIND_INITIAL === StateDef::KIND_INITIAL
            && PurchasingWorkflow::WF_KIND_INTERMEDIATE === StateDef::KIND_INTERMEDIATE
            && PurchasingWorkflow::WF_KIND_FINAL === StateDef::KIND_FINAL
            && PurchasingWorkflow::WF_QUORUM_COUNT === Step::QUORUM_COUNT
            && PurchasingWorkflow::WF_APPROVER_GROUP === Step::APPROVER_GROUP
            && PurchasingWorkflow::WF_RIGHT_ACT === WorkflowDef::RIGHT_ACT
            && PurchasingWorkflow::EV_STARTED === HistoryEvent::EVENT_STARTED
            && PurchasingWorkflow::EV_TRANSITIONED === HistoryEvent::EVENT_TRANSITIONED
            && PurchasingWorkflow::EV_INVALIDATED === HistoryEvent::EVENT_APPROVAL_INVALIDATED
            && PurchasingWorkflow::EV_DECISION === HistoryEvent::EVENT_DECISION_RECORDED
            && PurchasingWorkflow::DECISION_APPROVED === Assignment::DECISION_APPROVED);
        $this->check('[P2D2-PERSIST] códigos de resultado del motor (recorded/duplicate)', TransitionResult::RECORDED === 'recorded' && TransitionResult::DUPLICATE === 'duplicate');
    }

    // ================================================================ [DEFINITION]

    private function scenarioDefinition(): void
    {
        $this->out->writeln('== [DEFINITION] publicación desde configuración (fail-closed) ==');
        // Sin MANAGE_CONFIG (solicitante ni aprobador) → denegado.
        $this->asRequester();
        $deniedReq = $this->throws(fn () => $this->orch()->publishDefinition());
        $this->asApprover($this->uHead1);
        $deniedAppr = $this->throws(fn () => $this->orch()->publishDefinition());
        $this->check('[DEFINITION] publicar sin MANAGE_CONFIG → denegado', $deniedReq && $deniedAppr);

        // Sin grupo configurado → fail-closed (no publica).
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['approver_group_finance' => '0']);
        $this->check('[DEFINITION] grupo aprobador sin configurar → fail-closed', $this->throws(fn () => $this->orch()->publishDefinition()));
        $this->check('[DEFINITION] … y NO quedó definición activa', (new WorkflowGateway())->activeDefinition(PluginConfig::workflowCode()) === null);
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['approver_group_finance' => (string) $this->gFin]);

        // Enviar sin definición publicada → fail-closed, la instancia NO se crea.
        $this->asRequester();
        $rm = new RequestManager();
        $orphan = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'p2d2-nodef-' . $this->suffix]);
        $rm->addLine($orphan, ['description' => 'x', 'quantity' => '1', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0]);
        $this->check('[DEFINITION] submit sin definición publicada → fail-closed', $this->throws(fn () => $this->orch()->submit($orphan)));
        $this->check('[DEFINITION] … sin instancia de workflow', (new WorkflowGateway())->findInstance(Request::class, $orphan) === null);
        $ro = new Request();
        $ro->getFromDB($orphan);
        $this->check('[SUBMIT-PRECHECK] sin definición la solicitud SIGUE en DRAFT (sin número, sin REQUEST_SUBMITTED)',
            (string) $ro->fields['domain_state'] === Request::STATE_DRAFT && (int) ($ro->fields['number_seq'] ?? 0) === 0
            && $this->countEvents($orphan, PurchasingEvent::EV_REQUEST_SUBMITTED) === 0);

        $this->asAdmin();
        $defId = $this->orch()->publishDefinition();
        $def = (new WorkflowGateway())->activeDefinition(PluginConfig::workflowCode());
        $this->check('[DEFINITION] publicada y activa (versión del motor)', $defId > 0 && $def !== null && (int) $def->getID() === $defId);
        $this->check('[DEFINITION] evento workflow.definition_published', $this->countEvents(0, PurchasingEvent::EV_DEFINITION_PUBLISHED) >= 1);

        // Reanudación: el envío que falló sin definición ahora completa (idempotente).
        $this->asRequester();
        $state = $this->orch()->submit($orphan);
        $this->check('[DEFINITION] reintento de submit tras publicar → PENDING_AREA_HEAD', $state === PurchasingWorkflow::S_PENDING_AREA_HEAD);

        // Defensa en profundidad: aprobar por el motor SIN evidencia (UI genérica) → CONDITION_FAILED.
        $this->asApprover($this->uHead1);
        $inst = (new WorkflowGateway())->loadInstance($this->instanceId($orphan));
        $res = $inst !== null ? (new WorkflowApi())->transition($inst, 'approve', ['comment' => 'sin evidencia']) : null;
        $this->check('[DEFINITION] approve directo sin evidence_bound → rechazado por la condición del motor', $res !== null && !$res->success && $res->code === TransitionResult::CONDITION_FAILED);
        $this->check('[DEFINITION] … estado intacto', $this->wfState($orphan) === PurchasingWorkflow::S_PENDING_AREA_HEAD);
    }

    // ================================================================ [FLOW]

    private function scenarioFlowHappy(): void
    {
        $this->out->writeln('== [FLOW] jefe → Compras → Gerencia → APPROVED (evidencia por scope) ==');
        $req = $this->newSubmitted('flow');
        $this->check('[FLOW] submit → PENDING_AREA_HEAD (motor) y domain_state proyectado', $this->wfState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD && $this->domainState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD);
        $r = new Request();
        $r->getFromDB($req);
        $this->check('[FLOW] número asignado + scopes pinneados (P2D-1) antes del motor', (int) $r->fields['number_seq'] > 0 && (int) $r->fields['scopes_version'] > 0);

        // Jefe aprueba REQUEST_SCOPE.
        $a = $this->approveAs($this->uHead1, $req);
        $this->check('[FLOW] jefe aprueba → PURCHASING', ($a['state'] ?? '') === PurchasingWorkflow::S_PURCHASING && $this->wfState($req) === PurchasingWorkflow::S_PURCHASING);
        $this->check('[FLOW] REQUEST_SCOPE en document_version 1 (asignada por el dominio)', (int) $a['document_version'] === 1 && ($a['scope'] ?? '') === 'REQUEST_SCOPE');

        // evidence_ref EXACTA en el ledger del motor == versión registrada en Firma == ledger de dominio.
        $dec = $this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED);
        $ref = $dec !== [] ? ($this->metaOf(end($dec))['evidence_ref'] ?? null) : null;
        $dv = new DocumentVersion();
        $dvOk = is_array($ref) && $dv->getFromDB((int) ($ref['document_versions_id'] ?? 0));
        $row = $this->docVersionRow($req, 1);
        $this->check('[EVIDENCE-REF] evidence_ref = {document_versions_id, document_version, content_sha256} exacta',
            $dvOk && array_keys($ref) === ['document_versions_id', 'document_version', 'content_sha256']
            && (int) $ref['document_version'] === $dv->versionNumber() && (string) $ref['content_sha256'] === $dv->contentHash()
            && (string) $dv->fields['subject_itemtype'] === Request::class && (int) $dv->fields['subject_items_id'] === $req);
        $this->check('[EVIDENCE-REF] ledger de dominio enlaza la MISMA identidad de Firma',
            $row !== null && $dvOk && (int) $row['document_versions_id'] === (int) $dv->getID() && (string) $row['content_sha256'] === $dv->contentHash() && (string) $row['scope_key'] === 'REQUEST_SCOPE');
        $snap = $dvOk ? json_decode((string) $dv->fields['canonical_snapshot'], true) : null;
        $payload = is_array($snap) ? ($snap['payload'] ?? []) : [];
        $this->check('[FLOW] REQUEST_SCOPE NO incluye proveedor/cotización/precio final', is_array($payload) && !array_key_exists('selected_quote', $payload) && !array_key_exists('final_prices', $payload) && array_key_exists('lines', $payload));
        $this->check('[PDF] PDF de la etapa del jefe compuesto (ready)', ($a['pdf_status'] ?? '') === DocumentVersionAllocator::PDF_READY && (string) ($row['pdf_status'] ?? '') === DocumentVersionAllocator::PDF_READY);

        // Compras cotiza (2 cotizaciones, N adjuntos) y selecciona: NO invalida al jefe.
        $q1 = $this->makeQuote($req, $this->supA, ['1400', '2300'], '300', '550', '100');
        $q2 = $this->makeQuote($req, $this->supA2, ['1450', '2400']);
        $d1 = $this->makeDocument('q1a', $this->entityA);
        $d2 = $this->makeDocument('q1b', $this->entityA);
        $this->asBuyer($this->uBuyer);
        $o = $this->orch();
        $att = $o->attachQuoteDocument($q1, $d1) && $o->attachQuoteDocument($q1, $d2);
        $again = $o->attachQuoteDocument($q1, $d1);
        $this->check('[QUOTE] N adjuntos (Document_Item nativo) por cotización; re-adjuntar idempotente', $att && !$again && $o->quotes()->attachments($q1) === [min($d1, $d2), max($d1, $d2)]);
        $sel = $o->selectQuote($req, $q1);
        $r->getFromDB($req);
        $this->check('[QUOTE] selección = requests.quotes_id_selected', (int) $r->fields['quotes_id_selected'] === $q1 && $q2 > 0);
        $this->check('[SCOPE-KEEP] cotizar/seleccionar tras el jefe NO invalida REQUEST_SCOPE', ($sel['invalidated'] ?? []) === [] && $this->countEvents($req, PurchasingEvent::EV_SCOPE_INVALIDATED) === 0 && $this->wfState($req) === PurchasingWorkflow::S_PURCHASING);

        // Compras aprueba COMMERCIAL_FINANCIAL_SCOPE; Gerencia aprueba el MISMO contenido (reutiliza la versión).
        $b = $this->approveAs($this->uBuyer, $req, true);
        $this->check('[FLOW] Compras aprueba → PENDING_FINANCE con COMMERCIAL en v2', ($b['state'] ?? '') === PurchasingWorkflow::S_PENDING_FINANCE && (int) $b['document_version'] === 2 && ($b['scope'] ?? '') === 'COMMERCIAL_FINANCIAL_SCOPE');
        $before = $this->ledgerVersions($req);
        $f = $this->approveAs($this->uFin, $req);
        $this->check('[FLOW] Gerencia aprueba → APPROVED (intermedio; instancia abierta)', ($f['state'] ?? '') === PurchasingWorkflow::S_APPROVED && $this->domainState($req) === PurchasingWorkflow::S_APPROVED);
        $this->check('[DOCVER] Gerencia reutiliza v2 (mismo contenido): sin versión nueva', (int) $f['document_version'] === 2 && $this->ledgerVersions($req) === $before);
        $this->check('[PDF] PDF de la etapa financiera compuesto (ready)', ($f['pdf_status'] ?? '') === DocumentVersionAllocator::PDF_READY);

        // Snapshot comercial EXACTO: (2×1400 + 1×2300) − 300 + 550 + 100 = 5450 (PYG, strings, sin float).
        $dv2 = new DocumentVersion();
        $snap2 = $dv2->getFromDB((int) $f['document_versions_id']) ? json_decode((string) $dv2->fields['canonical_snapshot'], true) : null;
        $p2 = is_array($snap2) ? ($snap2['payload'] ?? []) : [];
        $this->check('[MONEY-P2D2] COMMERCIAL: total final exacto 5450 como string; precios por línea', is_array($p2)
            && ($p2['total'] ?? null) === '5450' && ($p2['discounts'] ?? null) === '300' && ($p2['taxes'] ?? null) === '550' && ($p2['freight'] ?? null) === '100'
            && ($p2['final_prices'][0]['final_unit_price'] ?? null) === '1400' && ($p2['final_prices'][0]['line_total'] ?? null) === '2800'
            && (int) ($p2['suppliers_id_selected'] ?? 0) === $this->supA && (int) ($p2['selected_quote']['id'] ?? 0) === $q1);

        // Monotonía y unicidad de versiones a lo largo del flujo.
        $vers = array_map(static fn (array $x): int => (int) $x['document_version'], (new DocumentVersionAllocator())->all($req));
        $this->check('[DOCVER] versiones estrictamente crecientes y únicas', $vers === range(1, count($vers)));
        $this->check('[AUDIT] eventos de negocio: submitted + checkpoints + 3 decisiones', $this->countEvents($req, PurchasingEvent::EV_WORKFLOW_SUBMITTED) === 1 && $this->countEvents($req, PurchasingEvent::EV_CHECKPOINT_RECORDED) === 2 && $this->countEvents($req, PurchasingEvent::EV_DECISION) === 3);
    }

    // ================================================================ [REJECT] / [RETURN]

    private function scenarioReject(): void
    {
        $this->out->writeln('== [REJECT] el jefe rechaza → REJECTED (final) ==');
        $req = $this->newSubmitted('reject');
        $this->asApprover($this->uHead1);
        $this->check('[REJECT] rechazar exige comentario (motor)', $this->throws(fn () => $this->orch()->decide($req, 'reject', PurchasingWorkflow::S_PENDING_AREA_HEAD, '')));
        $r = $this->orch()->decide($req, 'reject', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'fuera de presupuesto');
        $inst = new Instance();
        $inst->getFromDB($this->instanceId($req));
        $this->check('[REJECT] estado REJECTED + instancia cerrada + proyección', ($r['state'] ?? '') === PurchasingWorkflow::S_REJECTED && !$inst->isOpen() && $this->domainState($req) === PurchasingWorkflow::S_REJECTED);
        $dec = $this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED);
        $this->check('[REJECT] decisión rejected con evidence_ref de lo rechazado', $dec !== [] && ($this->metaOf(end($dec))['decision'] ?? '') === 'rejected' && is_array($this->metaOf(end($dec))['evidence_ref'] ?? null));
    }

    private function scenarioReturn(): void
    {
        $this->out->writeln('== [RETURN] devolución → RETURNED (editable) → reenvío ==');
        $req = $this->newSubmitted('return');
        $this->asRequester();
        $this->check('[RETURN] en PENDING_AREA_HEAD la solicitud NO es editable (autoridad del motor)', $this->throws(fn () => (new RequestManager())->updateDraft($req, ['observations' => 'x'])));
        $this->asApprover($this->uHead1);
        $r = $this->orch()->decide($req, 'return', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'falta justificar');
        $this->check('[RETURN] estado RETURNED', ($r['state'] ?? '') === PurchasingWorkflow::S_RETURNED && $this->domainState($req) === PurchasingWorkflow::S_RETURNED);
        $this->asRequester();
        $rm = new RequestManager();
        $editOk = !$this->throws(fn () => $rm->updateDraft($req, ['reason' => 'p2d2-return-justificada-' . $this->suffix]));
        $lineOk = !$this->throws(fn () => $rm->addLine($req, ['description' => 'Teclado', 'quantity' => '1', 'estimated_unit_price' => '900', 'is_inventoriable' => 0]));
        $this->check('[RETURN] en RETURNED el solicitante edita cabecera y líneas', $editOk && $lineOk);
        $state = $this->orch()->submit($req, 'reenviada');
        $this->check('[RETURN] reenvío → PENDING_AREA_HEAD (mismo número; no se renumera)', $state === PurchasingWorkflow::S_PENDING_AREA_HEAD && $this->countEvents($req, PurchasingEvent::EV_REQUEST_SUBMITTED) === 1);
        $a = $this->approveAs($this->uHead1, $req);
        $this->check('[RETURN] el jefe aprueba el contenido NUEVO (versión > la del primer ciclo)', ($a['state'] ?? '') === PurchasingWorkflow::S_PURCHASING && (int) $a['document_version'] === 2);
    }

    // ================================================================ [QUORUM] / [QUORUM-CONC]

    private function scenarioQuorum(): void
    {
        $this->out->writeln('== [QUORUM] jefe con quórum 2 (secuencial + procesos concurrentes) ==');
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['quorum_area_head' => '2']);
        $this->orch()->publishDefinition();

        $req = $this->newSubmitted('quorum');
        $r1 = $this->approveAs($this->uHead1, $req);
        $this->check('[QUORUM] 1/2 → voto registrado, sigue PENDING_AREA_HEAD', ($r1['status'] ?? '') === TransitionResult::RECORDED && $this->wfState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD);
        $this->asApprover($this->uHead1);
        $dup = $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'reintento');
        $this->check('[QUORUM] mismo aprobador otra vez → DUPLICATE (sin doble voto)', ($dup['status'] ?? '') === TransitionResult::DUPLICATE && $this->wfState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD);
        $r2 = $this->approveAs($this->uHead2, $req);
        $this->check('[QUORUM] 2/2 → PURCHASING', ($r2['state'] ?? '') === PurchasingWorkflow::S_PURCHASING);
        $refs = array_map(fn (array $row): int => (int) ($this->metaOf($row)['evidence_ref']['document_version'] ?? 0), $this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED));
        $this->check('[QUORUM] dos decisiones por aprobador ligadas a la MISMA versión', count($refs) === 2 && $refs[0] === $refs[1] && $refs[0] > 0 && $this->ledgerVersions($req) === 1);

        // Concurrente: dos procesos reales aprueban a la vez.
        $req2 = $this->newSubmitted('quorumconc');
        $cmd = 'php bin/console plugins:companypurchasing:concurrency-probe --op=approve --state=' . PurchasingWorkflow::S_PENDING_AREA_HEAD . ' --request=' . $req2 . ' --no-interaction --user=';
        $outs = $this->runParallel([$cmd . $this->uHead1, $cmd . $this->uHead2]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $okCount = count(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'OK:')));
        $this->check('[QUORUM-CONC] ambos aprobadores OK', $okCount === 2);
        $this->check('[QUORUM-CONC] exactamente UNA transición a PURCHASING', $this->countTransitionsTo($req2, PurchasingWorkflow::S_PURCHASING) === 1 && $this->wfState($req2) === PurchasingWorkflow::S_PURCHASING);
        $this->check('[QUORUM-CONC] una sola versión REQUEST_SCOPE (reutilizada por ambos)', $this->ledgerVersions($req2) === 1 && $this->sigVersions($req2) === 1);

        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['quorum_area_head' => '1']);
    }

    // ================================================================ [DELEGATION]

    private function scenarioDelegation(): void
    {
        $this->out->writeln('== [DELEGATION] el delegado de Gerencia aprueba (contexto histórico) ==');
        $req = $this->newSubmitted('deleg');
        $this->toFinance($req);
        $f = $this->approveAs($this->uDelegate, $req);
        $dec = $this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED);
        $meta = $dec !== [] ? $this->metaOf(end($dec)) : [];
        $this->check('[DELEGATION] delegado aprueba → APPROVED', ($f['state'] ?? '') === PurchasingWorkflow::S_APPROVED);
        $this->check('[DELEGATION] decisión con delegated_from = titular (motor)', (int) ($meta['actor'] ?? 0) === $this->uDelegate && (int) ($meta['delegated_from'] ?? 0) === $this->uFin);
    }

    // ================================================================ [QUOTE-CONC]

    private function scenarioQuoteConcurrency(): void
    {
        $this->out->writeln('== [QUOTE-CONC] dos selecciones simultáneas (procesos reales) ==');
        $req = $this->newSubmitted('qconc');
        $this->approveAs($this->uHead1, $req);
        $q1 = $this->makeQuote($req, $this->supA, ['1000', '2000']);
        $q2 = $this->makeQuote($req, $this->supA2, ['1100', '2100']);
        $r = new Request();
        $r->getFromDB($req);
        $lock = (int) $r->fields['lock_version'];
        $base = 'php bin/console plugins:companypurchasing:concurrency-probe --op=select-quote --request=' . $req . ' --user=' . $this->uBuyer . ' --expect=' . $lock . ' --no-interaction --quote=';
        $outs = $this->runParallel([$base . $q1, $base . $q2]);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $ok = array_values(array_filter($outs, static fn (string $o): bool => str_starts_with($o, 'OK:')));
        $r->getFromDB($req);
        $sel = (int) $r->fields['quotes_id_selected'];
        $this->check('[QUOTE-CONC] exactamente UNA selección gana (la otra falla por lock_version)', count($ok) === 1);
        $this->check('[QUOTE-CONC] quotes_id_selected = la ganadora', count($ok) === 1 && $ok[0] === 'OK:selected-' . $sel && in_array($sel, [$q1, $q2], true));
        $this->check('[QUOTE-CONC] un solo evento quote.selected', $this->countEvents($req, PurchasingEvent::EV_QUOTE_SELECTED) === 1);
    }

    // ================================================================ [SCOPE-REQUEST]

    private function scenarioScopeRequestInvalidation(): void
    {
        $this->out->writeln('== [SCOPE-REQUEST] cantidad cambia tras el jefe → reabre al jefe ==');
        $req = $this->newSubmitted('scopereq');
        $a = $this->approveAs($this->uHead1, $req);
        $v1 = (int) $a['document_version'];
        $q = $this->makeQuote($req, $this->supA, ['1000', '2000']);
        $this->asBuyer($this->uBuyer);
        $this->orch()->selectQuote($req, $q);
        $lines = $this->lineIds($req);

        $this->asBuyer($this->uBuyer, null, false);
        $this->check('[SCOPE-REQUEST] enmendar sin MANAGE_PURCHASING → denegado', $this->throws(fn () => $this->orch()->amendLineQuantity($lines[0], '5')));

        $this->asBuyer($this->uBuyer);
        $res = $this->orch()->amendLineQuantity($lines[0], '5');
        $inv = $res['invalidated'][0] ?? [];
        $this->check('[SCOPE-REQUEST] invalida REQUEST_SCOPE y reabre PENDING_AREA_HEAD', ($inv['scope'] ?? '') === 'REQUEST_SCOPE' && $this->wfState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD && $this->domainState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD);
        $this->check('[SCOPE-REQUEST] nueva document_version (> v1) registrada', (int) ($inv['document_version'] ?? 0) > $v1 && $this->sigVersions($req) >= 2);
        $invRows = $this->ledger($req, HistoryEvent::EVENT_APPROVAL_INVALIDATED);
        $this->check('[SCOPE-REQUEST] invalidateApprovals con idempotency_key + reopen_to_code', $invRows !== [] && ($this->metaOf(end($invRows))['idempotency_key'] ?? '') === ($inv['idempotency_key'] ?? '-') && ($this->metaOf(end($invRows))['reopen_to_code'] ?? '') === PurchasingWorkflow::S_PENDING_AREA_HEAD);
        $this->check('[SCOPE-REQUEST] evidencia previa CONSERVADA (v1 sigue en Firma; ledger append-only)', $this->docVersionRow($req, $v1) !== null && count($this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED)) === 1);

        // Idempotente: re-evaluar no vuelve a invalidar.
        $again = $this->orch()->enforceIntegrity($req);
        $this->check('[SCOPE-REQUEST] re-evaluación idempotente (sin segunda invalidación)', ($again['invalidated'] ?? ['x']) === [] && count($this->ledger($req, HistoryEvent::EVENT_APPROVAL_INVALIDATED)) === count($invRows));

        // Reaprobación del jefe sobre la versión NUEVA (reutiliza la registrada al invalidar).
        $a2 = $this->approveAs($this->uHead1, $req);
        $this->check('[SCOPE-REQUEST] reaprobación → PURCHASING ligada a la versión nueva', ($a2['state'] ?? '') === PurchasingWorkflow::S_PURCHASING && (int) $a2['document_version'] === (int) ($inv['document_version'] ?? -1));
    }

    // ================================================================ [SCOPE-COMM]

    private function scenarioScopeCommercialInvalidation(): void
    {
        $this->out->writeln('== [SCOPE-COMM] precio final cambia tras Gerencia → reabre COMMERCIAL (el jefe sigue válido) ==');
        \Config::setConfigurationValues('plugin:companysignature', ['listen_workflow_events' => '1']);
        $req = $this->newSubmitted('scopecomm');
        $q = $this->toFinance($req);
        $f = $this->approveAs($this->uFin, $req);
        $this->check('[SCOPE-COMM] APPROVED', ($f['state'] ?? '') === PurchasingWorkflow::S_APPROVED);
        $headV = (int) ($this->metaOf($this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED)[0])['evidence_ref']['document_version'] ?? 0);

        $this->asBuyer($this->uBuyer);
        $lines = $this->lineIds($req);
        $res = $this->orch()->updateQuote($q, ['lines' => [['items_id' => $lines[0], 'final_unit_price' => '1350']]]);
        $inv = $res['invalidated'][0] ?? [];
        $this->check('[SCOPE-COMM] invalida COMMERCIAL_FINANCIAL_SCOPE y reabre PURCHASING', ($inv['scope'] ?? '') === 'COMMERCIAL_FINANCIAL_SCOPE' && $this->wfState($req) === PurchasingWorkflow::S_PURCHASING);
        $this->check('[SCOPE-COMM] NO invalida REQUEST_SCOPE (una sola invalidación, comercial)', count($this->ledger($req, HistoryEvent::EVENT_APPROVAL_INVALIDATED)) === 1 && $this->countEvents($req, PurchasingEvent::EV_SCOPE_INVALIDATED) === 1);

        // Evidencia (companysignature, invalidación exacta por checkpoint): jefe VÁLIDA; Gerencia INVALIDADA.
        (new ReconcileService())->run();
        $head = $this->evidenceOf($req, $this->uHead1);
        $fin  = $this->evidenceOf($req, $this->uFin);
        $this->check('[SCOPE-COMM] evidencia del jefe (REQUEST_SCOPE v' . $headV . ') sigue VALID', $head !== null && $this->verifyToken($head) === VerificationService::STATUS_VALID);
        $this->check('[SCOPE-COMM] evidencia de Gerencia queda INVALIDATED', $fin !== null && $this->verifyToken($fin) === VerificationService::STATUS_INVALIDATED);

        // Recorrido nuevo: Compras + Gerencia sobre la versión comercial NUEVA.
        $b = $this->approveAs($this->uBuyer, $req, true);
        $f2 = $this->approveAs($this->uFin, $req);
        $this->check('[SCOPE-COMM] re-aprobación comercial → APPROVED con la versión nueva', ($f2['state'] ?? '') === PurchasingWorkflow::S_APPROVED && (int) $b['document_version'] === (int) ($inv['document_version'] ?? -1));
    }

    private function evidenceOf(int $reqId, int $actor): ?ApprovalEvidence
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request([
            'SELECT' => 'id', 'FROM' => ApprovalEvidence::getTable(),
            'WHERE'  => ['subject_itemtype' => Request::class, 'subject_items_id' => $reqId, 'actor_users_id' => $actor,
                         'event_type' => ApprovalEvidence::EVENT_DECISION, 'decision' => ApprovalEvidence::DECISION_APPROVED],
            'ORDER'  => 'id ASC', 'LIMIT' => 1,
        ]) as $row) {
            $m = new ApprovalEvidence();
            return $m->getFromDB((int) $row['id']) ? $m : null;
        }
        return null;
    }

    private function verifyToken(ApprovalEvidence $ev): string
    {
        $this->asApprover($this->uHead1);
        return (string) ((new SignatureApi())->verify((string) $ev->fields['verification_token'])['status'] ?? '');
    }

    // ================================================================ [DOCVER]

    private function scenarioDocVersionConcurrency(): void
    {
        $this->out->writeln('== [DOCVER] allocator concurrente (procesos reales) ==');
        $this->asRequester();
        $req = (new RequestManager())->createDraft(['entities_id' => $this->entityA, 'reason' => 'p2d2-docver-' . $this->suffix]);
        $base = 'php bin/console plugins:companypurchasing:concurrency-probe --op=docversion --request=' . $req . ' --scope=REQUEST_SCOPE --no-interaction --hash=';
        $cmds = [];
        for ($i = 1; $i <= 5; $i++) {
            $cmds[] = $base . hash('sha256', 'distinct-' . $i . '-' . $this->suffix);
        }
        $outs = $this->runParallel($cmds);
        $this->out->writeln('  probes: ' . implode(' | ', $outs));
        $vers = [];
        foreach ($outs as $o) {
            if (preg_match('/^OK:(\d+):new$/', $o, $m) === 1) {
                $vers[] = (int) $m[1];
            }
        }
        sort($vers);
        $this->check('[DOCVER] 5 contenidos distintos → 5 versiones DISTINTAS y contiguas 1..5', $vers === range(1, 5));

        $same = hash('sha256', 'same-' . $this->suffix);
        $outs2 = $this->runParallel(array_fill(0, 4, $base . $same));
        $this->out->writeln('  probes: ' . implode(' | ', $outs2));
        $v2 = [];
        $news = 0;
        foreach ($outs2 as $o) {
            if (preg_match('/^OK:(\d+):(new|reused)$/', $o, $m) === 1) {
                $v2[] = (int) $m[1];
                $news += $m[2] === 'new' ? 1 : 0;
            }
        }
        $this->check('[DOCVER] 4 procesos con el MISMO contenido → una sola versión (6), reutilizada', count($v2) === 4 && count(array_unique($v2)) === 1 && $v2[0] === 6 && $news === 1);
        $this->check('[DOCVER] nunca se hardcodea v1 ni se reutiliza una versión anterior', $this->ledgerVersions($req) === 6);
    }

    // ================================================================ [CRASH-1] / [CRASH-2]

    private function scenarioCrashBeforeTransition(): void
    {
        $this->out->writeln('== [CRASH-1] caída tras recordDocumentVersion y antes de transition ==');
        $req = $this->newSubmitted('crash1');
        $crashing = new class extends ApprovalOrchestrator {
            protected function beforeTransition(int $requestId, string $action): void
            {
                throw new \RuntimeException('caída simulada antes de transition()');
            }
        };
        $this->asApprover($this->uHead1);
        $this->check('[CRASH-1] la decisión falla por la caída', $this->throws(fn () => $crashing->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'x')));
        $sigN = $this->sigVersions($req);
        $ledN = $this->ledgerVersions($req);
        $this->check('[CRASH-1] versión ya registrada en Firma pero el motor NO avanzó', $sigN === 1 && $ledN === 1 && $this->wfState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD && $this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED) === []);
        $a = $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'reintento');
        $this->check('[CRASH-1] reintento: transición OK', ($a['state'] ?? '') === PurchasingWorkflow::S_PURCHASING);
        $this->check('[CRASH-1] reintento SIN duplicar versión (Firma y ledger iguales)', $this->sigVersions($req) === $sigN && $this->ledgerVersions($req) === $ledN && (int) $a['document_version'] === 1);
        $this->check('[CRASH-1] exactamente una decisión en el motor', count($this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED)) === 1);
    }

    private function scenarioCrashAfterTransition(): void
    {
        $this->out->writeln('== [CRASH-2] caída tras transition (confirmada) y antes de la proyección ==');
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['sync_on_workflow_events' => '0']); // listener "perdido"
        $req = $this->newSubmitted('crash2');
        $crashing = new class extends ApprovalOrchestrator {
            protected function afterTransition(int $requestId, string $action): void
            {
                throw new \RuntimeException('caída simulada tras transition()');
            }
        };
        $this->asApprover($this->uHead1);
        $this->check('[CRASH-2] la decisión "falla" tras confirmar en el motor', $this->throws(fn () => $crashing->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'x')));
        $this->check('[CRASH-2] motor = PURCHASING; proyección stale = PENDING_AREA_HEAD', $this->wfState($req) === PurchasingWorkflow::S_PURCHASING && $this->domainState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD);
        $again = $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'reintento');
        $this->check('[CRASH-2] reintento con etapa esperada → no-op idempotente (stage_changed), sin 2ª decisión', ($again['status'] ?? '') === 'stage_changed' && count($this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED)) === 1);
        // (el no-op también proyecta; se vuelve a dejar stale para probar el reconciliador)
        (new Request())->update(['id' => $req, 'domain_state' => PurchasingWorkflow::S_PENDING_AREA_HEAD]);
        $rec = $this->orch()->reconcile($req);
        $this->check('[CRASH-2] reconcile corrige domain_state (el motor gana)', ($rec['corrected'] ?? false) && $this->domainState($req) === PurchasingWorkflow::S_PURCHASING);
        $this->check('[CRASH-2] reconciliación auditada (state.reconciled)', $this->countEvents($req, PurchasingEvent::EV_STATE_RECONCILED) === 1);
        $this->check('[CRASH-2] reconcile idempotente', ($this->orch()->reconcile($req)['corrected'] ?? true) === false);
        $all = $this->orch()->reconcileAll();
        $this->check('[CRASH-2] reconcileAll sin errores', ($all['errors'] ?? 1) === 0 && ($all['checked'] ?? 0) > 0);
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['sync_on_workflow_events' => '1']);

        // Listener: una transición ejecutada DIRECTAMENTE en el motor (fuera del orquestador) se proyecta.
        $this->asApprover($this->uBuyer);
        $inst = (new WorkflowGateway())->loadInstance($this->instanceId($req));
        $res = $inst !== null ? (new WorkflowApi())->transition($inst, 'return', ['comment' => 'devuelta por el motor']) : null;
        $this->check('[SYNC] transición directa en el motor → domain_state proyectado por el listener', $res !== null && $res->success && $this->domainState($req) === PurchasingWorkflow::S_RETURNED);
    }

    // ================================================================ [PDF-FAIL]

    private function scenarioPdfFailure(): void
    {
        $this->out->writeln('== [PDF-FAIL] el PDF falla: aprobación y evidencia permanecen; retry ==');
        $req = $this->newSubmitted('pdffail');
        $failingSig = new class extends SignatureGateway {
            public function composePdf(int $documentVersionsId): array
            {
                throw new \RuntimeException('servicio PDF caído (simulado)');
            }
        };
        $this->asApprover($this->uHead1);
        $a = (new ApprovalOrchestrator(null, $failingSig))->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'ok');
        $row = $this->docVersionRow($req, (int) ($a['document_version'] ?? 0));
        $this->check('[PDF-FAIL] la aprobación NO se revierte (PURCHASING)', ($a['state'] ?? '') === PurchasingWorkflow::S_PURCHASING && $this->wfState($req) === PurchasingWorkflow::S_PURCHASING);
        $this->check('[PDF-FAIL] pdf_status=error + evento pdf.failed', ($a['pdf_status'] ?? '') === DocumentVersionAllocator::PDF_ERROR && (string) ($row['pdf_status'] ?? '') === DocumentVersionAllocator::PDF_ERROR && $this->countEvents($req, PurchasingEvent::EV_PDF_FAILED) === 1);
        $this->check('[PDF-FAIL] la evidencia (versión en Firma + decisión con evidence_ref) permanece', $this->sigVersions($req) === 1 && count($this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED)) === 1);
        $retry = $this->orch()->retryPdf($req);
        $row2 = $this->docVersionRow($req, (int) ($a['document_version'] ?? 0));
        $this->check('[PDF-FAIL] retryPdf → ready (idempotente en Firma)', ($retry[(int) $a['document_version']] ?? '') === DocumentVersionAllocator::PDF_READY && (string) ($row2['pdf_status'] ?? '') === DocumentVersionAllocator::PDF_READY);
    }

    // ================================================================ [MULTI-ENT] / [ACL]

    private function scenarioApprovalMultiEntityAcl(): void
    {
        $this->out->writeln('== [MULTI-ENT] / [ACL] aprobaciones y compras fail-closed ==');
        $req = $this->newSubmitted('acl');
        $this->asApprover($this->uHead1, [$this->entityB]);
        $this->check('[MULTI-ENT] aprobador sin acceso a la entidad A → rechazado', $this->throws(fn () => $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'x')));
        $this->asApprover($this->uBuyer);
        $this->check('[ACL] actor que NO es aprobador de la etapa (motor) → rechazado', $this->throws(fn () => $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'x')));
        $this->asRequester();
        $this->check('[ACL] el solicitante no puede aprobar su solicitud', $this->throws(fn () => $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'x')));
        $this->check('[ACL] estado intacto tras los intentos denegados', $this->wfState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD && $this->ledger($req, HistoryEvent::EVENT_DECISION_RECORDED) === []);
        $this->asBuyer($this->uBuyer);
        $this->check('[ACL] cotizar ANTES de la aprobación del jefe → fail-closed (estado)', $this->throws(fn () => $this->makeQuote($req, $this->supA, ['1000', '2000'])));

        $this->approveAs($this->uHead1, $req);
        $this->asBuyer($this->uBuyer, null, false);
        $this->check('[ACL] cotizar sin MANAGE_PURCHASING → denegado', $this->throws(fn () => $this->orch()->createQuote($req, ['suppliers_id' => $this->supA])));
        $this->asBuyer($this->uBuyer, [$this->entityB]);
        $this->check('[MULTI-ENT] Compras sin acceso a la entidad A → denegado', $this->throws(fn () => $this->orch()->createQuote($req, ['suppliers_id' => $this->supA])));
    }

    // ================================================================ [XBRANCH] / [MONEY-P2D2]

    private function scenarioCrossBranchAndMoney(): void
    {
        $this->out->writeln('== [XBRANCH] / [MONEY-P2D2] referencias y dinero fail-closed ==');
        $req = $this->newSubmitted('xb');
        $this->approveAs($this->uHead1, $req);
        $other = $this->newSubmitted('xb2');
        $this->approveAs($this->uHead1, $other);
        $lines = $this->lineIds($req);
        $this->asBuyer($this->uBuyer);
        $o = $this->orch();
        $this->check('[XBRANCH] Supplier de la rama B en solicitud de A → rechazado',
            $this->throws(fn () => $o->createQuote($req, ['suppliers_id' => $this->supB, 'lines' => [['items_id' => $lines[0], 'final_unit_price' => '1000']]])));
        $foreignLine = $this->lineIds($other)[0];
        $this->check('[XBRANCH] precio sobre una línea de OTRA solicitud → rechazado',
            $this->throws(fn () => $o->createQuote($req, ['suppliers_id' => $this->supA, 'lines' => [['items_id' => $foreignLine, 'final_unit_price' => '1000']]])));
        $qOther = $this->makeQuote($other, $this->supA, ['1000', '2000']);
        $this->asBuyer($this->uBuyer);
        $this->check('[XBRANCH] seleccionar la cotización de OTRA solicitud → rechazado', $this->throws(fn () => $o->selectQuote($req, $qOther)));
        $qOk = $this->makeQuote($req, $this->supA, ['1000', '2000']);
        $docB = $this->makeDocument('xb', $this->entityB);
        $this->asBuyer($this->uBuyer);
        $this->check('[XBRANCH] Document de la rama B adjunto a cotización de A → rechazado', $docB > 0 && $this->throws(fn () => $o->attachQuoteDocument($qOk, $docB)));

        $this->check('[MONEY-P2D2] precio con decimales en PYG → rechazado',
            $this->throws(fn () => $o->createQuote($req, ['suppliers_id' => $this->supA, 'lines' => [['items_id' => $lines[0], 'final_unit_price' => '1000.5']]])));
        $this->check('[MONEY-P2D2] cotización en otra moneda → rechazada (v1)',
            $this->throws(fn () => $o->createQuote($req, ['suppliers_id' => $this->supA, 'currency_code' => 'USD'])));
        $partial = $o->createQuote($req, ['suppliers_id' => $this->supA, 'lines' => [['items_id' => $lines[0], 'final_unit_price' => '1000']]]);
        $this->check('[MONEY-P2D2] seleccionar cotización que NO cubre todas las líneas → rechazado', $this->throws(fn () => $o->selectQuote($req, $partial)));
        $neg = $this->makeQuote($req, $this->supA, ['1000', '2000'], '99999999');
        $this->asBuyer($this->uBuyer);
        $this->check('[MONEY-P2D2] descuento > subtotal+impuestos+flete (total negativo) → rechazado', $this->throws(fn () => $o->selectQuote($req, $neg)));
        $r = new Request();
        $r->getFromDB($req);
        $this->check('[XBRANCH] ningún intento rechazado alteró la selección', (int) $r->fields['quotes_id_selected'] === 0);
        $this->asBuyer($this->uBuyer);
        $this->check('[FLOW] Compras NO puede aprobar sin cotización seleccionada (COMMERCIAL no producible)', $this->throws(fn () => $o->decide($req, 'approve', PurchasingWorkflow::S_PURCHASING, 'x')) && $this->wfState($req) === PurchasingWorkflow::S_PURCHASING);
        // …pero SÍ puede devolver/rechazar sin cotización: la decisión se liga a REQUEST_SCOPE.
        $ret = $o->decide($req, 'return', PurchasingWorkflow::S_PURCHASING, 'sin cotizaciones válidas');
        $this->check('[RETURN] Compras devuelve SIN cotización seleccionada → RETURNED (evidencia REQUEST_SCOPE)', ($ret['state'] ?? '') === PurchasingWorkflow::S_RETURNED && ($ret['scope'] ?? '') === 'REQUEST_SCOPE');
    }

    // ================================================================ [INTEGRITY-DIRTY] (punto 1)

    /** Gateway del motor cuya invalidación FALLA (simula caída del motor tras confirmar el cambio local). */
    private function failingInvalidateGateway(): WorkflowGateway
    {
        return new class extends WorkflowGateway {
            public function invalidate(int $instanceId, string $reason, array $context, ?int $expectedVersion): TransitionResult
            {
                throw new \RuntimeException('motor no disponible al invalidar (simulado)');
            }
        };
    }

    private function integrityRowState(int $reqId): string
    {
        $r = new Request();
        return $r->getFromDB($reqId) ? (string) $r->fields['integrity_state'] : '';
    }

    private function scenarioIntegrityDirtyDurable(): void
    {
        $this->out->writeln('== [INTEGRITY-DIRTY] invalidación fallida tras un cambio sustantivo ⇒ marca DURABLE ==');
        $req = $this->newSubmitted('dirty');
        $q = $this->toFinance($req);
        $f = $this->approveAs($this->uFin, $req);
        $this->check('[INTEGRITY-DIRTY] Gerencia aprobó → APPROVED y aprobación íntegra', ($f['state'] ?? '') === PurchasingWorkflow::S_APPROVED && $this->orch()->isFullyApproved($req));

        // Cambio de precio con el motor CAÍDO al invalidar.
        $this->asBuyer($this->uBuyer);
        $lines = $this->lineIds($req);
        $res = (new ApprovalOrchestrator($this->failingInvalidateGateway()))->updateQuote($q, ['lines' => [['items_id' => $lines[0], 'final_unit_price' => '1333']]]);
        $qi = new \GlpiPlugin\Companypurchasing\Model\QuoteItem();
        $qi->getFromDBByCrit(['quotes_id' => $q, 'items_id' => $lines[0]]);
        $this->check('[INTEGRITY-DIRTY] el cambio LOCAL persiste (precio 1333)', (int) ($qi->fields['final_unit_price'] ?? 0) === 1333 && ($res['error'] ?? null) !== null);
        $st = $this->orch()->integrityStatus($req);
        $this->check('[INTEGRITY-DIRTY] marca DURABLE COMMERCIAL + integrity_state=dirty', in_array('COMMERCIAL_FINANCIAL_SCOPE', $st['dirty_scopes'], true) && $this->integrityRowState($req) === 'dirty');
        $this->check('[INTEGRITY-DIRTY] el motor aún dice APPROVED pero NUNCA se presenta como aprobación limpia',
            $this->wfState($req) === PurchasingWorkflow::S_APPROVED && !$st['clean'] && !$st['fully_approved'] && !$this->orch()->isFullyApproved($req));
        $mark = new \GlpiPlugin\Companypurchasing\Model\IntegrityMark();
        $mark->getFromDBByCrit(['requests_id' => $req, 'status' => 'dirty', 'scope_key' => 'COMMERCIAL_FINANCIAL_SCOPE']);
        $this->check('[INTEGRITY-DIRTY] la marca registra actor, estado del motor, causa e idempotency_key',
            (int) ($mark->fields['actor_users_id'] ?? 0) === $this->uBuyer && ($mark->fields['workflow_state'] ?? '') === PurchasingWorkflow::S_APPROVED
            && ($mark->fields['cause'] ?? '') === PurchasingEvent::EV_QUOTE_UPDATED && str_starts_with((string) ($mark->fields['idempotency_key'] ?? ''), 'dirty:' . $req . ':'));

        // Una marca REQUEST + COMMERCIAL: prioridad REQUEST_SCOPE (enmienda con el motor caído).
        // (se prueba en otra solicitud para no mezclar; aquí seguimos con la reparación)
        // Reintento con el motor disponible ⇒ invalida/reabre y LIMPIA la marca.
        $this->asBuyer($this->uBuyer);
        $fix = $this->orch()->enforceIntegrity($req);
        $inv = $fix['invalidated'][0] ?? [];
        $st2 = $this->orch()->integrityStatus($req);
        $this->check('[INTEGRITY-DIRTY] retry: invalida COMMERCIAL y reabre PURCHASING', ($inv['scope'] ?? '') === 'COMMERCIAL_FINANCIAL_SCOPE' && $this->wfState($req) === PurchasingWorkflow::S_PURCHASING);
        $this->check('[INTEGRITY-DIRTY] retry: marca RESUELTA sólo tras invalidación confirmada',
            $st2['dirty_scopes'] === [] && $this->integrityRowState($req) === 'clean'
            && ($mark->getFromDB((int) $mark->getID()) && $mark->fields['status'] === 'resolved' && str_starts_with((string) $mark->fields['resolution'], 'invalidated:')));

        // decide() repara o falla cerrado ANTES de decidir.
        $req2 = $this->newSubmitted('dirty2');
        $q2 = $this->toFinance($req2);
        $this->asBuyer($this->uBuyer);
        $l2 = $this->lineIds($req2);
        (new ApprovalOrchestrator($this->failingInvalidateGateway()))->updateQuote($q2, ['lines' => [['items_id' => $l2[0], 'final_unit_price' => '1111']]]);
        $this->asApprover($this->uFin);
        $this->check('[INTEGRITY-DIRTY] decide() con marca pendiente y motor caído ⇒ falla CERRADO (Gerencia no aprueba)',
            $this->throws(fn () => (new ApprovalOrchestrator($this->failingInvalidateGateway()))->decide($req2, 'approve', PurchasingWorkflow::S_PENDING_FINANCE, 'x'))
            && $this->wfState($req2) === PurchasingWorkflow::S_PENDING_FINANCE && $this->decisionsBy($req2, $this->uFin) === 0);
        $d = $this->orch()->decide($req2, 'approve', PurchasingWorkflow::S_PENDING_FINANCE, 'x');
        $this->check('[INTEGRITY-DIRTY] decide() con motor disponible ⇒ REPARA primero (reopened), sin decidir', ($d['status'] ?? '') === 'reopened' && $this->decisionsBy($req2, $this->uFin) === 0 && $this->integrityRowState($req2) === 'clean');

        // Prioridad REQUEST_SCOPE: enmienda (REQUEST+COMMERCIAL sucios) con motor caído ⇒ la reparación reabre al JEFE.
        $req3 = $this->newSubmitted('dirty3');
        $this->toFinance($req3);
        $this->asBuyer($this->uBuyer);
        (new ApprovalOrchestrator($this->failingInvalidateGateway()))->amendLineQuantity($this->lineIds($req3)[0], '4');
        $st3 = $this->orch()->integrityStatus($req3);
        $this->check('[INTEGRITY-DIRTY] enmienda con motor caído ⇒ REQUEST y COMMERCIAL sucios', $st3['dirty_scopes'] !== [] && array_diff(['REQUEST_SCOPE', 'COMMERCIAL_FINANCIAL_SCOPE'], $st3['dirty_scopes']) === []);
        $fix3 = $this->orch()->enforceIntegrity($req3);
        $this->check('[INTEGRITY-DIRTY] prioridad REQUEST_SCOPE: reabre al jefe y limpia AMBAS marcas',
            ($fix3['invalidated'][0]['scope'] ?? '') === 'REQUEST_SCOPE' && $this->wfState($req3) === PurchasingWorkflow::S_PENDING_AREA_HEAD
            && $this->orch()->integrityStatus($req3)['dirty_scopes'] === []);
    }

    private function decisionsBy(int $reqId, int $user): int
    {
        return count(array_filter($this->ledger($reqId, HistoryEvent::EVENT_DECISION_RECORDED), fn (array $r): bool => (int) ($this->metaOf($r)['actor'] ?? 0) === $user));
    }

    // ================================================================ [REOPEN-CAPABILITY] (punto 1)

    private function scenarioReopenCapability(): void
    {
        $this->out->writeln('== [REOPEN-CAPABILITY] sin RIGHT_ACT no se aceptan cambios que exigirían reabrir ==');
        $req = $this->newSubmitted('cap');
        $q = $this->toFinance($req);
        $lines = $this->lineIds($req);
        $noAct = function (): void {
            $this->applySession($this->uBuyer, [$this->entityA], [
                'plugin_companypurchasing' => READ | Request::RIGHT_VIEW_ENTITY | Request::RIGHT_MANAGE_PURCHASING,
                'plugin_companyworkflow'   => READ, // sin RIGHT_ACT
                'plugin_companysignature'  => ALLSTANDARDRIGHT,
            ]);
        };
        $noAct();
        $o = $this->orch();
        $q2 = $o->createQuote($req, ['suppliers_id' => $this->supA2, 'lines' => [['items_id' => $lines[0], 'final_unit_price' => '1000'], ['items_id' => $lines[1], 'final_unit_price' => '2000']]]);
        $this->check('[REOPEN-CAPABILITY] crear una cotización NO seleccionada sí se permite (no altera lo aprobado)', $q2 > 0);
        $this->check('[REOPEN-CAPABILITY] seleccionar otra cotización sin RIGHT_ACT → rechazado', $this->throws(fn () => $o->selectQuote($req, $q2)));
        $this->check('[REOPEN-CAPABILITY] cambiar la cotización seleccionada sin RIGHT_ACT → rechazado', $this->throws(fn () => $o->updateQuote($q, ['discounts' => '10'])));
        $this->check('[REOPEN-CAPABILITY] enmendar cantidad sin RIGHT_ACT → rechazado', $this->throws(fn () => $o->amendLineQuantity($lines[0], '9')));
        $r = new Request();
        $r->getFromDB($req);
        $qq = new \GlpiPlugin\Companypurchasing\Model\Quote();
        $qq->getFromDB($q);
        $this->check('[REOPEN-CAPABILITY] nada cambió: selección, descuento, cantidad, sin marcas',
            (int) $r->fields['quotes_id_selected'] === $q && (int) $qq->fields['discounts'] === 300
            && (int) (new RequestManager())->loadItems($req)[0]->fields['quantity'] === 2 && $this->orch()->integrityStatus($req)['dirty_scopes'] === []);
    }

    // ================================================================ [EXPECTED-STATE] (punto 2)

    private function scenarioExpectedStateMandatory(): void
    {
        $this->out->writeln('== [EXPECTED-STATE] etapa obligatoria; reintento tras caída no aprueba la etapa siguiente ==');
        // Mismo usuario en Compras Y en Finanzas.
        $uDual = $this->makeActor('dual');
        (new Group_User())->add(['groups_id' => $this->gBuy, 'users_id' => $uDual]);
        (new Group_User())->add(['groups_id' => $this->gFin, 'users_id' => $uDual]);
        $req = $this->newSubmitted('dual');
        $this->approveAs($this->uHead1, $req);
        $q = $this->makeQuote($req, $this->supA, ['1400', '2300']);
        $this->asBuyer($this->uBuyer);
        $this->orch()->selectQuote($req, $q);

        $this->asBuyer($uDual);
        $this->check('[EXPECTED-STATE] expectedState vacío → rechazado (contrato obligatorio)', $this->throws(fn () => $this->orch()->decide($req, 'approve', '', 'x')));
        // Compras (uDual) aprueba; la transición se CONFIRMA y el proceso cae antes de responder.
        $crashing = new class extends ApprovalOrchestrator {
            protected function afterTransition(int $requestId, string $action): void
            {
                throw new \RuntimeException('caída simulada antes de responder');
            }
        };
        $this->check('[EXPECTED-STATE] Compras aprueba y el proceso cae tras confirmar', $this->throws(fn () => $crashing->decide($req, 'approve', PurchasingWorkflow::S_PURCHASING, 'ok compras'))
            && $this->wfState($req) === PurchasingWorkflow::S_PENDING_FINANCE);
        // Reintento del MISMO comando: jamás aprueba Finanzas.
        $retry = $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PURCHASING, 'ok compras');
        $this->check('[EXPECTED-STATE] reintento ⇒ stage_changed (no decide en PENDING_FINANCE)', ($retry['status'] ?? '') === 'stage_changed' && $this->wfState($req) === PurchasingWorkflow::S_PENDING_FINANCE);
        $this->check('[EXPECTED-STATE] exactamente UNA decisión de uDual (la de Compras); Finanzas intacta', $this->decisionsBy($req, $uDual) === 1 && $this->countTransitionsTo($req, PurchasingWorkflow::S_APPROVED) === 0);
        // Finanzas explícita por el mismo usuario sí funciona (decisión consciente, otra etapa declarada).
        $fin = $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PENDING_FINANCE, 'ok finanzas');
        $this->check('[EXPECTED-STATE] decisión explícita en PENDING_FINANCE → APPROVED', ($fin['state'] ?? '') === PurchasingWorkflow::S_APPROVED);
    }

    // ================================================================ [POLICY] (punto 3)

    private function policyIdOf(int $reqId): int
    {
        $r = new Request();
        return $r->getFromDB($reqId) ? (int) $r->fields['policies_id'] : 0;
    }

    private function scenarioPolicyPinned(): void
    {
        $this->out->writeln('== [POLICY] política pinneada por solicitud (cambio de config no es retroactivo) ==');
        $r1 = $this->newSubmitted('polA');
        $pA = $this->policyIdOf($r1);
        $this->check('[POLICY] R1 pinnea la política A al enviarse', $pA > 0);

        // Administrador cambia la política: sin PDF por etapa y sin gestión comercial en APPROVED.
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['pdf_stages' => '[]', 'quote_states' => '["PURCHASING","PENDING_FINANCE"]']);
        $r2 = $this->newSubmitted('polB');
        $pB = $this->policyIdOf($r2);
        $this->check('[POLICY] R2 nuevo pinnea la política B (distinta de A)', $pB > 0 && $pB !== $pA);
        $this->check('[POLICY] R1 conserva A (policies_id intacto)', $this->policyIdOf($r1) === $pA);

        $a1 = $this->approveAs($this->uHead1, $r1);
        $a2 = $this->approveAs($this->uHead1, $r2);
        $this->check('[POLICY] R1 (política A) compone PDF en la etapa del jefe', ($a1['pdf_status'] ?? '') === DocumentVersionAllocator::PDF_READY);
        $this->check('[POLICY] R2 (política B) NO compone PDF', !array_key_exists('pdf_status', $a2) && ($a2['state'] ?? '') === PurchasingWorkflow::S_PURCHASING);
        $store = new \GlpiPlugin\Companypurchasing\Service\PolicyStore();
        $this->check('[POLICY] versiones inmutables verificables (hash)', $store->load($pA)->pdfStages() === ['PENDING_AREA_HEAD', 'PENDING_FINANCE'] && $store->load($pB)->pdfStages() === []);

        // Restaurar la política por defecto (nuevas solicitudes).
        \Config::setConfigurationValues(PluginConfig::CONTEXT, [
            'pdf_stages'   => PluginConfig::DEFAULTS['pdf_stages'],
            'quote_states' => PluginConfig::DEFAULTS['quote_states'],
        ]);
    }

    // ================================================================ [CRASH-0] (punto 5)

    private function scenarioSubmitCrashBeforeStart(): void
    {
        $this->out->writeln('== [CRASH-0] envío local confirmado → caída antes de startInstance → retry converge ==');
        $this->asRequester();
        $rm = new RequestManager();
        $req = $rm->createDraft(['entities_id' => $this->entityA, 'reason' => 'p2d2-crash0-' . $this->suffix]);
        $rm->addLine($req, ['description' => 'x', 'quantity' => '1', 'estimated_unit_price' => '1000', 'is_inventoriable' => 0]);
        $crashing = new class extends ApprovalOrchestrator {
            protected function beforeStartInstance(int $requestId): void
            {
                throw new \RuntimeException('caída simulada antes de startInstance()');
            }
        };
        $this->check('[CRASH-0] el envío falla tras confirmar el paso local', $this->throws(fn () => $crashing->submit($req)));
        $r = new Request();
        $r->getFromDB($req);
        $this->check('[CRASH-0] estado intermedio: número asignado + política pinneada, SIN instancia',
            (int) $r->fields['number_seq'] > 0 && (int) $r->fields['policies_id'] > 0 && (new WorkflowGateway())->findInstance(Request::class, $req) === null);
        $this->check('[CRASH-0] reconcile DETECTA la solicitud enviada sin instancia (no es éxito silencioso)', ($this->orch()->reconcile($req)['orphan'] ?? false) === true);
        $this->asRequester();
        $state = $this->orch()->submit($req);
        $this->check('[CRASH-0] retry del envío converge → PENDING_AREA_HEAD (mismo número)', $state === PurchasingWorkflow::S_PENDING_AREA_HEAD && $this->countEvents($req, PurchasingEvent::EV_REQUEST_SUBMITTED) === 1);
        $this->check('[CRASH-0] reconcile ya no la reporta', ($this->orch()->reconcile($req)['orphan'] ?? true) === false);
    }

    // ================================================================ [RECONCILE-CURSOR] (punto 5)

    private function scenarioReconcileCursor(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [RECONCILE-CURSOR] lotes con cursor y wrap-around: sin starvation ==');
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['sync_on_workflow_events' => '0']);
        $reqs = [];
        for ($i = 0; $i < 3; $i++) {
            $reqs[] = $this->newSubmitted('cur' . $i);
        }
        // Proyecciones stale en solicitudes repartidas (incluida la de MAYOR id).
        foreach ($reqs as $id) {
            $DB->update(Request::getTable(), ['domain_state' => 'STALE'], ['id' => $id]);
        }
        $total = 0;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => Request::getTable(), 'WHERE' => ['NOT' => ['domain_state' => Request::STATE_DRAFT]]]) as $row) {
            $total = (int) $row['c'];
        }
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['reconcile_cursor' => '0']);
        $limit = 2;
        $runs = (int) ceil($total / $limit);
        $wrapped = false;
        $seen = [];
        $o = $this->orch();
        for ($i = 0; $i < $runs + 1; $i++) {
            $r = $o->reconcileAll($limit);
            $wrapped = $wrapped || $r['wrapped'];
            $seen[] = $r['cursor'];
        }
        $fixed = array_filter($reqs, fn (int $id): bool => $this->domainState($id) === PurchasingWorkflow::S_PENDING_AREA_HEAD);
        $this->check('[RECONCILE-CURSOR] con límite ' . $limit . ' y ' . $total . ' solicitudes, TODAS las stale se corrigen en ' . ($runs + 1) . ' corridas (incluida la de mayor id)', count($fixed) === count($reqs));
        $this->check('[RECONCILE-CURSOR] el cursor avanza y hace wrap-around', $wrapped && count(array_unique($seen)) > 1);
        $once = $o->reconcileAll(1000);
        $this->check('[RECONCILE-CURSOR] reporta solicitudes enviadas sin instancia (las de P2D-1 enviadas sólo localmente)', count($once['orphans']) > 0 && $once['errors'] === 0);

        // La Acción automática NATIVA (CronTask) corrige una proyección stale por la ruta real de GLPI.
        $DB->update(Request::getTable(), ['domain_state' => 'STALE'], ['id' => end($reqs)]);
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['reconcile_cursor' => '0']);
        $ran = \CronTask::launch(-\CronTask::MODE_EXTERNAL, 1, \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME);
        $this->check('[RECONCILE-CURSOR] CronTask nativa ejecutada y corrigió la proyección', $ran === \GlpiPlugin\Companypurchasing\Model\ProjectionTask::CRON_NAME && $this->domainState(end($reqs)) === PurchasingWorkflow::S_PENDING_AREA_HEAD);
        $this->asAdmin();
        \Config::setConfigurationValues(PluginConfig::CONTEXT, ['sync_on_workflow_events' => '1', 'reconcile_cursor' => '0']);
    }

    // ================================================================ [EVIDENCE-TAMPER] (punto 6)

    private function scenarioEvidenceRefTampered(): void
    {
        $this->out->writeln('== [EVIDENCE-TAMPER] aprobación con evidence_bound=1 pero evidence_ref alterada ==');
        $prepare = function (string $tag): array {
            $req = $this->newSubmitted($tag);
            // Registra la versión REQUEST_SCOPE en el ledger de Compras y en Firma SIN transicionar.
            $recordOnly = new class extends ApprovalOrchestrator {
                protected function beforeTransition(int $requestId, string $action): void
                {
                    throw new \RuntimeException('sólo registrar la versión');
                }
            };
            $this->asApprover($this->uHead1);
            $this->throws(fn () => $recordOnly->decide($req, 'approve', PurchasingWorkflow::S_PENDING_AREA_HEAD, 'x'));
            $row = $this->docVersionRow($req, 1);
            return [$req, $row];
        };
        $cases = [
            'content_sha256 alterado' => static fn (array $row): array => ['document_versions_id' => (int) $row['document_versions_id'], 'document_version' => 1, 'content_sha256' => str_repeat('f', 64)],
            'ref incompleta (sin content_sha256)' => static fn (array $row): array => ['document_versions_id' => (int) $row['document_versions_id'], 'document_version' => 1],
            'document_versions_id ajeno' => static fn (array $row): array => ['document_versions_id' => (int) $row['document_versions_id'] + 999999, 'document_version' => 1, 'content_sha256' => (string) $row['content_sha256']],
        ];
        $i = 0;
        foreach ($cases as $label => $mkRef) {
            [$req, $row] = $prepare('tamper' . $i++);
            $inst = (new WorkflowGateway())->loadInstance($this->instanceId($req));
            $this->asApprover($this->uHead1);
            // Transición DIRECTA en el motor (fuera de Compras) con la condición satisfecha y la ref alterada.
            $res = ($inst !== null && $row !== null) ? (new WorkflowApi())->transition($inst, 'approve', [
                'comment' => 'ref alterada', 'fields' => ['evidence_bound' => 1], 'evidence_ref' => $mkRef($row),
            ]) : null;
            $st = $this->orch()->integrityStatus($req);
            $this->check("[EVIDENCE-TAMPER] {$label}: el motor avanzó pero Compras NO la considera íntegra",
                $res !== null && $res->success && $this->wfState($req) === PurchasingWorkflow::S_PURCHASING
                && ($st['drift']['REQUEST_SCOPE'] ?? '') === 'evidence_mismatch' && !$st['clean']);
            $this->asBuyer($this->uBuyer);
            $d = $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PURCHASING, 'x');
            $this->check("[EVIDENCE-TAMPER] {$label}: la siguiente decisión REPARA (reabre al jefe) y no decide",
                ($d['status'] ?? '') === 'reopened' && $this->wfState($req) === PurchasingWorkflow::S_PENDING_AREA_HEAD && $this->decisionsBy($req, $this->uBuyer) === 0);
        }
    }

    // ================================================================ [SUPPLIER-MOVED] (punto 6)

    private function scenarioSupplierMovedAfterSelection(): void
    {
        $this->out->writeln('== [SUPPLIER-MOVED] Supplier válido al seleccionar → pasa a otra rama → fail-closed ==');
        $this->asAdmin();
        $supM = $this->makeSupplier('CP-SUP-MOVE-' . $this->suffix, $this->entityA, false);
        $req = $this->newSubmitted('supmove');
        $this->approveAs($this->uHead1, $req);
        $q = $this->makeQuote($req, $supM, ['1400', '2300']);
        $this->asBuyer($this->uBuyer);
        $this->orch()->selectQuote($req, $q);
        $this->approveAs($this->uBuyer, $req, true);
        $this->check('[SUPPLIER-MOVED] con Supplier válido → PENDING_FINANCE', $this->wfState($req) === PurchasingWorkflow::S_PENDING_FINANCE);

        // El Supplier pasa a la rama B (interfaz del modelo nativo, no SQL al core).
        $this->asAdmin();
        (new \Supplier())->update(['id' => $supM, 'entities_id' => $this->entityB]);
        $sup = new \Supplier();
        $moved = $sup->getFromDB($supM) && (int) $sup->fields['entities_id'] === $this->entityB;
        $this->check('[SUPPLIER-MOVED] fixture: el Supplier quedó en la rama B', $moved);

        $this->asApprover($this->uFin);
        $d = null;
        $threw = $this->throws(function () use ($req, &$d): void {
            $d = $this->orch()->decide($req, 'approve', PurchasingWorkflow::S_PENDING_FINANCE, 'ok');
        });
        $this->check('[SUPPLIER-MOVED] la aprobación financiera NO ocurre (fail-closed: reabre o rechaza)',
            $moved && $this->decisionsBy($req, $this->uFin) === 0 && $this->wfState($req) !== PurchasingWorkflow::S_APPROVED
            && ($threw || in_array($d['status'] ?? '', ['reopened'], true)));
        $this->check('[SUPPLIER-MOVED] los términos comerciales ya no son producibles con ese Supplier', $this->throws(function () use ($req): void {
            $r = new Request();
            $r->getFromDB($req);
            (new \GlpiPlugin\Companypurchasing\Service\QuoteManager())->commercialTerms($r);
        }));
    }

    // ================================================================ fixtures nativos

    /** Document NATIVO de prueba (sin archivo; si GLPI lo exige, con un archivo temporal). */
    private function makeDocument(string $tag, int $entity): int
    {
        $this->asAdmin();
        $name = 'CP-DOC-' . $tag . '-' . $this->suffix;
        $id = (int) (new Document())->add(['name' => $name, 'entities_id' => $entity, 'is_recursive' => 0]);
        if ($id <= 0) {
            $dir = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
            $fname = 'cpur_' . $tag . '_' . $this->suffix . '.txt';
            @file_put_contents(rtrim((string) $dir, '/') . '/' . $fname, 'cp selftest ' . $tag);
            $id = (int) (new Document())->add([
                'name' => $name, 'entities_id' => $entity, 'is_recursive' => 0,
                '_filename' => [$fname], '_prefix_filename' => [''],
            ]);
        }
        if ($id > 0) {
            $this->createdDocuments[] = $id;
        }
        return $id;
    }

    // ================================================================ limpieza

    private function p2d2Cleanup(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        try {
            $this->asAdmin();
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => Request::getTable()]) as $row) {
                $DB->delete(ApprovalEvidence::getTable(), ['subject_itemtype' => Request::class, 'subject_items_id' => (int) $row['id']]);
                $DB->delete(DocumentVersion::getTable(), ['subject_itemtype' => Request::class, 'subject_items_id' => (int) $row['id']]);
            }
            $DB->delete(\Document_Item::getTable(), ['itemtype' => [Quote::class, Request::class]]);
            foreach ($this->createdDocuments as $d) {
                (new Document())->delete(['id' => $d], true);
            }
            // Higiene del reconciliador de Firma: los eventos de ESTAS instancias de prueba no deben quedar
            // pendientes/erróneos tras borrar sus sujetos (mismo baseline que usa el selftest de Firma).
            $instIds = [];
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => Instance::getTable(), 'WHERE' => ['itemtype' => Request::class]]) as $row) {
                $instIds[] = (int) $row['id'];
            }
            if ($instIds !== []) {
                $DB->delete(ReconcileTask::getTable(), ['instances_id' => $instIds]);
            }
            $maxHid = 0;
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => HistoryEvent::getTable(), 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $row) {
                $maxHid = (int) $row['id'];
            }
            \Config::setConfigurationValues('plugin:companysignature', ['last_seen_history_id' => (string) $maxHid]);
            $DB->delete(Instance::getTable(), ['itemtype' => Request::class]);
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => WorkflowDef::getTable(), 'WHERE' => ['code' => 'cpur_st_' . $this->suffix]]) as $row) {
                $DB->delete(WorkflowDef::getTable(), ['id' => (int) $row['id']]);
            }
            $DB->delete(Delegation::getTable(), ['users_id_from' => $this->uFin]);
            foreach (['inventory_outbox', 'receipt_units', 'receipt_batches', 'cost_policies', 'integrity', 'quote_items', 'quotes', 'doc_versions', 'docseq', 'policies'] as $t) {
                $DB->delete('glpi_plugin_companypurchasing_' . $t, ['id' => ['>', 0]]);
            }
            $DB->delete(PurchasingEvent::getTable(), ['event' => PurchasingEvent::EV_DEFINITION_PUBLISHED]);
            $restore = array_filter($this->savedConfig, static fn ($v): bool => $v !== null);
            if ($restore !== []) {
                \Config::setConfigurationValues(PluginConfig::CONTEXT, array_map('strval', $restore));
            }
        } catch (\Throwable $e) {
            $this->out->writeln('  (limpieza P2D-2 best-effort: ' . $e->getMessage() . ')');
        }
    }
}
