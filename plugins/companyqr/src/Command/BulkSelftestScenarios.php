<?php

/**
 * Escenarios de SELFTEST de la impresión masiva de etiquetas (ADR-0024) sobre GLPI 11.0.8 REAL:
 *
 *   [BULK-HOOK]    la Acción masiva aparece en activos sólo con `print`; nunca en tipos que no son activos
 *   [BULK-ROUTE]   GET /labels/{batch} es AUTHENTICATED
 *   [BULK-PLAN]    ACL por activo (otra entidad ⇒ no_right), sin código ⇒ no_code, revocado ⇒ inactive,
 *                  "generar los que falten" sólo con `generate`, tope del lote ⇒ over_limit
 *   [BULK-RENDER]  PDF real: una página por etiqueta, con los public_code; auditoría label_printed por etiqueta;
 *                  revalida al imprimir (código revocado después de armar el lote ⇒ se omite)
 *   [BULK-CONFIG]  la clave `label_batch_max` existe tras install()
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Command;

use Computer;
use Glpi\Http\Firewall;
use GlpiPlugin\Companyqr\Controller\BatchLabelController;
use GlpiPlugin\Companyqr\Model\Code;
use GlpiPlugin\Companyqr\Model\Scan;
use GlpiPlugin\Companyqr\Service\BulkLabelService;
use GlpiPlugin\Companyqr\Service\CodeManager;
use GlpiPlugin\Companyqr\Service\PluginConfig;

trait BulkSelftestScenarios
{
    /** @var array<int,int> activos creados por estos escenarios */
    private array $bulkComputers = [];

    private function runBulkScenarios(int $entityA, int $entityB, string $suffix): void
    {
        try {
            $this->bulkHookAndRoute();
            $this->bulkPlanAndRender($entityA, $entityB, $suffix);
            $this->check('[BULK-CONFIG] label_batch_max sembrada por install()', isset(PluginConfig::all()['label_batch_max']));
        } catch (\Throwable $e) {
            $this->check('[BULK] sin excepciones: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
        } finally {
            $this->applySession(2, [0, $entityA, $entityB], ['computer' => ALLSTANDARDRIGHT, 'entity' => ALLSTANDARDRIGHT, Code::$rightname => ALLSTANDARDRIGHT | 14]);
            foreach ($this->bulkComputers as $id) {
                $c = new Code();
                if ($c->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $id])) {
                    $c->delete(['id' => $c->getID()], true);
                }
                (new Computer())->delete(['id' => $id], true);
            }
        }
    }

    private function bulkHookAndRoute(): void
    {
        $this->out->writeln('== [BULK] Impresión masiva de etiquetas (ADR-0024) ==');
        if (!function_exists('plugin_companyqr_MassiveActions')) {
            include_once dirname(__DIR__, 2) . '/hook.php';
        }
        $key = Code::class . \MassiveAction::CLASS_ACTION_SEPARATOR . Code::MA_PRINT_LABELS;

        $this->applySession(102, [0], ['computer' => READ, Code::$rightname => Code::RIGHT_PRINT]);
        $this->check('[BULK-HOOK] con print: la acción aparece en Computer', array_key_exists($key, plugin_companyqr_MassiveActions('Computer')));
        $this->check('[BULK-HOOK] 🔒 nunca en tipos que no son activos (Ticket)', plugin_companyqr_MassiveActions('Ticket') === []);
        $this->applySession(102, [0], ['computer' => READ, Code::$rightname => Code::RIGHT_GENERATE]);
        $this->check('[BULK-HOOK] 🔒 sin print: la acción no aparece', plugin_companyqr_MassiveActions('Computer') === []);

        $info = $this->routeInfo((new \ReflectionClass(BatchLabelController::class))->getMethod('pdf'));
        $this->check('[BULK-ROUTE] /labels/{batch} declarada y AUTHENTICATED', str_contains($info['path'], '/labels/{batch}')
            && $info['strategy'] === Firewall::STRATEGY_AUTHENTICATED);
    }

    private function bulkPlanAndRender(int $entityA, int $entityB, string $suffix): void
    {
        $this->applySession(2, [0, $entityA, $entityB], ['computer' => ALLSTANDARDRIGHT, 'entity' => ALLSTANDARDRIGHT]);
        $add = function (string $tag, int $entity): int {
            $id = (int) (new Computer())->add(['name' => 'QR-BULK-' . $tag, 'entities_id' => $entity, 'otherserial' => 'BLK-' . $tag]);
            $this->bulkComputers[] = $id;
            return $id;
        };
        $active1 = $add($suffix . '-1', $entityB);
        $active2 = $add($suffix . '-2', $entityB);
        $missing = $add($suffix . '-3', $entityB);
        $revoked = $add($suffix . '-4', $entityB);
        $foreign = $add($suffix . '-5', $entityA);
        $manager = new CodeManager();
        $codeOf = function (int $id) use ($manager): Code {
            $c = new Computer();
            $c->getFromDB($id);
            return $manager->getOrCreateForItem($c);
        };
        $c1 = $codeOf($active1);
        $c2 = $codeOf($active2);
        $manager->revoke($codeOf($revoked), 'selftest bulk');
        $codeOf($foreign);

        $service = new BulkLabelService();
        $printer = ['computer' => READ, Code::$rightname => Code::RIGHT_PRINT];
        $all = [$active1, $active2, $missing, $revoked, $foreign];

        // Sólo print: el faltante no se genera aunque se pida.
        $this->applySession(102, [$entityB], $printer);
        $plan = $service->plan('Computer', $all, true, [], 100);
        $this->check('[BULK-PLAN] activos con código activo ⇒ ok', ($plan['outcomes'][$active1] ?? '') === BulkLabelService::OK
            && ($plan['outcomes'][$active2] ?? '') === BulkLabelService::OK);
        $this->check('[BULK-PLAN] 🔒 activo de otra entidad ⇒ no_right', ($plan['outcomes'][$foreign] ?? '') === BulkLabelService::NO_RIGHT);
        $this->check('[BULK-PLAN] 🔒 código revocado ⇒ inactive (no se reactiva)', ($plan['outcomes'][$revoked] ?? '') === BulkLabelService::INACTIVE
            && !(new Code())->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $revoked, 'status' => Code::STATUS_ACTIVE]));
        $this->check('[BULK-PLAN] 🔒 sin generate: el faltante ⇒ no_code y no se crea', ($plan['outcomes'][$missing] ?? '') === BulkLabelService::NO_CODE
            && countElementsInTable(Code::getTable(), ['itemtype' => 'Computer', 'items_id' => $missing]) === 0);
        $this->check('[BULK-PLAN] el lote = códigos de los aceptados, en orden', $plan['codes'] === [(int) $c1->getID(), (int) $c2->getID()]);

        $limited = $service->plan('Computer', [$active1, $active2], false, [], 1);
        $this->check('[BULK-PLAN] tope del lote ⇒ el excedente queda over_limit', ($limited['outcomes'][$active2] ?? '') === BulkLabelService::OVER_MAX
            && count($limited['codes']) === 1);
        $reload = $service->plan('Computer', [$active1, $active2], false, $limited['codes'], 1);
        $this->check('[BULK-PLAN] recarga de GLPI: lo ya encolado se acepta sin consumir el tope ni duplicarse', ($reload['outcomes'][$active1] ?? '') === BulkLabelService::OK
            && ($reload['outcomes'][$active2] ?? '') === BulkLabelService::OVER_MAX && $reload['codes'] === []);

        // Con generate y la casilla marcada: se genera el faltante.
        $this->applySession(102, [$entityB], ['computer' => READ, Code::$rightname => Code::RIGHT_PRINT | Code::RIGHT_GENERATE]);
        $gen = $service->plan('Computer', [$missing], true, [], 100);
        $this->check('[BULK-PLAN] con generate + casilla: el faltante se genera y entra al lote', ($gen['outcomes'][$missing] ?? '') === BulkLabelService::GENERATE
            && count($gen['codes']) === 1 && countElementsInTable(Code::getTable(), ['itemtype' => 'Computer', 'items_id' => $missing, 'status' => Code::STATUS_ACTIVE]) === 1);

        // [BULK-RENDER]
        $this->applySession(102, [$entityB], $printer);
        $batch = array_merge($plan['codes'], $gen['codes']);
        $audit = static fn (): int => countElementsInTable(Scan::getTable(), ['result' => Scan::RESULT_LABEL_PRINTED, 'plugin_companyqr_codes_id' => $batch]);
        $before = $audit();
        $out = $service->render($batch);
        $pdf = (string) $out['pdf'];
        $pages = preg_match_all('#/Type\s*/Page[^s]#', $pdf);
        $text = $this->apiPdfText($pdf);
        $this->check('[BULK-RENDER] PDF real: una página por etiqueta (' . $pages . ' páginas, ' . strlen($pdf) . ' bytes)', str_starts_with($pdf, '%PDF-')
            && $pages === 3 && $out['printed'] === 3 && $out['skipped'] === 0);
        $this->check('[BULK-RENDER] cada etiqueta muestra su public_code', str_contains($text, 'BLK-' . $suffix . '-1') && str_contains($text, 'BLK-' . $suffix . '-2')
            && str_contains($text, 'BLK-' . $suffix . '-3'));
        $this->check('[BULK-RENDER] 🔒 sin tokens en el PDF visible', !str_contains($text, (string) $c1->fields['token']));
        $this->check('[BULK-RENDER] auditoría: una fila label_printed por etiqueta', $audit() - $before === 3);

        $this->applySession(2, [0, $entityA, $entityB], ['computer' => ALLSTANDARDRIGHT, 'entity' => ALLSTANDARDRIGHT]);
        $manager->revoke($c2, 'selftest bulk revalidate');
        $this->applySession(102, [$entityB], $printer);
        $again = $service->render($batch);
        $this->check('[BULK-RENDER] 🔒 revalida al imprimir: revocado después de armar el lote ⇒ se omite', $again['printed'] === 2 && $again['skipped'] === 1);
        $this->applySession(101, [$entityA], $printer);
        $this->check('[BULK-RENDER] 🔒 otra entidad: nada imprimible ⇒ sin PDF', $service->render($batch)['pdf'] === null);
    }
}
