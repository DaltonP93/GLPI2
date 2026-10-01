<?php

/**
 * Modelo de VISTA del detalle de una solicitud (P2D-4; ADR-0023 §8): cabecera, líneas, cotizaciones, selección,
 * aprobaciones/historial, PDFs/evidencias, recepción, unidades físicas, integración de inventario, entrega, cierre y
 * auditoría. SÓLO LECTURA.
 *
 * Seguridad: exige `canView` (entidad + VIEW_OWN/VIEW_ENTITY). Los permisos de cada acción (`can.*`) se DERIVAN de las
 * acciones del MOTOR (`availableActions` / `actionsForCurrentUser`) ∩ el derecho de dominio — nunca de `domain_state`.
 * Estos `can.*` sólo deciden qué formulario mostrar: cada POST vuelve a pasar por el servicio de dominio, que aplica
 * ACL, entidad y estado (fail-closed). Nunca expone tokens, lease, hashes internos ni errores técnicos.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\DeliveryBatch;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Quote;
use GlpiPlugin\Companypurchasing\Model\ReceiptBatch;
use GlpiPlugin\Companypurchasing\Model\Request;

final class RequestDetailBuilder
{
    private WorkflowGateway $wf;

    public function __construct(?WorkflowGateway $wf = null)
    {
        $this->wf = $wf ?? new WorkflowGateway();
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException sin permiso de vista / inexistente (fail-closed)
     */
    public function build(int $requestId): array
    {
        $rm  = new RequestManager();
        $req = $rm->getViewable($requestId);
        $id  = (int) $req->getID();
        $f   = $req->fields;
        $me  = (int) (Session::getLoginUserID() ?: 0);
        $lang = (string) ($_SESSION['glpilanguage'] ?? 'es_ES');
        $cur = (string) $f['currency_code'];
        $overrides = CostPolicyStore::scaleOverridesFor($req);

        $inst = $this->wf->loadInstance((int) ($f['workflow_instances_id'] ?? 0));
        $open = $inst !== null && $this->wf->isOpen($inst);
        $state = $inst !== null ? $this->wf->stateCode($inst) : (string) $f['domain_state'];
        $available = $open ? $this->wf->availableActions($inst) : [];
        $mine = [];
        try {
            $mine = $inst !== null ? $this->wf->actionsForCurrentUser($inst) : [];
        } catch (\Throwable) {
            $mine = [];
        }
        $has = static fn (int $bit): bool => (bool) Session::haveRight(Request::$rightname, $bit);
        $isRequester = (int) $f['users_id_requester'] === $me;
        $started = !empty($f['purchase_started_at']);
        $editable = $inst === null ? $req->isDraft() : $this->wf->isEditable($inst);

        $quoteStates = [];
        try {
            $quoteStates = (int) ($f['policies_id'] ?? 0) > 0 ? (new PolicyStore())->forRequest($req)->quoteStates() : [];
        } catch (\Throwable) {
            $quoteStates = [];
        }

        $integrity = ['clean' => true, 'dirty_scopes' => []];
        if ($inst !== null) {
            try {
                $st = (new ApprovalOrchestrator($this->wf))->integrityStatus($id);
                $integrity = ['clean' => $st['clean'], 'dirty_scopes' => array_values(array_unique(array_merge($st['dirty_scopes'], array_keys($st['drift']))))];
            } catch (\Throwable) {
                $integrity = ['clean' => false, 'dirty_scopes' => []];
            }
        }

        $can = [
            'edit'           => $editable && $isRequester && $has(Request::RIGHT_EDIT_DRAFT),
            'submit'         => $editable && $isRequester && $has(Request::RIGHT_EDIT_DRAFT) && ($inst === null || in_array('submit', $mine, true)),
            'decide'         => array_values(array_intersect(['approve', 'reject', 'return'], $mine)),
            'quotes'         => $has(Request::RIGHT_MANAGE_PURCHASING) && !$started && $open && in_array($state, $quoteStates, true),
            'start_purchase' => $has(Request::RIGHT_MANAGE_PURCHASING) && !$started && in_array(PurchasingWorkflow::A_START_PURCHASE, $available, true),
            'receive'        => $has(Request::RIGHT_RECEIVE) && $started
                && array_intersect([PurchasingWorkflow::A_RECEIVE_PARTIAL, PurchasingWorkflow::A_RECEIVE_COMPLETE], $available) !== [],
            'deliver'        => $has(Request::RIGHT_DELIVER) && in_array(PurchasingWorkflow::A_DELIVER_COMPLETE, $available, true),
            'close'          => $has(Request::RIGHT_MANAGE_PURCHASING) && in_array(PurchasingWorkflow::A_CLOSE, $available, true),
        ];

        // ---------------- líneas
        $lines = [];
        foreach ($rm->loadItems($id) as $it) {
            $l = $it->fields;
            $ordered = (int) ($l['ordered_qty'] ?? 0);
            $received = (int) ($l['received_qty'] ?? 0);
            $lines[] = [
                'id'               => (int) $l['id'],
                'line_no'          => (int) $l['line_no'],
                'description'      => (string) $l['description'],
                'category'         => (string) $l['category'],
                'quantity'         => (int) $l['quantity'],
                'unit'             => (string) $l['unit'],
                'is_inventoriable' => (int) $l['is_inventoriable'] === 1,
                'estimated_unit_price' => $this->money((string) $l['estimated_unit_price'], $cur, $overrides, $lang),
                'estimated_line_total' => $this->money((string) $l['estimated_line_total'], $cur, $overrides, $lang),
                'ordered_qty'      => $ordered,
                'received_qty'     => $received,
                'pending_qty'      => max(0, $ordered - $received),
                'purchase_unit_price' => $started ? $this->money((string) $l['purchase_unit_price'], $cur, $overrides, $lang) : ['display' => '', 'raw' => ''],
                'notes'            => (string) ($l['notes'] ?? ''),
            ];
        }

        // ---------------- cotizaciones (proveedor NATIVO, adjuntos Document NATIVOS)
        $quotes = [];
        $qm = new QuoteManager();
        foreach ((new Quote())->find(['requests_id' => $id], ['id ASC']) as $q) {
            $qid = (int) $q['id'];
            $docs = [];
            foreach ($qm->attachments($qid) as $docId) {
                $docs[] = $this->documentLink($docId);
            }
            $quotes[] = [
                'id'         => $qid,
                'supplier'   => \Dropdown::getDropdownName('glpi_suppliers', (int) $q['suppliers_id']),
                'reference'  => (string) $q['reference'],
                'discounts'  => $this->money((string) $q['discounts'], $cur, $overrides, $lang),
                'taxes'      => $this->money((string) $q['taxes'], $cur, $overrides, $lang),
                'freight'    => $this->money((string) $q['freight'], $cur, $overrides, $lang),
                'valid_until' => (string) ($q['valid_until'] ?? ''),
                'prices'     => $this->quotePrices($qid, $cur, $overrides),
                'total'      => $this->quoteTotal($qid, $q, $lines, $cur, $overrides, $lang),
                'selected'   => (int) $f['quotes_id_selected'] === $qid,
                'purchase'   => (int) ($f['purchase_quotes_id'] ?? 0) === $qid,
                'documents'  => $docs,
            ];
        }

        // ---------------- historial del motor (ledger) — sin metadatos técnicos
        $history = [];
        if ($inst !== null) {
            foreach ($this->wf->history((int) $inst->getID()) as $h) {
                $history[] = [
                    'date'    => (string) $h['date'],
                    'event'   => Labels::historyEvent((string) $h['event']),
                    'from'    => (string) $h['from_code'] !== '' ? Labels::state((string) $h['from_code']) : '',
                    'to'      => (string) $h['to_code'] !== '' ? Labels::state((string) $h['to_code']) : '',
                    'actor'   => $this->userName((int) ($h['actor_users_id'] ?? 0)),
                    'comment' => (string) ($h['comment'] ?? ''),
                ];
            }
        }

        // ---------------- evidencias / PDFs (ledger propio + Document NATIVO vinculado a la solicitud)
        $versions = [];
        foreach ((new DocumentVersionAllocator())->all($id) as $v) {
            $versions[] = ['scope' => (string) $v['scope_key'], 'version' => (int) $v['document_version'], 'pdf_status' => (string) $v['pdf_status']];
        }
        $pdfs = [];
        foreach ((new \Document_Item())->find(['itemtype' => Request::class, 'items_id' => $id], ['id ASC']) as $di) {
            $pdfs[] = $this->documentLink((int) $di['documents_id']);
        }

        // ---------------- recepción
        $receipts = [];
        foreach ((new ReceiptBatch())->find(['requests_id' => $id], ['id ASC']) as $b) {
            $receipts[] = ['date' => (string) $b['received_at'], 'actor' => $this->userName((int) $b['actor_users_id']),
                           'units' => (int) $b['units_count'], 'notes' => (string) ($b['notes'] ?? '')];
        }

        // ---------------- unidades físicas + integración (outbox PROPIO + API read-only de companyintegrations)
        $units = [];
        try {
            $units = (new DeliveryService())->unitsWithGate($id);
        } catch (\Throwable) {
            $units = [];
        }
        $links = (new IntegrationLinkGateway())->forUnits(array_map(static fn (array $u): string => $u['receipt_unit_uuid'],
            array_filter($units, static fn (array $u): bool => $u['is_inventoriable'])));
        foreach ($units as &$u) {
            $link = $links[$u['receipt_unit_uuid']] ?? null;
            $u['physical_label']    = Labels::physical($u['physical_state']);
            $u['outbox_label']      = Labels::outboxStatus($u['outbox_status']);
            $u['gate_label']        = Labels::gate($u['gate']);
            $u['integration_label'] = $u['is_inventoriable'] ? Labels::integrationPhase($link['phase'] ?? null) : '';
            $u['public_code']       = (string) ($link['public_code'] ?? '');
            $u['asset_url']         = '';
            if (!empty($link['itemtype']) && !empty($link['items_id']) && is_a((string) $link['itemtype'], \CommonDBTM::class, true)) {
                $u['asset_url'] = (string) ((string) $link['itemtype'])::getFormURLWithID((int) $link['items_id']);
            }
            $u['delivered_to'] = $this->userName((int) $u['delivered_to_users_id']);
            unset($u['receipt_unit_uuid']); // la UI usa el uuid sólo para seleccionar (campo aparte, abajo)
        }
        unset($u);
        $deliverable = [];
        if ($can['deliver']) {
            foreach ((new DeliveryService())->unitsWithGate($id) as $raw) {
                if ($raw['physical_state'] === DeliveryRules::PHYSICAL_RECEIVED) {
                    $deliverable[] = ['uuid' => $raw['receipt_unit_uuid'], 'short' => $raw['short_uuid'], 'description' => $raw['description'],
                                      'serial' => $raw['serial'], 'gate' => $raw['gate'], 'gate_label' => Labels::gate($raw['gate']),
                                      'enabled' => $raw['gate'] === DeliveryRules::GATE_DELIVERABLE];
                }
            }
        }

        // ---------------- entregas
        $deliveries = [];
        foreach ((new DeliveryBatch())->find(['requests_id' => $id], ['id ASC']) as $b) {
            $deliveries[] = ['date' => (string) $b['delivered_at'], 'actor' => $this->userName((int) $b['actor_users_id']),
                             'recipient' => $this->userName((int) $b['recipient_users_id']), 'units' => (int) $b['units_count'],
                             'notes' => (string) ($b['notes'] ?? '')];
        }

        // ---------------- cierre (motivos funcionales)
        $blockers = [];
        if ($started) {
            try {
                $blockers = array_map([Labels::class, 'closeBlocker'], (new DeliveryService())->closeReadiness($id)['blockers']);
            } catch (\Throwable) {
                $blockers = [];
            }
        }

        // ---------------- auditoría de negocio (sin el detalle técnico)
        $audit = [];
        foreach ((new PurchasingEvent())->find(['requests_id' => $id], ['id DESC'], 200) as $e) {
            $audit[] = ['date' => (string) $e['date_creation'], 'event' => Labels::auditEvent((string) $e['event']),
                        'actor' => $this->userName((int) $e['actor_users_id'])];
        }

        $estimated = $this->money((string) $f['amount_estimated'], $cur, $overrides, $lang);
        return [
            'id'        => $id,
            'header'    => [
                'number'      => (string) ($f['number'] ?? ''),
                'state'       => $state,
                'state_label' => Labels::state($state),
                'phase_label' => Labels::phase(PurchasingWorkflow::phaseOf($state)),
                'requester'   => $this->userName((int) $f['users_id_requester']),
                'entity'      => \Dropdown::getDropdownName('glpi_entities', (int) $f['entities_id']),
                'department'  => (int) $f['groups_id_department'] > 0 ? \Dropdown::getDropdownName('glpi_groups', (int) $f['groups_id_department']) : '',
                'category'    => (string) $f['category'],
                'destination' => (string) $f['destination'],
                'reason'      => (string) ($f['reason'] ?? ''),
                'observations' => (string) ($f['observations'] ?? ''),
                'supplier_suggested' => (int) $f['suppliers_id_suggested'] > 0 ? \Dropdown::getDropdownName('glpi_suppliers', (int) $f['suppliers_id_suggested']) : '',
                'budget'      => (int) $f['budgets_id'] > 0 ? \Dropdown::getDropdownName('glpi_budgets', (int) $f['budgets_id']) : '',
                'currency'    => $cur,
                'amount'      => $estimated,
                'date_creation' => (string) $f['date_creation'],
                'date_mod'    => (string) $f['date_mod'],
                'purchase_started_at' => (string) ($f['purchase_started_at'] ?? ''),
                'sync_pending' => ReceivingSync::isPending($req),
                'frozen'      => $started,
            ],
            'entity_id'  => (int) $f['entities_id'],
            'lock_version' => (int) $f['lock_version'],
            'integrity'  => $integrity,
            'can'        => $can,
            'lines'      => $lines,
            'quotes'     => $quotes,
            'history'    => $history,
            'versions'   => $versions,
            'pdfs'       => $pdfs,
            'receipts'   => $receipts,
            'units'      => $units,
            'deliverable' => $deliverable,
            'deliveries' => $deliveries,
            'blockers'   => $blockers,
            'audit'      => $audit,
            'pdf_retry'  => array_filter($versions, static fn (array $v): bool => in_array($v['pdf_status'], [DocumentVersionAllocator::PDF_ERROR, DocumentVersionAllocator::PDF_PENDING], true)) !== [],
        ];
    }

    /** @return array{display:string, raw:string} */
    private function money(string $stored, string $cur, array $overrides, string $lang): array
    {
        try {
            $raw = Money::ofStored($stored, $cur, $overrides)->amount();
            return ['display' => MetricsMath::display($raw, $lang), 'raw' => $raw];
        } catch (\Throwable) {
            return ['display' => '', 'raw' => ''];
        }
    }

    /** @return array<int,string> items_id → precio final (exacto) */
    private function quotePrices(int $quoteId, string $cur, array $overrides): array
    {
        $out = [];
        foreach ((new \GlpiPlugin\Companypurchasing\Model\QuoteItem())->find(['quotes_id' => $quoteId]) as $qi) {
            try {
                $out[(int) $qi['items_id']] = Money::ofStored((string) $qi['final_unit_price'], $cur, $overrides)->amount();
            } catch (\Throwable) {
                $out[(int) $qi['items_id']] = '';
            }
        }
        return $out;
    }

    /**
     * Total EXACTO de la cotización (`QuoteMath`) o vacío si aún no cotiza todas las líneas.
     *
     * @param array<string,mixed> $q @param array<int,array<string,mixed>> $lines
     * @return array{display:string, raw:string}
     */
    private function quoteTotal(int $quoteId, array $q, array $lines, string $cur, array $overrides, string $lang): array
    {
        try {
            $prices = $this->quotePrices($quoteId, $cur, $overrides);
            $math = [];
            foreach ($lines as $l) {
                if (!isset($prices[$l['id']]) || $prices[$l['id']] === '') {
                    return ['display' => '', 'raw' => ''];
                }
                $math[] = ['line_id' => $l['id'], 'quantity' => $l['quantity'], 'final_unit_price' => $prices[$l['id']]];
            }
            $r = QuoteMath::compute($math,
                Money::ofStored((string) $q['discounts'], $cur, $overrides)->amount(),
                Money::ofStored((string) $q['taxes'], $cur, $overrides)->amount(),
                Money::ofStored((string) $q['freight'], $cur, $overrides)->amount(), $cur, $overrides);
            return ['display' => MetricsMath::display($r['total'], $lang), 'raw' => $r['total']];
        } catch (\Throwable) {
            return ['display' => '', 'raw' => ''];
        }
    }

    /** Enlace al Document NATIVO (descarga por la ruta nativa, con SU ACL). @return array{id:int, name:string, url:string} */
    private function documentLink(int $docId): array
    {
        global $CFG_GLPI;
        $doc = new \Document();
        $name = $doc->getFromDB($docId) ? (string) $doc->fields['name'] : '';
        return ['id' => $docId, 'name' => $name, 'url' => (string) ($CFG_GLPI['root_doc'] ?? '') . '/front/document.send.php?docid=' . $docId];
    }

    private function userName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $u = new \User();
        return $u->getFromDB($userId) ? (string) $u->getFriendlyName() : '';
    }
}
