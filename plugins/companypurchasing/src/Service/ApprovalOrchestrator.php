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
 *      paso local (allocator, ledger, auditoría, proyección) confirma por sí mismo.
 *   2. Serialización por el lock común de la solicitud (`request_<id>`) — no es una transacción de BD.
 *   3. Idempotencia: la versión se REUTILIZA si el contenido del scope no cambió; `recordDocumentVersion`
 *      es idempotente; la transición usa `expected_lock_version`; la invalidación usa `idempotency_key`
 *      derivada de la versión; los eventos de auditoría de la saga usan `recordOnce`.
 *   4. Recuperación: un reintento converge; `reconcile()` corrige la proyección (el motor gana).
 *   5. Integridad por scope (fail-closed): antes de CADA decisión se verifica, contra el LEDGER del motor,
 *      que las aprobaciones VIVAS de cada scope correspondan al contenido ACTUAL; si no → nueva versión +
 *      `invalidateApprovals(reopen_to_code = checkpoint del scope)`. Nunca se aprueba sobre una
 *      aprobación obsoleta.
 *
 * No es `final`: los tests deterministas de caída inyectan fallos en los puntos `beforeTransition()` /
 * `afterTransition()`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

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
        PurchasingWorkflow::validateMaps(PluginConfig::stageScopes(), PluginConfig::scopeCheckpoints(), [ScopeCatalog::SCOPE_REQUEST, ScopeCatalog::SCOPE_COMMERCIAL_FINANCIAL]);
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
     * Envía la solicitud al circuito: (1) paso LOCAL de P2D-1 (número + scopes pinneados, idempotente);
     * (2) inicia/enlaza la instancia del motor; (3) `submit` desde DRAFT/RETURNED; (4) proyecta.
     * Idempotente y reanudable tras una caída en cualquier punto. Devuelve el estado actual del motor.
     */
    public function submit(int $requestId, string $comment = ''): string
    {
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
                $inst = $this->wf->startInstance($def, Request::class, $requestId, (int) $req->fields['entities_id']);
                $this->audit->recordOnce($requestId, PurchasingEvent::EV_WORKFLOW_STARTED, (int) $req->fields['entities_id'], [
                    'instances_id' => (int) $inst->getID(), 'workflowdefs_id' => (int) $def->getID(),
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

    // ================================================================ decisiones

    /**
     * approve | reject | return en la etapa ACTUAL, ligada a evidencia del scope de la etapa.
     *
     * Saga: integridad de scopes → snapshot del scope → versión (reutilizada si no cambió) →
     * `recordDocumentVersion` → `evidence_ref` → `transition(expected_lock_version)` → auditoría →
     * proyección → (fuera del lock) PDF si la etapa lo exige.
     *
     * `$expectedState`: si se indica y la etapa ya cambió (p. ej. reintento tras una caída posterior a la
     * transición) ⇒ no-op idempotente `stage_changed`.
     *
     * @return array<string,mixed>
     */
    public function decide(int $requestId, string $action, string $comment = '', ?string $expectedState = null): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("acción inválida: {$action}");
        }
        $result = (array) $this->lock->withRequestLock($requestId, function () use ($requestId, $action, $comment, $expectedState): array {
            $req  = $this->loadRequest($requestId);
            $this->assertEntity($req);
            $inst = $this->instanceFor($req, true);
            if (!$this->wf->isOpen($inst)) {
                throw new \RuntimeException('la instancia de workflow no está abierta');
            }
            $state = $this->wf->stateCode($inst);
            if ($expectedState !== null && $state !== $expectedState) {
                $this->projection->sync($req);
                return ['status' => 'stage_changed', 'state' => $state, 'advanced' => false, 'pdf' => false];
            }
            $scope = PluginConfig::stageScopes()[$state] ?? null;
            if ($scope === null) {
                throw new \RuntimeException("el estado {$state} no es una etapa de aprobación (fail-closed)");
            }

            // Red de seguridad (fail-closed): invalidar aprobaciones obsoletas ANTES de decidir.
            $invalidated = $this->enforceIntegrityLocked($req, $inst);
            if ($invalidated !== []) {
                $inst = $this->instanceFor($req, true);
                $now = $this->wf->stateCode($inst);
                if ($now !== $state) {
                    return ['status' => 'reopened', 'state' => $now, 'advanced' => false, 'pdf' => false, 'invalidated' => $invalidated];
                }
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
                'pdf'                  => $action === 'approve' && $advanced && in_array($state, PluginConfig::pdfStages(), true),
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
     * Re-evalúa la integridad de los scopes aprobados tras una mutación (no lanza: la mutación local ya
     * está confirmada; si no se pudo invalidar ahora —p. ej. la sesión carece de RIGHT_ACT— la red de
     * seguridad de `decide()` lo hará antes de la próxima decisión).
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
     * Núcleo (bajo el lock de la solicitud). Recorre los scopes del checkpoint MÁS TEMPRANO al más tardío;
     * al invalidar uno se detiene (reabrir un checkpoint anterior reinicia los posteriores).
     *
     * @return array<int,array<string,mixed>> invalidaciones aplicadas
     */
    protected function enforceIntegrityLocked(Request $req, Instance $inst): array
    {
        $requestId   = (int) $req->getID();
        $stageScopes = PluginConfig::stageScopes();
        $checkpoints = PluginConfig::scopeCheckpoints();
        uasort($checkpoints, static fn (string $a, string $b): int => PurchasingWorkflow::stageIndex($a) <=> PurchasingWorkflow::stageIndex($b));
        $history = $this->wf->history((int) $inst->getID());

        foreach ($checkpoints as $scope => $checkpoint) {
            $live = PurchasingWorkflow::liveDecisions($history, $scope, $checkpoint, $stageScopes);
            if ($live === []) {
                continue;
            }
            // Contenido ACTUAL del scope (no producible ⇒ deriva, fail-closed).
            $semantic = null;
            $currentSha = '';
            try {
                $semantic = $this->semantic($req, $scope);
                $currentSha = DocumentVersionAllocator::payloadHash($semantic);
            } catch (\Throwable) {
                $semantic = null;
            }
            $drift = $semantic === null;
            foreach ($live as $d) {
                $ver = (int) ($d['meta']['evidence_ref']['document_version'] ?? 0);
                $row = $ver > 0 ? $this->alloc->findByVersion($requestId, $ver) : null;
                if ($row === null || (string) $row['scope_key'] !== $scope || !hash_equals((string) $row['payload_sha256'], $currentSha)) {
                    $drift = true;
                    break;
                }
            }
            if (!$drift) {
                continue;
            }

            // Deriva ⇒ nueva versión del scope (si es producible) + invalidación idempotente.
            $version = 0;
            if ($semantic !== null) {
                $cp = $this->recordCheckpointLocked($req, $scope, $semantic);
                $version = $cp['document_version'];
                $key = 'cpur:' . $requestId . ':' . $scope . ':v' . $version;
            } else {
                $key = 'cpur:' . $requestId . ':' . $scope . ':unbuildable:h' . (int) end($live)['id'];
            }
            $res = $this->wf->invalidate((int) $inst->getID(), "scope {$scope} changed after approval", [
                'idempotency_key'  => $key,
                'reopen_to_code'   => $checkpoint,
                'subject_type'     => Request::class,
                'subject_id'       => $requestId,
                'document_version' => $version,
            ], (int) $inst->fields['lock_version']);
            if (!$res->success) {
                throw new \RuntimeException('no se pudo invalidar el scope ' . $scope . ': ' . $res->code . ' — ' . $res->message);
            }
            $this->audit->recordOnce($requestId, PurchasingEvent::EV_SCOPE_INVALIDATED, (int) $req->fields['entities_id'], [
                'scope' => $scope, 'reopen_to' => $checkpoint, 'document_version' => $version,
                'idempotency_key' => $key, 'idempotent' => (bool) ($res->data['idempotent'] ?? false),
            ], (string) $req->fields['correlation_id'], 'invalidated:' . $key);
            $this->projection->sync($req);
            return [['scope' => $scope, 'reopen_to' => $checkpoint, 'document_version' => $version, 'idempotency_key' => $key]];
        }
        return [];
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
     * Converge la proyección con el motor (el motor gana) y REPORTA derivas de scope (no invalida: eso
     * exige una sesión con RIGHT_ACT y lo hace `decide()`/`enforceIntegrity()`). Idempotente.
     *
     * @return array{state:string, corrected:bool, linked:bool}
     */
    public function reconcile(int $requestId): array
    {
        $req = $this->loadRequest($requestId);
        $r = $this->projection->sync($req, 'reconcile');
        return ['state' => $r['to'], 'corrected' => $r['changed'] && $r['from'] !== $r['to'], 'linked' => $r['linked']];
    }

    /** Reconciliación masiva (comando/cron): solicitudes ya enviadas. @return array{checked:int, corrected:int, errors:int} */
    public function reconcileAll(int $limit = 500): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $stats = ['checked' => 0, 'corrected' => 0, 'errors' => 0];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => Request::getTable(),
            'WHERE'  => ['NOT' => ['domain_state' => Request::STATE_DRAFT]],
            'ORDER'  => 'id ASC',
            'LIMIT'  => max(1, $limit),
        ]) as $row) {
            $stats['checked']++;
            try {
                if ($this->reconcile((int) $row['id'])['corrected']) {
                    $stats['corrected']++;
                }
            } catch (\Throwable) {
                $stats['errors']++;
            }
        }
        return $stats;
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
