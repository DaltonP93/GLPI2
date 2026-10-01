<?php

/**
 * Páginas (GET) de la UI de Compras (P2D-4; ADR-0023 §8). SÓLO LECTURA: ningún GET muta datos.
 *
 *   GET /plugins/companypurchasing/requests            Mis solicitudes (VIEW_OWN; alcance entidad con VIEW_ENTITY)
 *   GET /plugins/companypurchasing/inbox/{box}         Bandejas derivadas del motor (para mí / compras / recepción / entrega)
 *   GET /plugins/companypurchasing/request/new         Nueva solicitud (CREATE_REQUEST)
 *   GET /plugins/companypurchasing/request/{id}        Detalle (canView)
 *   GET /plugins/companypurchasing/request/{id}/edit   Borrador / devuelta (EDIT_DRAFT + editable por el motor)
 *   GET /plugins/companypurchasing/metrics             Métricas (VIEW_METRICS)
 *   GET /plugins/companypurchasing/config              Configuración (MANAGE_CONFIG)
 *
 * Layout, menú y CSRF NATIVOS de GLPI 11. Toda autorización se vuelve a aplicar en los servicios (fail-closed).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Controller;

use Session;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Companypurchasing\Model\Request as PurchaseRequest;
use GlpiPlugin\Companypurchasing\Service\ConfigForm;
use GlpiPlugin\Companypurchasing\Service\InboxService;
use GlpiPlugin\Companypurchasing\Service\Labels;
use GlpiPlugin\Companypurchasing\Service\MetricsMath;
use GlpiPlugin\Companypurchasing\Service\MetricsService;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;
use GlpiPlugin\Companypurchasing\Service\PurchasingWorkflow;
use GlpiPlugin\Companypurchasing\Service\RequestDetailBuilder;
use GlpiPlugin\Companypurchasing\Service\RequestManager;
use GlpiPlugin\Companypurchasing\Service\RequestQuery;
use GlpiPlugin\Companypurchasing\Service\WorkflowGateway;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    use UiSupport;

    /** Estados ofrecidos en el filtro (códigos del motor; etiquetas i18n). */
    private const FILTER_STATES = [
        PurchasingWorkflow::S_PENDING_AREA_HEAD, PurchasingWorkflow::S_PURCHASING, PurchasingWorkflow::S_PENDING_FINANCE,
        PurchasingWorkflow::S_APPROVED, PurchasingWorkflow::S_RETURNED, PurchasingWorkflow::S_REJECTED, PurchasingWorkflow::S_CANCELLED,
        PurchasingWorkflow::S_IN_PURCHASE, PurchasingWorkflow::S_PARTIALLY_RECEIVED, PurchasingWorkflow::S_RECEIVED,
        PurchasingWorkflow::S_DELIVERED, PurchasingWorkflow::S_CLOSED, PurchasingWorkflow::S_DRAFT,
    ];

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/requests', name: 'cpur_requests', methods: ['GET'])]
    public function requests(Request $http): Response
    {
        $filters = [
            'state'       => (string) $http->query->get('state', ''),
            'from'        => (string) $http->query->get('from', ''),
            'to'          => (string) $http->query->get('to', ''),
            'category'    => (string) $http->query->get('category', ''),
            'entities_id' => $http->query->get('entities_id', ''),
            'number'      => (string) $http->query->get('number', ''),
        ];
        $scope = (string) $http->query->get('scope', RequestQuery::SCOPE_MINE);
        try {
            $rows = (new RequestQuery())->search($filters, $scope);
        } catch (\RuntimeException) {
            throw new AccessDeniedHttpException();
        }
        $states = [];
        foreach (self::FILTER_STATES as $code) {
            $states[$code] = Labels::state($code);
        }
        return $this->render('@companypurchasing/requests.html.twig', self::page(__('My requests', 'companypurchasing'), 'requests', [
            'rows'        => $rows,
            'filters'     => $filters,
            'scope'       => Session::haveRight(PurchaseRequest::$rightname, PurchaseRequest::RIGHT_VIEW_ENTITY) && $scope === RequestQuery::SCOPE_ENTITY ? RequestQuery::SCOPE_ENTITY : RequestQuery::SCOPE_MINE,
            'can_entity'  => Session::haveRight(PurchaseRequest::$rightname, PurchaseRequest::RIGHT_VIEW_ENTITY),
            'can_create'  => Session::haveRight(PurchaseRequest::$rightname, PurchaseRequest::RIGHT_CREATE_REQUEST),
            'states'      => $states,
            'entities'    => $this->activeEntityNames(),
        ]));
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/inbox/{box}', name: 'cpur_inbox', methods: ['GET'], requirements: ['box' => 'approvals|purchasing|receiving|delivery'])]
    public function inbox(string $box): Response
    {
        if (!in_array($box, InboxService::availableBoxes(), true)) {
            throw new AccessDeniedHttpException();
        }
        try {
            $rows = (new InboxService())->box($box);
        } catch (\RuntimeException) {
            throw new AccessDeniedHttpException();
        }
        foreach ($rows as &$r) {
            $r['action_labels'] = array_map([Labels::class, 'action'], array_values(array_intersect($r['actions'] ?? [], ['approve', 'reject', 'return'])));
        }
        unset($r);
        $titles = [
            InboxService::BOX_APPROVALS  => __('Pending my approval', 'companypurchasing'),
            InboxService::BOX_PURCHASING => __('Purchasing management', 'companypurchasing'),
            InboxService::BOX_RECEIVING  => __('Pending receptions', 'companypurchasing'),
            InboxService::BOX_DELIVERY   => __('Pending deliveries', 'companypurchasing'),
        ];
        $option = ['approvals' => 'inbox', 'purchasing' => 'purchasing', 'receiving' => 'receiving', 'delivery' => 'delivery'][$box];
        $tabs = [];
        foreach (InboxService::availableBoxes() as $b) {
            $tabs[$b] = $titles[$b];
        }
        return $this->render('@companypurchasing/inbox.html.twig', self::page($titles[$box], $option, [
            'box' => $box, 'rows' => $rows, 'tabs' => $tabs,
        ]));
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/new', name: 'cpur_request_new', methods: ['GET'])]
    public function newRequest(): Response
    {
        if (!Session::haveRight(PurchaseRequest::$rightname, PurchaseRequest::RIGHT_CREATE_REQUEST)) {
            throw new AccessDeniedHttpException();
        }
        $entity = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        return $this->render('@companypurchasing/request_form.html.twig', self::page(__('New purchase request', 'companypurchasing'), 'requests', [
            'request'   => null,
            'lines'     => [],
            'currency'  => PluginConfig::defaultCurrency(),
            'dropdowns' => $this->headerDropdowns($entity, []),
            'entities'  => $this->activeEntityNames(),
        ]));
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}', name: 'cpur_request_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        try {
            $view = (new RequestDetailBuilder())->build($id);
        } catch (\RuntimeException $e) {
            throw str_contains($e->getMessage(), 'inexistente') ? new NotFoundHttpException() : new AccessDeniedHttpException();
        }
        $view['decide_labels'] = [];
        foreach ($view['can']['decide'] as $a) {
            $view['decide_labels'][$a] = Labels::action($a);
        }
        $recipient = '';
        if ($view['can']['deliver']) {
            $recipient = (string) \User::dropdown(['name' => 'recipient_users_id', 'entity' => $view['entity_id'], 'right' => 'all', 'display' => false]);
        }
        $supplier = '';
        if ($view['can']['quotes']) {
            $supplier = (string) \Supplier::dropdown(['name' => 'suppliers_id', 'entity' => $view['entity_id'], 'display' => false]);
        }
        return $this->render('@companypurchasing/request_show.html.twig', self::page(
            sprintf(__('Purchase request %s', 'companypurchasing'), $view['header']['number'] !== '' ? $view['header']['number'] : '#' . $id),
            'requests',
            [
                'r'                 => $view,
                'recipient_dropdown' => $recipient,
                'supplier_dropdown' => $supplier,
                // Una clave por RENDER del formulario: un reintento del MISMO submit es un replay (nunca un 2.º lote).
                'receive_key'       => 'ui-rcv-' . bin2hex(random_bytes(12)),
                'deliver_key'       => 'ui-dlv-' . bin2hex(random_bytes(12)),
            ]
        ));
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/request/{id}/edit', name: 'cpur_request_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id): Response
    {
        try {
            $view = (new RequestDetailBuilder())->build($id);
        } catch (\RuntimeException $e) {
            throw str_contains($e->getMessage(), 'inexistente') ? new NotFoundHttpException() : new AccessDeniedHttpException();
        }
        if (!$view['can']['edit']) {
            throw new AccessDeniedHttpException();
        }
        $req = (new RequestManager())->getViewable($id);
        return $this->render('@companypurchasing/request_form.html.twig', self::page(__('Edit purchase request', 'companypurchasing'), 'requests', [
            'request'   => $req->fields,
            'view'      => $view,
            'lines'     => $view['lines'],
            'currency'  => (string) $req->fields['currency_code'],
            'dropdowns' => $this->headerDropdowns((int) $req->fields['entities_id'], $req->fields),
            'entities'  => $this->activeEntityNames(),
        ]));
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/metrics', name: 'cpur_metrics', methods: ['GET'])]
    public function metrics(Request $http): Response
    {
        $filter = ['from' => (string) $http->query->get('from', ''), 'to' => (string) $http->query->get('to', ''),
                   'entities_id' => $http->query->get('entities_id', '')];
        try {
            $m = (new MetricsService())->compute($filter);
        } catch (\RuntimeException) {
            throw new AccessDeniedHttpException();
        }
        $lang = (string) ($_SESSION['glpilanguage'] ?? 'es_ES');
        $fmt = static function (array $amounts) use ($lang): array {
            $out = [];
            foreach ($amounts as $cur => $a) {
                $out[$cur] = MetricsMath::display((string) $a, $lang);
            }
            return $out;
        };
        foreach ($m['amounts'] as $k => $v) {
            $m['amounts'][$k] = $fmt($v);
        }
        foreach ($m['breakdown'] as $dim => $rows) {
            foreach ($rows as $i => $row) {
                foreach (['requested', 'approved', 'purchased'] as $k) {
                    $m['breakdown'][$dim][$i][$k] = $fmt($row[$k]);
                }
            }
        }
        $stateLabels = [];
        foreach (array_unique(array_merge(array_keys($m['counts']['by_state']), array_keys($m['stages']))) as $code) {
            $stateLabels[$code] = Labels::state((string) $code);
        }
        return $this->render('@companypurchasing/metrics.html.twig', self::page(__('Purchasing metrics', 'companypurchasing'), 'metrics', [
            'm' => $m, 'filter' => $filter, 'state_labels' => $stateLabels, 'entities' => $this->activeEntityNames(),
        ]));
    }

    #[SecurityStrategy(Firewall::STRATEGY_AUTHENTICATED)]
    #[Route('/config', name: 'cpur_config', methods: ['GET'])]
    public function config(): Response
    {
        if (!Session::haveRight(PurchaseRequest::$rightname, PurchaseRequest::RIGHT_MANAGE_CONFIG)) {
            throw new AccessDeniedHttpException();
        }
        $values = [];
        foreach (ConfigForm::KEYS as $key => $type) {
            $values[$key] = (string) PluginConfig::get($key, '');
        }
        $groups = [];
        foreach (PluginConfig::STAGE_CONFIG_KEYS as $stage => $suffix) {
            $groups[$suffix] = [
                'label'    => Labels::state($stage),
                'dropdown' => (string) \Group::dropdown(['name' => 'approver_group_' . $suffix, 'value' => (int) PluginConfig::get('approver_group_' . $suffix, '0'),
                                                         'entity' => 0, 'entity_sons' => true, 'display' => false]),
            ];
        }
        $def = null;
        try {
            $active = (new WorkflowGateway())->activeDefinition(PluginConfig::workflowCode());
            $def = $active !== null ? ['version' => (int) $active->fields['version']] : null;
        } catch (\Throwable) {
            $def = null;
        }
        return $this->render('@companypurchasing/config.html.twig', self::page(__('Purchasing configuration', 'companypurchasing'), 'config', [
            'values' => $values, 'groups' => $groups, 'definition' => $def, 'keys' => ConfigForm::KEYS,
        ]));
    }

    /** @return array<int,string> entidades ACTIVAS de la sesión (id → nombre) */
    private function activeEntityNames(): array
    {
        $out = [];
        foreach (array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [])) as $e) {
            if (Session::haveAccessToEntity($e)) {
                $out[$e] = \Dropdown::getDropdownName('glpi_entities', $e);
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $values @return array<string,string> dropdowns NATIVOS (HTML generado por GLPI) */
    private function headerDropdowns(int $entity, array $values): array
    {
        return [
            'department' => (string) \Group::dropdown(['name' => 'groups_id_department', 'value' => (int) ($values['groups_id_department'] ?? 0),
                                                       'entity' => $entity, 'display' => false]),
            'supplier'   => (string) \Supplier::dropdown(['name' => 'suppliers_id_suggested', 'value' => (int) ($values['suppliers_id_suggested'] ?? 0),
                                                          'entity' => $entity, 'display' => false]),
            'budget'     => (string) \Budget::dropdown(['name' => 'budgets_id', 'value' => (int) ($values['budgets_id'] ?? 0),
                                                        'entity' => $entity, 'display' => false]),
        ];
    }
}
