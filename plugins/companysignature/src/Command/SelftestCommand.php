<?php

/**
 * Autotest de INTEGRACIÓN + E2E de companysignature (corre DENTRO de un GLPI arrancado, en CI).
 *
 * Nombre: `plugins:companysignature:selftest`. Fail-closed → exit 1 si algo falla.
 *
 * Cobertura (gate §11/§14; domain-agnostic, sin Compras):
 *   [PERSIST]     tablas propias creadas por la migración.
 *   [ROUTES]      GET /verify/{token} AUTHENTICATED.
 *   [WIRING]      listeners de companyworkflow registrados en $PLUGIN_HOOKS.
 *   [VERSION]     versión inmutable + idempotente; violar inmutabilidad → falla; cambio → hash nuevo.
 *   [EVIDENCE]    listener registra evidencia de decisión; evento duplicado → UNA sola evidencia.
 *   [INVALIDATE]  invalidación (vía companyworkflow) conserva la aprobación (append-only) e idempotente.
 *   [PDF]         PDF aprobado como Document nativo (hashes separados) + regeneración idempotente;
 *                 fallo de PDF tras aprobación → evidencia permanece + reintento posible.
 *   [VERIFY]      verificación interna: hash recomputado coincide; multi-entidad no filtra; token
 *                 inválido no filtra; sin ACL → denied; tras invalidación refleja `invalidated`.
 *   [TAMPER]      manipular el hash almacenado → verificación `tampered`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Command;

use Computer;
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
use GlpiPlugin\Companysignature\Service\VerificationService;
use GlpiPlugin\Companysignature\Service\VersionStore;
use GlpiPlugin\Companysignature\Service\WorkflowEventListener;
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
    private array $createdEntities = [];
    private array $createdComputers = [];

    private int $entityA = 0;
    private int $entityB = 0;
    private int $user = 0;

    protected function configure(): void
    {
        $this->setName('plugins:companysignature:selftest')
            ->setDescription('Pruebas de integración + E2E de firma/evidencia (hash, versionado, invalidación, verificación, PDF).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $this->suffix = substr((string) time(), -6);

        $this->checkPersistence();
        $this->checkRoutes();
        $this->checkWiring();
        $this->buildFixtures();

        $this->scenarioImmutableVersioning();
        $this->scenarioEvidenceAndPdfAndVerify();
        $this->scenarioTamper();

        $this->cleanup();

        if ($this->failures > 0) {
            $output->writeln(sprintf('<error>SELFTEST: %d comprobación(es) fallida(s).</error>', $this->failures));
            return Command::FAILURE;
        }
        $output->writeln('<info>SELFTEST: todas las comprobaciones pasaron.</info>');
        return Command::SUCCESS;
    }

    // ------------------------------------------------------------------ [PERSIST] / [ROUTES] / [WIRING]

    private function checkPersistence(): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $this->out->writeln('== [PERSIST] tablas propias ==');
        foreach (['evidences', 'document_versions'] as $t) {
            $this->check("tabla glpi_plugin_companysignature_{$t} existe", $DB->tableExists("glpi_plugin_companysignature_{$t}"));
        }
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
        $this->out->writeln('== [WIRING] listeners de companyworkflow ==');
        $t = $PLUGIN_HOOKS['companyworkflow:transitioned']['companysignature'] ?? null;
        $i = $PLUGIN_HOOKS['companyworkflow:approval_invalidated']['companysignature'] ?? null;
        $this->check('listener :transitioned registrado', $t === 'plugin_companysignature_on_transitioned');
        $this->check('listener :approval_invalidated registrado', $i === 'plugin_companysignature_on_approval_invalidated');
    }

    // ------------------------------------------------------------------ fixtures

    private function buildFixtures(): void
    {
        $this->out->writeln('== fixtures (entidades/usuario) ==');
        $this->applySession(2, [0], [
            'entity' => ALLSTANDARDRIGHT, 'user' => ALLSTANDARDRIGHT,
            'computer' => ALLSTANDARDRIGHT, 'profile' => ALLSTANDARDRIGHT,
        ], 1);

        $this->entityA = (int) (new Entity())->add(['name' => 'SIG-A-' . $this->suffix, 'entities_id' => 0]);
        $this->entityB = (int) (new Entity())->add(['name' => 'SIG-B-' . $this->suffix, 'entities_id' => 0]);
        $this->createdEntities = array_filter([$this->entityA, $this->entityB]);
        $this->check('entidades A y B creadas', $this->entityA > 0 && $this->entityB > 0);

        $this->user = (int) (new User())->add(['name' => 'sig_u_' . $this->suffix, 'realname' => 'SIG U', '_no_history' => true]);
        if ($this->user > 0) {
            $this->createdUsers[] = $this->user;
            (new Profile_User())->add(['users_id' => $this->user, 'profiles_id' => 1, 'entities_id' => $this->entityA, 'is_recursive' => 0]);
        }
        $this->check('usuario de prueba creado', $this->user > 0);
    }

    private function makeComputer(int $entityId): int
    {
        $this->applySession(2, [0, $entityId], ['computer' => ALLSTANDARDRIGHT], 1);
        $id = (int) (new Computer())->add(['name' => 'SIG-ITEM-' . $this->suffix . '-' . random_int(1000, 9999), 'entities_id' => $entityId]);
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

    // ------------------------------------------------------------------ [VERSION]

    private function scenarioImmutableVersioning(): void
    {
        $this->out->writeln('== [VERSION] versión inmutable + idempotente (D1) ==');
        $api = new SignatureApi();
        $store = new VersionStore();
        $c = $this->makeComputer($this->entityA);
        $this->applySession($this->user, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);

        $dv1 = $api->recordDocumentVersion($this->snap($c, 1, 'X'), true);
        $this->check('[VERSION] v1 creada', $dv1->getID() > 0 && $dv1->versionNumber() === 1 && strlen($dv1->contentHash()) === 64);

        $dv1b = $api->recordDocumentVersion($this->snap($c, 1, 'X'), true);
        $this->check('[VERSION] idempotente (mismo contenido → misma fila)', $dv1b->getID() === $dv1->getID());

        // Violar inmutabilidad: misma versión, contenido distinto → falla.
        $threw = false;
        try {
            $api->recordDocumentVersion($this->snap($c, 1, 'DIFERENTE'), true);
        } catch (\InvalidArgumentException) {
            $threw = true;
        }
        $this->check('[VERSION] inmutabilidad: misma versión con otro contenido → excepción', $threw);

        $dv2 = $api->recordDocumentVersion($this->snap($c, 2, 'Y'), true);
        $this->check('[VERSION] v2 con hash distinto', $dv2->versionNumber() === 2 && $dv2->contentHash() !== $dv1->contentHash());
        $this->check('[VERSION] latest = v2', ($store->latest('Computer', $c)?->versionNumber()) === 2);
        // La v1 anterior NO se modificó.
        $dv1->getFromDB($dv1->getID());
        $this->check('[VERSION] v1 no fue modificada por v2', $dv1->versionNumber() === 1);
    }

    // ------------------------------------------------------------------ [EVIDENCE] / [PDF] / [VERIFY] / [INVALIDATE]

    private function scenarioEvidenceAndPdfAndVerify(): void
    {
        $this->out->writeln('== [EVIDENCE]/[PDF]/[VERIFY]/[INVALIDATE] ciclo E2E ==');
        if (!class_exists('GlpiPlugin\\Companyworkflow\\Api\\WorkflowApi')) {
            $this->check('[E2E] companyworkflow disponible (dependencia de integración)', false);
            return;
        }

        $wfApiClass = 'GlpiPlugin\\Companyworkflow\\Api\\WorkflowApi';
        $stateDef   = 'GlpiPlugin\\Companyworkflow\\Model\\StateDef';
        $wfApi = new $wfApiClass();
        $sigApi = new SignatureApi();
        $listener = new WorkflowEventListener();

        $c = $this->makeComputer($this->entityA);

        // Definición de workflow mínima (neutral) + instancia OPEN sobre el Computer.
        $this->applySession(2, [0, $this->entityA], ['plugin_companyworkflow' => ALLSTANDARDRIGHT, 'computer' => ALLSTANDARDRIGHT], 1);
        $def = $wfApi->builder()->createVersion([
            'code' => 'sig_wf_' . $this->suffix, 'name' => 'sig demo', 'itemtype_target' => 'Computer',
            'entities_id' => 0, 'is_recursive' => 1,
            'states' => [
                ['code' => 'DRAFT', 'kind' => $stateDef::KIND_INITIAL, 'is_editable' => 1],
                ['code' => 'PENDING', 'kind' => $stateDef::KIND_INTERMEDIATE],
                ['code' => 'DONE', 'kind' => $stateDef::KIND_FINAL],
            ],
            'transitions' => [
                ['from' => 'DRAFT', 'to' => 'PENDING', 'action' => 'submit'],
                ['from' => 'PENDING', 'to' => 'DONE', 'action' => 'approve'],
            ],
        ]);
        $this->applySession($this->user, [$this->entityA], ['plugin_companyworkflow' => READ, 'plugin_companysignature' => ALLSTANDARDRIGHT]);
        $instance = $wfApi->startInstance($def, 'Computer', $c, $this->entityA, 0);
        if ($instance === null) {
            $this->check('[E2E] instancia de workflow creada', false);
            return;
        }
        $instanceId = (int) $instance->getID();

        // El "dominio" registra la versión documental aprobada.
        $dv1 = $sigApi->recordDocumentVersion($this->snap($c, 1, 'X'), true);

        // (a) EVIDENCIA de decisión vía listener + idempotencia (evento duplicado → 1 evidencia).
        $payload = ['instances_id' => $instanceId, 'from' => 'PENDING', 'to' => 'DONE', 'action' => 'approve', 'actor' => $this->user, 'comment' => 'ok'];
        $e1 = $listener->onTransitioned($payload);
        $e1b = $listener->onTransitioned($payload); // duplicado
        $this->check('[EVIDENCE] evidencia de aprobación registrada', $e1 !== null && (string) $e1->fields['decision'] === ApprovalEvidence::DECISION_APPROVED);
        $this->check('[EVIDENCE] idempotente: evento duplicado → misma evidencia', $e1 !== null && $e1b !== null && $e1->getID() === $e1b->getID());
        $this->check('[EVIDENCE] 1 sola evidencia approved para el sujeto', $this->countEvidence($c, ApprovalEvidence::DECISION_APPROVED) === 1);
        $token = (string) ($e1->fields['verification_token'] ?? '');
        $this->check('[EVIDENCE] token opaco bien formado', \GlpiPlugin\Companysignature\Service\TokenGenerator::isWellFormed($token));
        $this->check('[EVIDENCE] content_sha256 = hash de la v1', (string) $e1->fields['content_sha256'] === $dv1->contentHash());

        // (b) PDF como Document nativo (D1) + idempotencia.
        $docCountBefore = $this->countDocuments($c);
        $dv1 = $sigApi->composePdf((int) $dv1->getID());
        $this->check('[PDF] pdf_status=ready', (string) $dv1->fields['pdf_status'] === DocumentVersion::PDF_READY);
        $docId = (int) $dv1->fields['documents_id'];
        $this->check('[PDF] Document nativo creado', $docId > 0 && (new Document())->getFromDB($docId));
        $this->check('[PDF] pdf_sha256 (64 hex) separado del content', strlen((string) $dv1->fields['pdf_sha256']) === 64 && (string) $dv1->fields['pdf_sha256'] !== $dv1->contentHash());
        $this->check('[PDF] Document_Item enlaza al sujeto', (new Document_Item())->getFromDBByCrit(['documents_id' => $docId, 'itemtype' => 'Computer', 'items_id' => $c]));
        $dv1b = $sigApi->composePdf((int) $dv1->getID()); // idempotente
        $this->check('[PDF] regeneración idempotente (mismo Document)', (int) $dv1b->fields['documents_id'] === $docId);
        $this->check('[PDF] no duplica Document', $this->countDocuments($c) === $docCountBefore + 1);

        // (b2) PDF falla tras aprobación → la evidencia permanece; se puede reintentar.
        (new VersionStore())->markPdfError((int) $dv1->getID());
        $dv1->getFromDB((int) $dv1->getID());
        $this->check('[PDF] error simulado: pdf_status=error', (string) $dv1->fields['pdf_status'] === DocumentVersion::PDF_ERROR);
        $this->check('[PDF] la evidencia sobrevive al fallo del PDF', $this->countEvidence($c, ApprovalEvidence::DECISION_APPROVED) === 1);
        $dv1 = $sigApi->composePdf((int) $dv1->getID()); // reintento
        $this->check('[PDF] reintento → ready de nuevo', (string) $dv1->fields['pdf_status'] === DocumentVersion::PDF_READY);

        // (c) VERIFY interno: hash recomputado coincide → valid.
        $this->applySession($this->user, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $res = $sigApi->verify($token);
        $this->check('[VERIFY] token válido → valid', $res['status'] === VerificationService::STATUS_VALID);
        $this->check('[VERIFY] proyección incluye actor/fecha', ($res['evidence']['actor_users_id'] ?? 0) === $this->user && ($res['evidence']['event_date_utc'] ?? '') !== '');

        // multi-entidad: verificador en entidad B no ve evidencia de A → not_found (no fuga).
        $this->applySession($this->user, [$this->entityB], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $resB = $sigApi->verify($token);
        $this->check('[VERIFY] 🔒 entidad B no verifica evidencia de A → not_found', $resB['status'] === VerificationService::STATUS_NOT_FOUND && $resB['evidence'] === null);

        // token inexistente (bien formado) → not_found sin filtrar.
        $this->applySession($this->user, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $resX = $sigApi->verify(str_repeat('a', 40));
        $this->check('[VERIFY] token inexistente → not_found', $resX['status'] === VerificationService::STATUS_NOT_FOUND);

        // sin ACL de verificación → denied.
        $this->applySession($this->user, [$this->entityA], ['plugin_companysignature' => READ]);
        $resD = $sigApi->verify($token);
        $this->check('[VERIFY] 🔒 sin RIGHT_VERIFY → denied', $resD['status'] === VerificationService::STATUS_DENIED);

        // (d) INVALIDACIÓN vía companyworkflow (append-only) + idempotente + verify refleja invalidated.
        // Restaurar permisos plenos ANTES de registrar la v2 (la prueba anterior dejó sólo READ).
        $this->applySession($this->user, [$this->entityA], ['plugin_companyworkflow' => READ, 'plugin_companysignature' => ALLSTANDARDRIGHT]);
        $sigApi->recordDocumentVersion($this->snap($c, 2, 'Y'), true); // cambio sustantivo (v2)
        $wfRes = $wfApi->invalidateApprovals($instanceId, 'contenido cambió', ['idempotency_key' => 'sig-inv-' . $this->suffix, 'subject_type' => 'Computer', 'subject_id' => $c]);
        $this->check('[INVALIDATE] companyworkflow reabrió (OK)', $wfRes->success);
        // El hook debió registrar la invalidación; reforzamos idempotencia con una llamada directa.
        $invPayload = ['instances_id' => $instanceId, 'reason' => 'contenido cambió', 'from' => 'PENDING', 'to' => 'DRAFT', 'idempotency_key' => 'sig-inv-' . $this->suffix, 'actor' => $this->user];
        $listener->onApprovalInvalidated($invPayload);
        $this->check('[INVALIDATE] evidencia de aprobación CONSERVADA (append-only)', $this->countEvidence($c, ApprovalEvidence::DECISION_APPROVED) === 1);
        $this->check('[INVALIDATE] 1 sola evidencia de invalidación (idempotente)', $this->countEvidence($c, ApprovalEvidence::DECISION_INVALIDATED) === 1);
        $inv = $this->latestEvidence($c, ApprovalEvidence::DECISION_INVALIDATED);
        $this->check('[INVALIDATE] invalidación referencia a la aprobación previa', $inv !== null && (int) $inv->fields['references_evidences_id'] === (int) $e1->getID());

        // verify del token de aprobación ahora refleja invalidated.
        $this->applySession($this->user, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $resInv = $sigApi->verify($token);
        $this->check('[VERIFY] tras invalidación → invalidated', $resInv['status'] === VerificationService::STATUS_INVALIDATED);
    }

    // ------------------------------------------------------------------ [TAMPER]

    private function scenarioTamper(): void
    {
        $this->out->writeln('== [TAMPER] manipulación del hash → verificación tampered ==');
        /** @var \DBmysql $DB */
        global $DB;
        $sigApi = new SignatureApi();
        $recorder = new EvidenceRecorder();
        $c = $this->makeComputer($this->entityA);

        $this->applySession($this->user, [$this->entityA], ['plugin_companysignature' => ALLSTANDARDRIGHT]);
        $dv = $sigApi->recordDocumentVersion($this->snap($c, 1, 'TAMPER'), true);
        // Evidencia directa (sin workflow) para aislar el chequeo de integridad.
        $ev = $recorder->record([
            'idempotency_key'      => 'tamper-' . $this->suffix,
            'subject_itemtype'     => 'Computer',
            'subject_items_id'     => $c,
            'entities_id'          => $this->entityA,
            'document_versions_id' => (int) $dv->getID(),
            'document_version'     => 1,
            'content_sha256'       => $dv->contentHash(),
            'actor_users_id'       => $this->user,
            'decision'             => ApprovalEvidence::DECISION_APPROVED,
            'event_type'           => ApprovalEvidence::EVENT_DECISION,
        ]);
        $this->check('[TAMPER] evidencia base creada', $ev !== null);
        $token = $ev !== null ? (string) $ev->fields['verification_token'] : '';

        // Verificación previa: valid.
        $ok = $sigApi->verify($token);
        $this->check('[TAMPER] antes de manipular → valid', $ok['status'] === VerificationService::STATUS_VALID);

        // Corromper el content_sha256 almacenado de la versión (simula manipulación en BD).
        $DB->update(DocumentVersion::getTable(), ['content_sha256' => str_repeat('0', 64)], ['id' => (int) $dv->getID()]);
        $bad = $sigApi->verify($token);
        $this->check('[TAMPER] hash no recomputa → tampered', $bad['status'] === VerificationService::STATUS_TAMPERED);
    }

    // ------------------------------------------------------------------ helpers

    private function countEvidence(int $subjectId, string $decision): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        $n = 0;
        foreach ($DB->request(['COUNT' => 'c', 'FROM' => ApprovalEvidence::getTable(), 'WHERE' => ['subject_itemtype' => 'Computer', 'subject_items_id' => $subjectId, 'decision' => $decision]]) as $row) {
            $n = (int) $row['c'];
        }
        return $n;
    }

    private function latestEvidence(int $subjectId, string $decision): ?ApprovalEvidence
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => ApprovalEvidence::getTable(), 'WHERE' => ['subject_itemtype' => 'Computer', 'subject_items_id' => $subjectId, 'decision' => $decision], 'ORDER' => 'id DESC', 'LIMIT' => 1]) as $row) {
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
        $_SESSION['glpi_currenttime']            = date('Y-m-d H:i:s');
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
            if (class_exists('GlpiPlugin\\Companyworkflow\\Model\\WorkflowDef')) {
                $wfDefTable = \GlpiPlugin\Companyworkflow\Model\WorkflowDef::getTable();
                foreach ($DB->request(['SELECT' => 'id', 'FROM' => $wfDefTable, 'WHERE' => ['code' => ['LIKE', 'sig\\_wf\\_%' . $this->suffix]]]) as $row) {
                    $DB->delete($wfDefTable, ['id' => (int) $row['id']]);
                }
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
