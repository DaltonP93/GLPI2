<?php

/**
 * ORQUESTADOR del circuito de aprobación de Compras (P2D-2) — saga idempotente sobre el motor y la firma.
 *
 * Roles (no se duplican):
 *   - `companyworkflow` = ÚNICO motor (estados, aprobadores, quórum, delegación, SLA, lock_version,
 *     invalidación, ledger). La instancia es la AUTORIDAD; `domain_state` es proyección.
 *   - `companysignature` = snapshot canónico/hash/versión inmutable/evidencia/PDF.
 *   - Compras = dominio: arma el snapshot SEMÁNTICO del scope, NUMERA la `document_version`, construye la
 *     `evidence_ref` y orquesta.
 *
 * Reglas de la saga:
 *   1. NINGUNA llamada a companyworkflow/companysignature ocurre dentro de una transacción LOCAL; cada
 *      paso local (allocator, ledger, marcas, auditoría, proyección) confirma por sí mismo.
 *   2. Serialización por el lock común de la solicitud (`request_<id>`) — no es una transacción de BD.
 *   3. Idempotencia: la versión se REUTILIZA si el contenido del scope no cambió; `recordDocumentVersion`
 *      es idempotente; la transición usa `expected_lock_version`; toda decisión declara la ETAPA sobre la
 *      que se cree actuar (`expectedState`, obligatoria: un reintento tras una caída posterior a la
 *      transición devuelve `stage_changed` y jamás decide en la etapa siguiente); la invalidación usa
 *      `idempotency_key` derivada de la versión; los eventos de la saga usan `recordOnce`.
 *   4. Política PINNEADA por solicitud (`PolicyStore`): etapa→scope, checkpoints, PDF y estados comerciales
 *      no cambian retroactivamente si un administrador edita la configuración.
 *   5. Integridad por scope (fail-closed): una mutación sustantiva escribe una MARCA DURABLE en su misma
 *      transacción; la marca sólo se resuelve tras una invalidación CONFIRMADA o tras verificar contra el
 *      ledger del motor. Antes de CADA decisión se repara (o se falla cerrado): las aprobaciones VIVAS de
 *      cada scope deben coincidir EXACTAMENTE ({document_versions_id, document_version, content_sha256} +
 *      scope) con el ledger de Compras y con el contenido ACTUAL. Mientras haya marcas o deriva, la
 *      solicitud NO está íntegramente aprobada (`integrityStatus()`), aunque el motor diga APPROVED.
 *   6. Proyección: `reconcile()`/`reconcileAll()` (cursor con wrap-around) + Acción automática nativa.
 *
 * No es `final`: los tests deterministas de caída inyectan fallos en `beforeStartInstance()`,
 * `beforeTransition()` y `afterTransition()`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Config;
use Session;
use GlpiPlugin\Companypurchasing\Model\DocVersion;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companyworkflow\Model\Instance;

class ApprovalOrchestrator
{
    public const ACTIONS = ['approve', 'reject', 'return'];

    /** Claves de scope que exigen términos de la cotización seleccionada. */
    private const COMMERCIAL_TERM_KEYS = ['suppliers_id_selected', 'selected_quote', 'final_prices', 'discounts', 'taxes', 'freight'];

    // Códigos de TransitionResult (literales: el archivo no depende del motor al cargarse).
    private const R_RECORDED  = 'recorded';
    private const R_DUPLICATE = 'duplicate';

    protected WorkflowGateway $wf;
    protected SignatureGateway $sig;
    protected DocumentVersionAllocator $alloc;
    protected Audit $audit;
    protected AdvisoryLock $lock;
    protected RequestManager $requests;
    protected QuoteManager $quotes;
    protected StateProjection $projection;
    protected ScopeSnapshotBuilder $builder;
    protected PolicyStore $policies;
    protected IntegrityLedger $integrity;

    public function __construct(
        ?WorkflowGateway $wf = null,
        ?SignatureGateway $sig = null,
        ?DocumentVersionAllocator $alloc = null,
        ?Audit $audit = null,
        ?AdvisoryLock $lock = null
    ) {
        $this->wf         = $wf ?? new WorkflowGateway();
        $this->sig        = $sig ?? new SignatureGateway();
        $this->alloc      = $alloc ?? new DocumentVersionAllocator();
        $this->audit      = $audit ?? new Audit();
        $this->lock       = $lock ?? new AdvisoryLock();
        $this->requests   = new RequestManager(null, $this->audit, $this->lock, $this->wf);
        $this->quotes     = new QuoteManager($this->audit, $this->lock, null, $this->wf);
        $this->projection = new StateProjection($this->wf, $this->audit);
        $this->builder    = new ScopeSnapshotBuilder();
        $this->policies   = new PolicyStore();
        $this->integrity  = new IntegrityLedger();
    }

    // ================================================================ definición (administración)

    /**
     * Publica una NUEVA versión de la definición de Compras en companyworkflow desde la configuración.
     * ACL `MANAGE_CONFIG`. FAIL-CLOSED: grupos sin configurar/inexistentes o mapas inválidos ⇒ no publica.
     *
     * @return int id de la definición activa publicada
     */
    public function publishDefinition(): int
    {
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_MANAGE_CONFIG)) {
            throw new \RuntimeException('permiso denegado (MANAGE_CONFIG)');
        }
        ApprovalPolicy::fromArray($this->policies->currentRaw()); // la política vigente debe ser válida
        $cfg = PluginConfig::stageConfig();
        foreach ($cfg['groups'] as $stage => $groupId) {
            if ($groupId > 0 && !(new \Group())->getFromDB($groupId)) {
                throw new \RuntimeException("grupo aprobador inexistente para {$stage} (fail-closed)");
            }
        }
        $spec = PurchasingWorkflow::spec(PluginConfig::workflowCode(), Request::class, $cfg);
        $def = $this->wf->publish($spec);
        $this->audit->record(0, PurchasingEvent::EV_DEFINITION_PUBLISHED, 0, [
            'code' => (string) $def->fields['code'], 'version' => (int) $def->fields['version'], 'workflowdefs_id' => (int) $def->getID(),
        ]);
        return (int) $def->getID();
    }

    // ================================================================ submit

    /**
     * Envía la solicitud al circuito: (0) exige definición ACTIVA antes de tocar nada local; (1) paso LOCAL
     * de P2D-1 (número + scopes + política pinneados, idempotente); (2) inicia/enlaza la instancia del
     * motor; (3) `submit` desde DRAFT/RETURNED; (4) proyecta. Un reintento tras una caída entre (1) y (2)
     * converge (y `reconcile` reporta la solicitud enviada-sin-instancia mientras tanto).
     */
    public function submit(int $requestId, string $comment = ''): string
    {
        // (0) FAIL-CLOSED antes de cualquier cambio local: sin definición activa la solicitud sigue en DRAFT
        // (no consume número ni queda "enviada" sin instancia).
        if ($this->wf->activeDefinition(PluginConfig::workflowCode()) === null) {
            throw new \RuntimeException('definición de workflow de compras no publicada (fail-closed)');
        }

        // (1) Local P2D-1 (toma y libera su propio lock; valida EDIT_DRAFT + entidad + visibilidad).
        $this->requests->submitDraft($requestId);

        return (string) $this->lock->withRequestLock($requestId, function () use ($requestId, $comment): string {
            $req = $this->loadRequest($requestId);
            $this->assertEntity($req);
            $def = $this->wf->activeDefinition(PluginConfig::workflowCode());
            if ($def === null) {
                throw new \RuntimeException('definición de workflow de compras no publicada (fail-closed)');
            }
            // (2) Instancia: enlazada, o existente por (itemtype, items_id) tras una caída, o nueva.
            $inst = $this->instanceFor($req, false);
            if ($inst === null) {
                if ((int) ($req->fields['policies_id'] ?? 0) <= 0) {
                    // Enviada antes de existir la política pinneada y SIN instancia aún: se pinnea ahora (todavía
                    // no hay ninguna decisión: no es un cambio retroactivo).
                    if (!$req->update(['id' => $requestId, 'policies_id' => $this->policies->pinCurrent()])) {
                        throw new \RuntimeException('no se pudo pinnear la política de aprobación');
                    }
                    $req->getFromDB($requestId);
                }
                $this->policies->forRequest($req); // fail-closed si no es válida
                $this->beforeStartInstance($requestId);
                $inst = $this->wf->startInstance($def, Request::class, $requestId, (int) $req->fields['entities_id']);
                $this->audit->recordOnce($requestId, PurchasingEvent::EV_WORKFLOW_STARTED, (int) $req->fields['entities_id'], [
                    'instances_id' => (int) $inst->getID(), 'workflowdefs_id' => (int) $def->getID(),
                    'policies_id'  => (int) $req->fields['policies_id'],
                ], (string) $req->fields['correlation_id'], 'wf-start:' . $requestId);
            }
            $this->projection->sync($req);

            // (3) submit desde un estado editable (DRAFT o RETURNED); en otro estado ya fue enviado.
            $state = $this->wf->stateCode($inst);
            if (in_array($state, PurchasingWorkflow::EDITABLE, true)) {
                $lockBefore = (int) $inst->fields['lock_version'];
                $res = $this->wf->transition($inst, 'submit', [
                    'comment'               => $comment,
                    'requester_users_id'    => (int) $req->fields['users_id_requester'],
                    'expected_lock_version' => $lockBefore,
                ]);
                if (!$res->success) {
                    throw new \RuntimeException('el motor rechazó el envío: ' . $res->code . ' — ' . $res->message);
                }
                $this->audit->recordOnce($requestId, PurchasingEvent::EV_WORKFLOW_SUBMITTED, (int) $req->fields['entities_id'], [
                    'from' => $state, 'to' => (string) ($res->data['to'] ?? ''), 'workflow_lock_version' => $lockBefore,
                ], (string) $req->fields['correlation_id'], 'wf-submit:' . (int) $inst->getID() . ':' . $lockBefore);
            }
            // (4) Proyección (el motor gana).
            return $this->projection->sync($req)['to'];
        });
    }

    /** Punto de inyección de caída para tests (envío local confirmado, antes de `startInstance()`). */
    protected function beforeStartInstance(int $requestId): void
    {
    }

    // ================================================================ decisiones

    /**
     * approve | reject | return en la etapa `$expectedState` (OBLIGATORIA), ligada a evidencia del scope.
     *
     * Si la instancia ya no está en `$expectedState` (p. ej. reintento tras una caída posterior a una
     * transición confirmada) ⇒ `stage_changed`, SIN crear otra decisión: un mismo usuario que pertenece a dos
     * etapas jamás "aprueba de más" por reintentar. Dentro de la misma etapa un reintento es `duplicate`.
     *
     * Saga: reparación de integridad (marcas + deriva; fail-closed) → snapshot del scope → versión (reusada
     * si no cambió) → `recordDocumentVersion` → `evidence_ref` → `transition(expected_lock_version)` →
     * auditoría → proyección → (fuera del lock) PDF si la política pinneada lo exige.
     *
     * @return array<string,mixed>
     */
    public function decide(int $requestId, string $action, string $expectedState, string $comment = ''): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("acción inválida: {$action}");
        }
        $expectedState = trim($expectedState);
        if ($expectedState === '') {
            throw new \InvalidArgumentException('expectedState es obligatorio: toda decisión declara la etapa sobre la que actúa');
        }
        $result = (array) $this->lock->withRequestLock($requestId, function () use ($requestId, $action, $comment, $expectedState): array {
            $req  = $this->loadRequest($requestId);
            $this->assertEntity($req);
            $inst = $this->instanceFor($req, true);
            if (!$this->wf->isOpen($inst)) {
                throw new \RuntimeException('la instancia de workflow no está abierta');
            }
            $policy = $this->policies->forRequest($req);
            $state = $this->wf->stateCode($inst);
            if ($state !== $expectedState) {
                $this->projection->sync($req);
                return ['status' => 'stage_changed', 'state' => $state, 'advanced' => false, 'pdf' => false];
            }
            $scope = $policy->stageScopes()[$state] ?? null;
            if ($scope === null) {
                throw new \RuntimeException("el estado {$state} no es una etapa de aprobación (fail-closed)");
            }

            // Reparación de integridad ANTES de decidir (marcas durables + deriva exacta). Si no se puede
            // reparar, lanza: nunca se decide sobre una aprobación obsoleta.
            $invalidated = $this->enforceIntegrityLocked($req, $inst);
            if ($invalidated !== []) {
                $inst = $this->instanceFor($req, true);
                $now = $this->wf->stateCode($inst);
                if ($now !== $state) {
                    return ['status' => 'reopened', 'state' => $now, 'advanced' => false, 'pdf' => false, 'invalidated' => $invalidated];
                }
            }
            if ($this->integrity->pending($requestId) !== []) {
                throw new \RuntimeException('integridad de aprobación pendiente: no se decide (fail-closed)');
            }

            // Evidencia de la decisión: una APROBACIÓN siempre se liga al scope de su etapa (no producible ⇒
            // fail-closed: p. ej. Compras no aprueba sin cotización seleccionada). Un RECHAZO/DEVOLUCIÓN debe
            // ser posible aunque ese scope aún no sea producible: se liga entonces a REQUEST_SCOPE (lo que el
            // solicitante pidió), que siempre es producible tras el envío.
            $evidenceScope = $scope;
            $semantic = null;
            if ($action !== 'approve') {
                try {
                    $semantic = $this->semantic($req, $scope);
                } catch (\Throwable) {
                    $evidenceScope = ScopeCatalog::SCOPE_REQUEST;
                }
            }
            $cp = $this->recordCheckpointLocked($req, $evidenceScope, $semantic);
            $ref = [
                'document_versions_id' => $cp['document_versions_id'],
                'document_version'     => $cp['document_version'],
                'content_sha256'       => $cp['content_sha256'],
            ];
            $lockBefore = (int) $inst->fields['lock_version'];

            $this->beforeTransition($requestId, $action);

            $res = $this->wf->transition($inst, $action, [
                'comment'               => $comment,
                'evidence_ref'          => $ref,
                'fields'                => ['evidence_bound' => 1, 'document_version' => $cp['document_version']],
                'expected_lock_version' => $lockBefore,
                'requester_users_id'    => (int) $req->fields['users_id_requester'],
            ]);

            $this->afterTransition($requestId, $action);

            $ok = $res->success || $res->code === self::R_RECORDED;
            if (!$ok && $res->code !== self::R_DUPLICATE) {
                throw new \RuntimeException('el motor rechazó la decisión: ' . $res->code . ' — ' . $res->message);
            }
            $advanced = (bool) ($res->data['advanced'] ?? false);
            $this->audit->recordOnce($requestId, PurchasingEvent::EV_DECISION, (int) $req->fields['entities_id'], [
                'action' => $action, 'stage' => $state, 'scope' => $evidenceScope, 'result' => $res->code, 'advanced' => $advanced,
                'to' => (string) ($res->data['to'] ?? $state), 'evidence_ref' => $ref, 'comment' => $comment,
            ], (string) $req->fields['correlation_id'],
                'decision:' . $requestId . ':' . (int) (Session::getLoginUserID() ?: 0) . ':' . (int) $inst->getID() . ':' . $lockBefore . ':' . $action);

            $proj = $this->projection->sync($req);
            return [
                'status'               => $res->code,
                'advanced'             => $advanced,
                'state'                => $proj['to'],
                'scope'                => $evidenceScope,
                'document_version'     => $cp['document_version'],
                'document_versions_id' => $cp['document_versions_id'],
                'content_sha256'       => $cp['content_sha256'],
                'ledger_id'            => $cp['ledger_id'],
                'invalidated'          => $invalidated,
                'pdf'                  => $action === 'approve' && $advanced && in_array($state, $policy->pdfStages(), true),
            ];
        });

        // PDF FUERA del lock y de toda transacción: derivado, retryable; su fallo NO revierte nada.
        if (!empty($result['pdf'])) {
            $result['pdf_status'] = $this->composePdf($requestId, (int) $result['ledger_id']);
        }
        return $result;
    }

    /** Punto de inyección de caída para tests (tras registrar la versión, antes de `transition()`). */
    protected function beforeTransition(int $requestId, string $action): void
    {
    }

    /** Punto de inyección de caída para tests (transición CONFIRMADA, antes de auditoría/proyección). */
    protected function afterTransition(int $requestId, string $action): void
    {
    }

    // ================================================================ fachada comercial (Compras)

    /** @param array<string,mixed> $in */
    public function createQuote(int $requestId, array $in): int
    {
        return $this->quotes->createQuote($requestId, $in);
    }

    /** @param array<string,mixed> $in @return array{invalidated:array<int,array<string,mixed>>, error:?string} */
    public function updateQuote(int $quoteId, array $in): array
    {
        $isSelected = $this->quotes->updateQuote($quoteId, $in);
        $q = new \GlpiPlugin\Companypurchasing\Model\Quote();
        $q->getFromDB($quoteId);
        return $isSelected ? $this->enforceIntegrity((int) $q->fields['requests_id']) : ['invalidated' => [], 'error' => null];
    }

    /** @return array{invalidated:array<int,array<string,mixed>>, error:?string} */
    public function selectQuote(int $requestId, int $quoteId, ?int $expectedLockVersion = null): array
    {
        $this->quotes->selectQuote($requestId, $quoteId, $expectedLockVersion);
        return $this->enforceIntegrity($requestId);
    }

    public function attachQuoteDocument(int $quoteId, int $documentsId): bool
    {
        return $this->quotes->attachDocument($quoteId, $documentsId);
    }

    /** @return array{invalidated:array<int,array<string,mixed>>, error:?string} */
    public function amendLineQuantity(int $lineId, mixed $quantity): array
    {
        $r = $this->requests->amendLineQuantity($lineId, $quantity);
        return $this->enforceIntegrity((int) $r['requests_id']);
    }

    public function quotes(): QuoteManager
    {
        return $this->quotes;
    }

    // ================================================================ integridad por scope

    /**
     * Repara la integridad (marcas durables + deriva). No lanza: la mutación local ya está confirmada y su
     * marca PERSISTE si la reparación falla; `decide()` la reparará (o fallará cerrado) antes de cualquier
     * decisión, y `integrityStatus()` la reporta como NO íntegramente aprobada mientras tanto.
     *
     * @return array{invalidated:array<int,array<string,mixed>>, error:?string}
     */
    public function enforceIntegrity(int $requestId): array
    {
        try {
            $inv = (array) $this->lock->withRequestLock($requestId, function () use ($requestId): array {
                $req = $this->loadRequest($requestId);
                $inst = $this->instanceFor($req, false);
                if ($inst === null || !$this->wf->isOpen($inst)) {
                    return [];
                }
                return $this->enforceIntegrityLocked($req, $inst);
            });
            return ['invalidated' => $inv, 'error' => null];
        } catch (\Throwable $e) {
            return ['invalidated' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Estado de integridad SIN efectos (sólo lectura): marcas durables pendientes + deriva exacta respecto
     * del ledger del motor. `fully_approved` exige APPROVED en el motor Y ausencia de marcas y de deriva.
     *
     * Servicio INTERNO sin ACL propia (sólo devuelve códigos de estado/scope, ningún dato de negocio): un
     * controlador/UI que lo exponga debe aplicar antes la ACL de vista de la solicitud (`canView`).
     *
     * @return array{state:string, dirty_scopes:array<int,string>, drift:array<string,string>, clean:bool, fully_approved:bool}
     */
    public function integrityStatus(int $requestId): array
    {
        $req = $this->loadRequest($requestId);
        $inst = $this->instanceFor($req, false);
        $marks = $this->integrity->pending($requestId);
        $state = $inst !== null ? $this->wf->stateCode($inst) : '';
        $drift = [];
        if ($inst !== null) {
            foreach ($this->analyze($req, $inst, $this->policies->forRequest($req)) as $scope => $a) {
                if ($a['drift']) {
                    $drift[$scope] = $a['reason'];
                }
            }
        }
        $clean = $marks === [] && $drift === [] && (string) ($req->fields['integrity_state'] ?? IntegrityLedger::CLEAN) === IntegrityLedger::CLEAN;
        return [
            'state'          => $state,
            'dirty_scopes'   => array_keys($marks),
            'drift'          => $drift,
            'clean'          => $clean,
            'fully_approved' => $clean && $inst !== null && $this->wf->isOpen($inst) && $state === PurchasingWorkflow::S_APPROVED,
        ];
    }

    public function isFullyApproved(int $requestId): bool
    {
        return $this->integrityStatus($requestId)['fully_approved'];
    }

    /**
     * Núcleo (bajo el lock de la solicitud). Recorre los scopes del checkpoint MÁS TEMPRANO al más tardío
     * (REQUEST_SCOPE primero). Sin deriva ⇒ resuelve sus marcas como verificadas. Con deriva ⇒ nueva versión
     * (si es producible) + `invalidateApprovals()`; sólo si el motor la CONFIRMA se resuelven las marcas del
     * scope y de los posteriores (reabrir un checkpoint anterior los reinicia). Si falla ⇒ lanza y las marcas
     * persisten.
     *
     * @return array<int,array<string,mixed>> invalidaciones aplicadas
     */
    protected function enforceIntegrityLocked(Request $req, Instance $inst): array
    {
        $requestId = (int) $req->getID();
        $policy    = $this->policies->forRequest($req);
        $marks     = $this->integrity->pending($requestId); // snapshot: scope → id máximo visto
        $maxMark   = $marks === [] ? 0 : max($marks);
        $ordered   = $policy->orderedCheckpoints();

        foreach ($this->analyze($req, $inst, $policy) as $scope => $a) {
            if (!$a['drift']) {
                if (isset($marks[$scope])) {
                    $this->integrity->resolve($requestId, [$scope], $marks[$scope], 'verified:' . $a['reason']);
                }
                continue;
            }

            // Deriva ⇒ nueva versión del scope (si es producible) + invalidación idempotente.
            $checkpoint = $a['checkpoint'];
            $version = 0;
            if ($a['semantic'] !== null) {
                $cp = $this->recordCheckpointLocked($req, $scope, $a['semantic']);
                $version = $cp['document_version'];
                $key = 'cpur:' . $requestId . ':' . $scope . ':v' . $version;
            } else {
                $key = 'cpur:' . $requestId . ':' . $scope . ':unbuildable:h' . $a['last_live_id'];
            }
            $res = $this->wf->invalidate((int) $inst->getID(), "scope {$scope} changed after approval ({$a['reason']})", [
                'idempotency_key'  => $key,
                'reopen_to_code'   => $checkpoint,
                'subject_type'     => Request::class,
                'subject_id'       => $requestId,
                'document_version' => $version,
            ], (int) $inst->fields['lock_version']);
            if (!$res->success) {
                throw new \RuntimeException('no se pudo invalidar el scope ' . $scope . ': ' . $res->code . ' — ' . $res->message);
            }
            // Invalidación CONFIRMADA ⇒ recién ahora se resuelven las marcas (este scope y los posteriores).
            $cpIndex = PurchasingWorkflow::stageIndex($checkpoint);
            $reset = array_keys(array_filter($ordered, static fn (string $c): bool => PurchasingWorkflow::stageIndex($c) >= $cpIndex));
            $this->integrity->resolve($requestId, $reset, $maxMark, 'invalidated:' . $key);
            $this->audit->recordOnce($requestId, PurchasingEvent::EV_SCOPE_INVALIDATED, (int) $req->fields['entities_id'], [
                'scope' => $scope, 'reopen_to' => $checkpoint, 'document_version' => $version, 'reason' => $a['reason'],
                'idempotency_key' => $key, 'idempotent' => (bool) ($res->data['idempotent'] ?? false),
            ], (string) $req->fields['correlation_id'], 'invalidated:' . $key);
            $this->projection->sync($req);
            return [['scope' => $scope, 'reopen_to' => $checkpoint, 'document_version' => $version, 'idempotency_key' => $key, 'reason' => $a['reason']]];
        }
        return [];
    }

    /**
     * Análisis SIN efectos de cada scope (orden de prioridad): aprobaciones vivas según el ledger del motor y
     * si alguna NO coincide EXACTAMENTE con el ledger de Compras y con el contenido actual.
     *
     * @return array<string,array{checkpoint:string, drift:bool, reason:string, semantic:?array, last_live_id:int}>
     */
    protected function analyze(Request $req, Instance $inst, ApprovalPolicy $policy): array
    {
        $requestId = (int) $req->getID();
        $history = $this->wf->history((int) $inst->getID());
        $out = [];
        foreach ($policy->orderedCheckpoints() as $scope => $checkpoint) {
            $live = PurchasingWorkflow::liveDecisions($history, $scope, $checkpoint, $policy->stageScopes());
            if ($live === []) {
                $out[$scope] = ['checkpoint' => $checkpoint, 'drift' => false, 'reason' => 'no_live_approvals', 'semantic' => null, 'last_live_id' => 0];
                continue;
            }
            $semantic = null;
            $currentSha = '';
            try {
                $semantic = $this->semantic($req, $scope);
                $currentSha = DocumentVersionAllocator::payloadHash($semantic);
            } catch (\Throwable) {
                $semantic = null; // contenido NO producible (p. ej. proveedor ya no aplicable) ⇒ deriva
            }
            $reason = $semantic === null ? 'unbuildable' : 'consistent';
            foreach ($live as $d) {
                $ref = $d['meta']['evidence_ref'] ?? null;
                $ver = is_array($ref) ? (int) ($ref['document_version'] ?? 0) : 0;
                $row = $ver > 0 ? $this->alloc->findByVersion($requestId, $ver) : null;
                // La referencia de la aprobación debe coincidir EXACTAMENTE con la del ledger propio (tres claves
                // + scope); si no, la aprobación no es íntegra (evidencia alterada/incompleta/ajena).
                if (!PurchasingWorkflow::evidenceRefMatches($ref, $row, $scope, $row !== null ? (string) $row['payload_sha256'] : '')) {
                    $reason = 'evidence_mismatch';
                    break;
                }
                if ($semantic !== null && !hash_equals((string) $row['payload_sha256'], $currentSha)) {
                    $reason = 'content_changed';
                    break;
                }
            }
            $out[$scope] = [
                'checkpoint'   => $checkpoint,
                'drift'        => $reason !== 'consistent',
                'reason'       => $reason,
                'semantic'     => $semantic,
                'last_live_id' => (int) end($live)['id'],
            ];
        }
        return $out;
    }

    // ================================================================ checkpoint / snapshot

    /**
     * Versión documental del scope: reutiliza (mismo contenido) o asigna la siguiente; la registra en
     * Firma (idempotente, FUERA de toda transacción local) y guarda su identidad en el ledger.
     *
     * @param array<string,mixed>|null $semantic
     * @return array{ledger_id:int, document_version:int, document_versions_id:int, content_sha256:string, reused:bool}
     */
    protected function recordCheckpointLocked(Request $req, string $scope, ?array $semantic): array
    {
        $requestId = (int) $req->getID();
        $semantic ??= $this->semantic($req, $scope);
        $sha = DocumentVersionAllocator::payloadHash($semantic);
        $a = $this->alloc->allocateOrReuse($requestId, $scope, $sha, (string) $req->fields['correlation_id']);

        $dv = $this->sig->record(ScopeSnapshotBuilder::envelope($semantic, $a['document_version']));
        if ($dv['document_version'] !== $a['document_version']) {
            throw new \RuntimeException('Firma registró una versión distinta de la asignada por el dominio (fail-closed)');
        }
        $this->alloc->markRecorded($a['id'], $dv['document_versions_id'], $dv['content_sha256']);
        $this->audit->recordOnce($requestId, PurchasingEvent::EV_CHECKPOINT_RECORDED, (int) $req->fields['entities_id'], [
            'scope' => $scope, 'document_version' => $a['document_version'],
            'document_versions_id' => $dv['document_versions_id'], 'content_sha256' => $dv['content_sha256'],
        ], (string) $req->fields['correlation_id'], 'checkpoint:' . $requestId . ':v' . $a['document_version']);

        return [
            'ledger_id'            => $a['id'],
            'document_version'     => $a['document_version'],
            'document_versions_id' => $dv['document_versions_id'],
            'content_sha256'       => $dv['content_sha256'],
            'reused'               => $a['reused'],
        ];
    }

    /** Snapshot SEMÁNTICO del scope (datos frescos; términos comerciales sólo si el scope los exige). */
    protected function semantic(Request $req, string $scope): array
    {
        $req->getFromDB((int) $req->getID());
        $version = (int) ($req->fields['scopes_version'] ?? 0);
        if ($version <= 0) {
            throw new \RuntimeException('la solicitud no tiene scopes pinneados (¿no fue enviada?)');
        }
        $fields = ScopeCatalog::fields($version, $scope);
        $commercial = array_intersect($fields, self::COMMERCIAL_TERM_KEYS) !== []
            ? $this->quotes->commercialTerms($req)
            : null;
        return $this->builder->build($req, $this->requests->loadItems((int) $req->getID()), $scope, $version, $commercial);
    }

    // ================================================================ PDF

    /** Compone el PDF de una versión aprobada. Nunca lanza: registra el resultado (retryable). */
    public function composePdf(int $requestId, int $ledgerId): string
    {
        $row = new DocVersion();
        if (!$row->getFromDB($ledgerId) || (int) $row->fields['requests_id'] !== $requestId) {
            return DocumentVersionAllocator::PDF_ERROR;
        }
        $req = new Request();
        $req->getFromDB($requestId);
        $dvId = (int) $row->fields['document_versions_id'];
        $status = DocumentVersionAllocator::PDF_ERROR;
        $reason = '';
        try {
            if ($dvId <= 0) {
                throw new \RuntimeException('versión sin identidad de Firma');
            }
            $r = $this->sig->composePdf($dvId);
            $status = $r['status'] === DocumentVersionAllocator::PDF_READY && $r['documents_id'] > 0
                ? DocumentVersionAllocator::PDF_READY
                : ($r['status'] === DocumentVersionAllocator::PDF_PENDING ? DocumentVersionAllocator::PDF_PENDING : DocumentVersionAllocator::PDF_ERROR);
        } catch (\Throwable $e) {
            $reason = substr(get_class($e) . ': ' . $e->getMessage(), 0, 250);
        }
        try {
            $this->alloc->markPdf($ledgerId, $status);
            $entity = (int) ($req->fields['entities_id'] ?? 0);
            $corr = (string) ($req->fields['correlation_id'] ?? '');
            $version = (int) $row->fields['document_version'];
            if ($status === DocumentVersionAllocator::PDF_READY) {
                $this->audit->recordOnce($requestId, PurchasingEvent::EV_PDF_READY, $entity, ['document_version' => $version], $corr, 'pdf:' . $requestId . ':v' . $version);
            } else {
                $this->audit->record($requestId, PurchasingEvent::EV_PDF_FAILED, $entity, ['document_version' => $version, 'status' => $status, 'reason' => $reason], $corr);
            }
        } catch (\Throwable) {
            // best-effort: el PDF jamás compromete aprobación/evidencia.
        }
        return $status;
    }

    /** Reintenta los PDF en error/pendientes de la solicitud (idempotente en Firma). @return array<int,string> */
    public function retryPdf(int $requestId): array
    {
        $req = $this->loadRequest($requestId);
        if (!$this->requests->canView($req)) {
            throw new \RuntimeException('sin permiso para ver la solicitud');
        }
        $out = [];
        foreach ($this->alloc->all($requestId) as $row) {
            if (in_array((string) $row['pdf_status'], [DocumentVersionAllocator::PDF_ERROR, DocumentVersionAllocator::PDF_PENDING], true)) {
                $out[(int) $row['document_version']] = $this->composePdf($requestId, (int) $row['id']);
            }
        }
        return $out;
    }

    // ================================================================ reconciliación

    /**
     * Converge la proyección con el motor (el motor gana) y REPORTA anomalías que no puede reparar sin una
     * sesión con RIGHT_ACT: solicitud ENVIADA sin instancia (`orphan`) e integridad pendiente (`dirty`).
     * Idempotente.
     *
     * @return array{state:string, corrected:bool, linked:bool, orphan:bool, dirty:bool}
     */
    public function reconcile(int $requestId): array
    {
        $req = $this->loadRequest($requestId);
        $r = $this->projection->sync($req, 'reconcile');
        $req->getFromDB($requestId);
        $submitted = (int) ($req->fields['number_seq'] ?? 0) > 0;
        return [
            'state'     => $r['to'],
            'corrected' => $r['changed'] && $r['from'] !== $r['to'],
            'linked'    => $r['linked'],
            'orphan'    => $submitted && !$r['instance'],
            'dirty'     => $this->integrity->pending($requestId) !== [],
        ];
    }

    /**
     * Reconciliación por LOTES sin starvation: recorre las solicitudes no-DRAFT en orden de id a partir de un
     * CURSOR persistido, con wrap-around al llegar al final (cada ejecución avanza; ninguna solicitud queda
     * eternamente sin revisar aunque haya más que `$limit`).
     *
     * @return array{checked:int, corrected:int, errors:int, orphans:array<int,int>, dirty:array<int,int>, cursor:int, wrapped:bool}
     */
    public function reconcileAll(int $limit = 500): array
    {
        $limit = max(1, $limit);
        $cursor = max(0, (int) PluginConfig::get('reconcile_cursor', '0'));
        $after = $this->candidateIds($cursor, PHP_INT_MAX, $limit);
        $fromStart = count($after) < $limit ? $this->candidateIds(0, $cursor, $limit - count($after)) : [];
        $plan = self::planBatch($after, $fromStart, $cursor);

        $stats = ['checked' => 0, 'corrected' => 0, 'errors' => 0, 'orphans' => [], 'dirty' => [], 'cursor' => $plan['cursor'], 'wrapped' => $plan['wrapped']];
        foreach ($plan['ids'] as $id) {
            $stats['checked']++;
            try {
                $r = $this->reconcile($id);
                if ($r['corrected']) {
                    $stats['corrected']++;
                }
                if ($r['orphan']) {
                    $stats['orphans'][] = $id;
                }
                if ($r['dirty']) {
                    $stats['dirty'][] = $id;
                }
            } catch (\Throwable) {
                $stats['errors']++;
            }
        }
        Config::setConfigurationValues(PluginConfig::CONTEXT, ['reconcile_cursor' => (string) $plan['cursor']]);
        return $stats;
    }

    /**
     * PURO: compone el lote a partir de los ids posteriores al cursor y (si no alcanzaron) los del inicio
     * hasta el cursor (wrap-around). El nuevo cursor es el último id revisado (0 si no hubo ninguno).
     *
     * @param array<int,int> $afterCursor  ids > cursor, ascendentes
     * @param array<int,int> $fromStart    ids ≤ cursor, ascendentes (wrap-around)
     * @return array{ids:array<int,int>, cursor:int, wrapped:bool}
     */
    public static function planBatch(array $afterCursor, array $fromStart, int $cursor): array
    {
        $ids = array_values(array_merge($afterCursor, array_filter($fromStart, static fn (int $i): bool => $i <= $cursor && !in_array($i, $afterCursor, true))));
        return ['ids' => $ids, 'cursor' => $ids === [] ? 0 : (int) end($ids), 'wrapped' => $fromStart !== []];
    }

    /** @return array<int,int> ids de solicitudes no-DRAFT con `$from < id <= $to`, ascendentes */
    private function candidateIds(int $from, int $to, int $limit): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($limit <= 0) {
            return [];
        }
        $where = [
            'NOT' => ['domain_state' => Request::STATE_DRAFT],
            ['id' => ['>', $from]],
        ];
        if ($to < PHP_INT_MAX) {
            $where[] = ['id' => ['<=', $to]];
        }
        $out = [];
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => Request::getTable(), 'WHERE' => $where, 'ORDER' => 'id ASC', 'LIMIT' => $limit]) as $row) {
            $out[] = (int) $row['id'];
        }
        return $out;
    }

    // ================================================================ internals

    private function loadRequest(int $requestId): Request
    {
        $req = new Request();
        if ($requestId <= 0 || !$req->getFromDB($requestId)) {
            throw new \RuntimeException('solicitud inexistente');
        }
        return $req;
    }

    private function assertEntity(Request $req): void
    {
        if (!Session::haveAccessToEntity((int) $req->fields['entities_id'])) {
            throw new \RuntimeException('sin acceso a la entidad de la solicitud');
        }
    }

    /** Instancia enlazada (o re-enlazada por UNIQUE(itemtype, items_id) tras una caída). */
    private function instanceFor(Request $req, bool $required): ?Instance
    {
        $instId = (int) ($req->fields['workflow_instances_id'] ?? 0);
        $inst = $instId > 0 ? $this->wf->loadInstance($instId) : $this->wf->findInstance(Request::class, (int) $req->getID());
        if ($inst !== null && ((string) $inst->fields['itemtype'] !== Request::class || (int) $inst->fields['items_id'] !== (int) $req->getID())) {
            throw new \RuntimeException('la instancia enlazada no pertenece a la solicitud (fail-closed)');
        }
        if ($inst === null && $required) {
            throw new \RuntimeException('la solicitud no tiene instancia de workflow (¿no fue enviada?)');
        }
        return $inst;
    }
}
