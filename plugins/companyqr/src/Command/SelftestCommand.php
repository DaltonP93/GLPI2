<?php

/**
 * Autotest de integración de companyqr (corre DENTRO de un GLPI arrancado, en CI).
 *
 * Nombre: `plugins:companyqr:selftest` (patrón exigido por GLPI para comandos de plugin).
 *
 * Evidencia OBLIGATORIA (fail-closed → exit 1 si algo falla):
 *   [ROUTES]      rutas declaradas: /scan/{token} AUTHENTICATED, /public/{token} NO_CHECK.
 *   [ACL]         🔒 usuario de la entidad A escaneando un activo de la entidad B → DENEGADO.
 *   [ACL]         usuario de la entidad B (con permiso) → RESUELTO.
 *   [NO-LEAK]     🔒 la vista NO contiene IP/MAC/hostname/responsable/VLAN/ubicación restringida.
 *   [ANON-OFF]    modo anónimo apagado → login_required (sin fuga).
 *   [LIFECYCLE]   rotar invalida el token viejo; revocar → "no disponible".
 *   [LABEL]       genera un PDF real 70,75×24 (evidencia; CI lo sube como artefacto).
 * Probe NO fatal:
 *   [FORMS-GATE]  ¿existe una vía soportada de prefill/lock del activo en Forms nativo?
 *
 * NOTA: la "sesión" de cada usuario se fabrica en $_SESSION (técnica estándar de los
 * tests de GLPI). Lo que se prueba de verdad es que canViewItem() nativo deniega el
 * acceso entre entidades y que AccessPolicyService lo respeta ("el QR identifica;
 * GLPI autoriza").
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Command;

use Computer;
use Entity;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companyqr\Controller\ScanController;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Model\Scan;
use GlpiPlugin\Companyqr\Service\AccessPolicyService;
use GlpiPlugin\Companyqr\Service\AssetResolver;
use GlpiPlugin\Companyqr\Service\CodeManager;
use GlpiPlugin\Companyqr\Service\LabelRenderer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Annotation\Route;

final class SelftestCommand extends Command
{
    private int $failures = 0;
    private OutputInterface $out;

    protected function configure(): void
    {
        $this->setName('plugins:companyqr:selftest')
            ->setDescription('Pruebas de integración de companyqr (ACL, no-fuga, ciclo de vida, etiqueta).')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Ruta del PDF de etiqueta', sys_get_temp_dir() . '/companyqr-label.pdf');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->out = $output;
        $suffix = substr((string) time(), -6);

        // --- Sesión permisiva para crear fixtures (no comprueba ACL aquí). ---
        $this->applySession(2, [0], ['computer' => ALLSTANDARDRIGHT, 'entity' => ALLSTANDARDRIGHT]);

        $entityA = (int) (new Entity())->add(['name' => 'QR-A-' . $suffix, 'entities_id' => 0]);
        $entityB = (int) (new Entity())->add(['name' => 'QR-B-' . $suffix, 'entities_id' => 0]);
        $this->check('fixtures: entidades A y B creadas', $entityA > 0 && $entityB > 0);

        $computer = new Computer();
        $computerId = (int) $computer->add([
            'name'        => 'QR-ASSET-' . $suffix,
            'entities_id' => $entityB,
            'otherserial' => 'NB-' . $suffix,
        ]);
        $this->check('fixtures: activo (Computer) creado en entidad B', $computerId > 0);
        $computer->getFromDB($computerId);

        // --- Código para el activo (public_code debe copiar otherserial). ---
        $manager = new CodeManager();
        $code = $manager->createForItem($computer);
        $token = (string) $code->fields['token'];
        $this->check('code: token generado', $token !== '');
        $this->check('code: public_code = otherserial (NB-…)', $code->fields['public_code'] === 'NB-' . $suffix);

        // --- [ROUTES] atributos de ruta/seguridad declarados. ---
        $this->checkRoutes();

        // --- [ACL] 🔒 multi-entidad ---
        $policy = new AccessPolicyService();

        // Usuario A (entidad A) escanea un activo de la entidad B → DENEGADO.
        $this->applySession(101, [$entityA], ['computer' => READ]);
        $rA = $policy->resolveAuthenticated($token);
        $this->check('ACL 🔒 usuario entidad A → DENEGADO en activo de entidad B',
            $rA['result'] === Scan::RESULT_DENIED);

        // Usuario B (entidad B, con READ) → RESUELTO.
        $this->applySession(102, [$entityB], ['computer' => READ]);
        $rB = $policy->resolveAuthenticated($token);
        $this->check('ACL usuario entidad B → RESUELTO', $rB['result'] === Scan::RESULT_RESOLVED);

        // --- [NO-LEAK] 🔒 la vista sólo trae whitelist; sin campos prohibidos. ---
        $view = $rB['view'] ?? [];
        $this->check('NO-LEAK 🔒 claves ⊆ whitelist segura',
            $view !== [] && empty(array_diff(array_keys($view), AssetResolver::SAFE_FIELDS)));
        $this->check('NO-LEAK 🔒 sin IP/MAC/hostname/responsable/VLAN', $this->noForbidden($view));

        // --- [ANON-OFF] modo anónimo apagado → login_required (sin fuga). ---
        $rAnon = $policy->resolveAnonymous($token);
        $this->check('ANON-OFF modo anónimo apagado → login_required',
            $rAnon['result'] === Scan::RESULT_LOGIN_REQUIRED);

        // --- [LIFECYCLE] rotar / revocar ---
        $manager->rotate($code);
        $code->getFromDB($code->getID());
        $newToken = (string) $code->fields['token'];
        $this->applySession(102, [$entityB], ['computer' => READ]);
        $oldResolves = $policy->resolveAuthenticated($token)['result'];
        $newResolves = $policy->resolveAuthenticated($newToken)['result'];
        $this->check('LIFECYCLE rotar: token viejo NO resuelve', $oldResolves === Scan::RESULT_NOT_FOUND);
        $this->check('LIFECYCLE rotar: token nuevo resuelve', $newResolves === Scan::RESULT_RESOLVED);

        $manager->revoke($code, 'selftest');
        $this->check('LIFECYCLE revocar → "no disponible"',
            $policy->resolveAuthenticated($newToken)['result'] === Scan::RESULT_REVOKED);

        // --- [LABEL] etiqueta PDF real (evidencia) ---
        $outPath = (string) $input->getOption('out');
        $this->generateLabel($computer, $code, $outPath);

        // --- [FORMS-GATE] probe no fatal ---
        $this->formsGateProbe();

        // --- Limpieza best-effort ---
        $this->cleanup($code, $computer, $entityA, $entityB);

        if ($this->failures > 0) {
            $output->writeln(sprintf('<error>SELFTEST: %d comprobación(es) fallida(s).</error>', $this->failures));
            return Command::FAILURE;
        }
        $output->writeln('<info>SELFTEST: todas las comprobaciones pasaron.</info>');
        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------

    private function checkRoutes(): void
    {
        try {
            $rc = new \ReflectionClass(ScanController::class);

            $scan = $this->routeInfo($rc->getMethod('scan'));
            $this->check('ROUTES /scan/{token} declarada',
                str_contains($scan['path'], '/scan/{token}'));
            $this->check('ROUTES /scan es AUTHENTICATED (no NO_CHECK)',
                $scan['strategy'] === Firewall::STRATEGY_AUTHENTICATED);

            $public = $this->routeInfo($rc->getMethod('public'));
            $this->check('ROUTES /public/{token} declarada',
                str_contains($public['path'], '/public/{token}'));
            $this->check('ROUTES /public es NO_CHECK (anónimo, ruta separada)',
                $public['strategy'] === Firewall::STRATEGY_NO_CHECK);
        } catch (\Throwable $e) {
            $this->check('ROUTES reflexión de ScanController: ' . $e->getMessage(), false);
        }
    }

    /** @return array{path:string, strategy:?string} */
    private function routeInfo(\ReflectionMethod $m): array
    {
        $path = '';
        $strategy = null;
        foreach ($m->getAttributes(Route::class) as $a) {
            $args = $a->getArguments();
            $path = (string) ($args['path'] ?? $args[0] ?? '');
        }
        foreach ($m->getAttributes(SecurityStrategy::class) as $a) {
            $args = $a->getArguments();
            $strategy = (string) ($args['strategy'] ?? $args[0] ?? '');
        }
        return ['path' => $path, 'strategy' => $strategy];
    }

    private function generateLabel(Computer $computer, Code $code, string $outPath): void
    {
        try {
            $pdf = (new LabelRenderer())->pdf([
                'public_code' => (string) $code->fields['public_code'],
                'type'        => $computer->getTypeName(1),
                'qr_data'     => 'https://example.test/plugins/companyqr/scan/' . $code->fields['token'],
            ]);
            $isPdf = str_starts_with($pdf, '%PDF');
            if ($isPdf) {
                @file_put_contents($outPath, $pdf);
            }
            $this->check('LABEL PDF 70,75×24 generado (' . strlen($pdf) . ' bytes → ' . $outPath . ')',
                $isPdf && is_file($outPath));
        } catch (\Throwable $e) {
            $this->check('LABEL generación: ' . $e->getMessage(), false);
        }
    }

    private function formsGateProbe(): void
    {
        $formClass = 'Glpi\\Form\\Destination\\FormDestinationTicket';
        $assocClass = 'Glpi\\Form\\Destination\\CommonITILField\\AssociatedItemsField';
        $hasTicketDest = class_exists($formClass);
        $hasAssoc = class_exists($assocClass);

        // Búsqueda runtime de una vía de prefill/lock soportada (no concluyente por sí sola).
        $prefill = false;
        if ($hasAssoc) {
            foreach (get_class_methods($assocClass) as $method) {
                if (stripos($method, 'prefill') !== false || stripos($method, 'preselect') !== false) {
                    $prefill = true;
                    break;
                }
            }
        }

        $this->out->writeln('[FORMS-GATE] FormDestinationTicket: ' . ($hasTicketDest ? 'sí' : 'no')
            . ' | AssociatedItemsField: ' . ($hasAssoc ? 'sí' : 'no')
            . ' | prefill/preselect soportado: ' . ($prefill ? 'SÍ (revisar → ADR de seguimiento)' : 'no detectado'));
        $this->out->writeln('[FORMS-GATE] Decisión v1: formulario mínimo propio (probe no fatal). '
            . 'Si "prefill soportado=SÍ", registrar evidencia y abrir ADR para migrar a Forms.');
    }

    private function cleanup(Code $code, Computer $computer, int $entityA, int $entityB): void
    {
        try {
            $code->delete(['id' => $code->getID()], true);
            $computer->delete(['id' => $computer->getID()], true);
            (new Entity())->delete(['id' => $entityA], true);
            (new Entity())->delete(['id' => $entityB], true);
        } catch (\Throwable) {
            // best-effort; el stack de CI es efímero.
        }
    }

    /**
     * Fabrica $_SESSION como lo haría GLPI tras un login (técnica de test).
     *
     * @param array<int>          $entities        entidades activas
     * @param array<string,int>   $rights          rightname => bits
     */
    private function applySession(int $userId, array $entities, array $rights): void
    {
        $_SESSION['glpiID']                     = $userId;
        $_SESSION['glpiname']                   = 'qr_selftest';
        $_SESSION['glpiactive_entity']          = $entities[0] ?? 0;
        $_SESSION['glpiactiveentities']         = $entities;
        $_SESSION['glpiactiveentities_string']  = "'" . implode("','", $entities) . "'";
        $_SESSION['glpiactive_entity_recursive'] = 0;
        $_SESSION['glpigroups']                 = [];
        $_SESSION['glpiactiveprofile']          = array_merge(
            ['id' => 1, 'interface' => 'central', 'entities_id' => $entities[0] ?? 0],
            $rights
        );
    }

    private function noForbidden(array $view): bool
    {
        foreach (array_keys($view) as $key) {
            foreach (AssetResolver::FORBIDDEN_FIELDS as $bad) {
                if (stripos((string) $key, $bad) !== false) {
                    return false;
                }
            }
        }
        return true;
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
