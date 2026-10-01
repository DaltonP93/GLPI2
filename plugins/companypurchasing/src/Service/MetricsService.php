<?php

/**
 * Métricas / tablero de Compras v1 (P2D-4; ADR-0023 §10) — SIN data warehouse: se calcula desde las tablas PROPIAS
 * (`requests`, `items`, `quotes`, `quote_items`, `receipt_units`, `delivery_batches`, `events`, `inventory_outbox`) y el
 * ledger del motor por su API (`WorkflowApi::history`), siempre con FILTRO DE ENTIDAD.
 *
 * Seguridad: `RIGHT_VIEW_METRICS` obligatorio (fail-closed). Las entidades son las ACTIVAS de la sesión (opcionalmente
 * acotadas a una de ellas); un usuario de A jamás ve conteos ni montos de B.
 *
 * Dinero: micro-unidades exactas por moneda (`MetricsMath`), nunca float, nunca se suman monedas distintas, PYG
 * escala 0.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\DeliveryBatch;
use GlpiPlugin\Companypurchasing\Model\OutboxEntry;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\ReceiptUnit;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;

final class MetricsService
{
    /** Estados en los que la solicitud ya fue APROBADA (aprobada o posterior). */
    public const APPROVED_OR_LATER = [
        PurchasingWorkflow::S_APPROVED, PurchasingWorkflow::S_IN_PURCHASE, PurchasingWorkflow::S_PARTIALLY_RECEIVED,
        PurchasingWorkflow::S_RECEIVED, PurchasingWorkflow::S_DELIVERED, PurchasingWorkflow::S_CLOSED,
    ];

    public const OUTBOX_STATUSES = ['PENDING', 'LEASED', 'RETRY', 'ERROR', 'DONE'];

    private WorkflowGateway $wf;

    public function __construct(?WorkflowGateway $wf = null)
    {
        $this->wf = $wf ?? new WorkflowGateway();
    }

    /**
     * @param array{from?:string, to?:string, entities_id?:int|string|null} $filter
     * @return array<string,mixed>
     * @throws \RuntimeException sin derecho (fail-closed)
     */
    public function compute(array $filter = []): array
    {
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_VIEW_METRICS)) {
            throw new \RuntimeException('permiso denegado (VIEW_METRICS)');
        }
        $entities = self::scopeEntities($filter['entities_id'] ?? null);
        $out = self::emptyResult($entities);
        if ($entities === []) {
            return $out;
        }
        $overrides = PluginConfig::currencyScaleOverrides();

        // ---------------- solicitudes del alcance (entidad + período de creación)
        /** @var \DBmysql $DB */
        global $DB;
        $where = ['entities_id' => $entities, 'NOT' => ['domain_state' => Request::STATE_DRAFT]];
        $from = self::validDate($filter['from'] ?? null);
        $to   = self::validDate($filter['to'] ?? null);
        if ($from !== null) {
            $where[] = ['date_creation' => ['>=', $from . ' 00:00:00']];
        }
        if ($to !== null) {
            $where[] = ['date_creation' => ['<=', $to . ' 23:59:59']];
        }
        $reqs = [];
        foreach ($DB->request(['FROM' => Request::getTable(), 'WHERE' => $where, 'ORDER' => 'id ASC']) as $r) {
            $reqs[(int) $r['id']] = $r;
        }
        $ids = array_keys($reqs);

        $items = [];
        $quoteIds = [];
        if ($ids !== []) {
            foreach ($DB->request(['FROM' => RequestItem::getTable(), 'WHERE' => ['requests_id' => $ids]]) as $it) {
                $items[(int) $it['requests_id']][(int) $it['id']] = $it;
            }
            foreach ($reqs as $r) {
                foreach (['quotes_id_selected', 'purchase_quotes_id'] as $k) {
                    if ((int) $r[$k] > 0) {
                        $quoteIds[(int) $r[$k]] = true;
                    }
                }
            }
        }
        $quotes = [];
        $quotePrices = [];
        if ($quoteIds !== []) {
            foreach ($DB->request(['FROM' => 'glpi_plugin_companypurchasing_quotes', 'WHERE' => ['id' => array_keys($quoteIds), 'requests_id' => $ids]]) as $q) {
                $quotes[(int) $q['id']] = $q;
            }
            foreach ($DB->request(['FROM' => 'glpi_plugin_companypurchasing_quote_items', 'WHERE' => ['quotes_id' => array_keys($quotes)]]) as $qi) {
                $quotePrices[(int) $qi['quotes_id']][(int) $qi['items_id']] = (string) $qi['final_unit_price'];
            }
        }

        $amounts = ['requested' => [], 'approved' => [], 'purchased' => []];
        $dims = ['entity' => [], 'department' => [], 'category' => [], 'supplier' => []];
        $inexact = 0;
        foreach ($reqs as $id => $r) {
            $state = (string) $r['domain_state'];
            $cur = (string) $r['currency_code'];
            $out['counts']['by_state'][$state] = ($out['counts']['by_state'][$state] ?? 0) + 1;
            $month = substr((string) $r['date_creation'], 0, 7);
            if ($month !== '') {
                $out['counts']['by_month'][$month] = ($out['counts']['by_month'][$month] ?? 0) + 1;
            }
            $submitted = (int) $r['number_seq'] > 0;
            $approved = in_array($state, self::APPROVED_OR_LATER, true);
            $out['counts']['requested'] += $submitted ? 1 : 0;
            $out['counts']['approved'] += $approved ? 1 : 0;
            $out['counts']['closed'] += $state === PurchasingWorkflow::S_CLOSED ? 1 : 0;

            $req = $apr = $pur = [];
            try {
                if ($submitted) {
                    MetricsMath::addStored($req, $cur, (string) $r['amount_estimated']);
                }
                $started = !empty($r['purchase_started_at']);
                $qid = $started ? (int) $r['purchase_quotes_id'] : (int) $r['quotes_id_selected'];
                if ($approved && $qid > 0 && isset($quotes[$qid])) {
                    $apr = self::quoteTotal($quotes[$qid], $quotePrices[$qid] ?? [], $items[$id] ?? [], $cur, $started);
                    if ($started) {
                        $pur = self::purchaseTotal($quotes[$qid], $items[$id] ?? [], $cur);
                    }
                }
            } catch (\Throwable) {
                $inexact++; // dato inconsistente: no se suma (se reporta), jamás se aproxima
                $req = $apr = $pur = [];
            }
            MetricsMath::merge($amounts['requested'], $req);
            MetricsMath::merge($amounts['approved'], $apr);
            MetricsMath::merge($amounts['purchased'], $pur);

            $supplier = (int) ($r['purchase_suppliers_id'] ?? 0);
            if ($supplier <= 0 && (int) $r['quotes_id_selected'] > 0) {
                $supplier = (int) ($quotes[(int) $r['quotes_id_selected']]['suppliers_id'] ?? 0);
            }
            foreach ([
                'entity'     => (string) (int) $r['entities_id'],
                'department' => (string) (int) $r['groups_id_department'],
                'category'   => (string) $r['category'],
                'supplier'   => (string) $supplier,
            ] as $dim => $key) {
                $dims[$dim][$key] ??= ['count' => 0, 'requested' => [], 'approved' => [], 'purchased' => []];
                $dims[$dim][$key]['count']++;
                MetricsMath::merge($dims[$dim][$key]['requested'], $req);
                MetricsMath::merge($dims[$dim][$key]['approved'], $apr);
                MetricsMath::merge($dims[$dim][$key]['purchased'], $pur);
            }

            // Recepción (sólo compras iniciadas): pendiente / parcial / completa por contadores físicos.
            if (!empty($r['purchase_started_at'])) {
                $ord = $rec = 0;
                foreach ($items[$id] ?? [] as $it) {
                    $ord += (int) $it['ordered_qty'];
                    $rec += (int) $it['received_qty'];
                }
                $key = $rec === 0 ? 'pending' : ($rec < $ord ? 'partial' : 'complete');
                $out['receiving'][$key]++;
            }
        }
        foreach ($amounts as $k => $acc) {
            $out['amounts'][$k] = MetricsMath::format($acc, $overrides);
        }
        foreach ($dims as $dim => $rows) {
            ksort($rows, SORT_STRING);
            foreach ($rows as $key => $row) {
                $out['breakdown'][$dim][] = [
                    'key'       => (string) $key,
                    'label'     => self::dimLabel((string) $dim, (string) $key),
                    'count'     => $row['count'],
                    'requested' => MetricsMath::format($row['requested'], $overrides),
                    'approved'  => MetricsMath::format($row['approved'], $overrides),
                    'purchased' => MetricsMath::format($row['purchased'], $overrides),
                ];
            }
        }
        $out['inexact'] = $inexact;
        ksort($out['counts']['by_state'], SORT_STRING);
        ksort($out['counts']['by_month'], SORT_STRING);

        // ---------------- ciclo total (envío → cierre) desde la auditoría PROPIA
        if ($ids !== []) {
            $submittedAt = [];
            $closedAt = [];
            foreach ($DB->request(['SELECT' => ['requests_id', 'event', 'date_creation'], 'FROM' => PurchasingEvent::getTable(),
                                   'WHERE' => ['requests_id' => $ids, 'entities_id' => $entities,
                                               'event' => [PurchasingEvent::EV_REQUEST_SUBMITTED, PurchasingEvent::EV_REQUEST_CLOSED]]]) as $e) {
                $ts = strtotime((string) $e['date_creation']);
                if ($ts === false) {
                    continue;
                }
                $rid = (int) $e['requests_id'];
                if ((string) $e['event'] === PurchasingEvent::EV_REQUEST_SUBMITTED) {
                    $submittedAt[$rid] = min($submittedAt[$rid] ?? PHP_INT_MAX, $ts);
                } else {
                    $closedAt[$rid] = max($closedAt[$rid] ?? 0, $ts);
                }
            }
            $cycle = ['count' => 0, 'total' => 0, 'max' => 0];
            foreach ($closedAt as $rid => $c) {
                if (isset($submittedAt[$rid]) && $c >= $submittedAt[$rid]) {
                    $secs = $c - $submittedAt[$rid];
                    $cycle['count']++;
                    $cycle['total'] += $secs;
                    $cycle['max'] = max($cycle['max'], $secs);
                }
            }
            $out['cycle'] = MetricsMath::hours($cycle);

            // ---------------- duración por etapa desde el ledger del motor (API), acotado
            $instIds = [];
            foreach ($reqs as $r) {
                if ((int) $r['workflow_instances_id'] > 0) {
                    $instIds[] = (int) $r['workflow_instances_id'];
                }
            }
            $instIds = array_slice($instIds, 0, PluginConfig::metricsMaxRequests());
            try {
                $rows = $this->wf->historyFor($instIds, MetricsMath::ENTRY_EVENTS);
                foreach (MetricsMath::stageDurations($rows, time()) as $code => $agg) {
                    $out['stages'][$code] = MetricsMath::hours($agg);
                }
            } catch (\Throwable) {
                $out['stages'] = [];
            }
        }

        // ---------------- inventario (outbox PROPIO) y entregas, por entidad
        foreach ($DB->request(['SELECT' => ['status'], 'COUNT' => 'n', 'FROM' => OutboxEntry::getTable(),
                               'WHERE' => ['entities_id' => $entities] + ($ids !== [] ? ['requests_id' => $ids] : ['requests_id' => 0]),
                               'GROUPBY' => 'status']) as $o) {
            $st = (string) $o['status'];
            if (array_key_exists($st, $out['inventory'])) {
                $out['inventory'][$st] = (int) $o['n'];
            }
        }
        if ($ids !== []) {
            $out['delivery']['units_pending'] = countElementsInTable(ReceiptUnit::getTable(),
                ['entities_id' => $entities, 'requests_id' => $ids, 'physical_state' => ReceiptUnit::PHYSICAL_RECEIVED]);
            $out['delivery']['units_delivered'] = countElementsInTable(ReceiptUnit::getTable(),
                ['entities_id' => $entities, 'requests_id' => $ids, 'physical_state' => ReceiptUnit::PHYSICAL_DELIVERED]);
            $out['delivery']['batches'] = countElementsInTable(DeliveryBatch::getTable(), ['entities_id' => $entities, 'requests_id' => $ids]);
        }
        return $out;
    }

    /**
     * Entidades del alcance: las ACTIVAS de la sesión; si se pide una, debe estar entre ellas (si no ⇒ ninguna).
     *
     * @return array<int,int>
     */
    public static function scopeEntities(mixed $requested): array
    {
        $active = array_values(array_unique(array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []))));
        $active = array_values(array_filter($active, static fn (int $e): bool => Session::haveAccessToEntity($e)));
        if ($requested === null || $requested === '' || (int) $requested < 0) {
            return $active;
        }
        return in_array((int) $requested, $active, true) ? [(int) $requested] : [];
    }

    /** @param array<int,int> $entities @return array<string,mixed> */
    private static function emptyResult(array $entities): array
    {
        return [
            'entities'  => $entities,
            'counts'    => ['by_state' => [], 'by_month' => [], 'requested' => 0, 'approved' => 0, 'closed' => 0],
            'amounts'   => ['requested' => [], 'approved' => [], 'purchased' => []],
            'breakdown' => ['entity' => [], 'department' => [], 'category' => [], 'supplier' => []],
            'cycle'     => ['count' => 0, 'avg_hours' => 0, 'max_hours' => 0],
            'stages'    => [],
            'receiving' => ['pending' => 0, 'partial' => 0, 'complete' => 0],
            'inventory' => array_fill_keys(self::OUTBOX_STATUSES, 0),
            'delivery'  => ['units_pending' => 0, 'units_delivered' => 0, 'batches' => 0],
            'inexact'   => 0,
        ];
    }

    /**
     * Total comercial EXACTO de una cotización: Σ precio final × cantidad + impuestos + flete − descuentos. Cantidad =
     * `ordered_qty` (congelada) si la compra se inició; si no, la cantidad aprobada de la línea.
     *
     * @param array<string,mixed> $quote @param array<int,string> $prices @param array<int,array<string,mixed>> $lines
     * @return array<string,string>
     */
    private static function quoteTotal(array $quote, array $prices, array $lines, string $cur, bool $started): array
    {
        $acc = [];
        foreach ($lines as $lineId => $l) {
            if (!isset($prices[$lineId])) {
                throw new \RuntimeException('cotización sin precio para una línea');
            }
            MetricsMath::addStored($acc, $cur, $prices[$lineId], (int) ($started ? $l['ordered_qty'] : (int) $l['quantity']));
        }
        MetricsMath::addStored($acc, $cur, (string) $quote['taxes']);
        MetricsMath::addStored($acc, $cur, (string) $quote['freight']);
        MetricsMath::subStored($acc, $cur, (string) $quote['discounts']);
        return $acc;
    }

    /**
     * Monto COMPRADO: Σ precio congelado (`purchase_unit_price`) × `ordered_qty` + impuestos + flete − descuentos de la
     * cotización de la compra.
     *
     * @param array<string,mixed> $quote @param array<int,array<string,mixed>> $lines
     * @return array<string,string>
     */
    private static function purchaseTotal(array $quote, array $lines, string $cur): array
    {
        $acc = [];
        foreach ($lines as $l) {
            MetricsMath::addStored($acc, $cur, (string) $l['purchase_unit_price'], (int) $l['ordered_qty']);
        }
        MetricsMath::addStored($acc, $cur, (string) $quote['taxes']);
        MetricsMath::addStored($acc, $cur, (string) $quote['freight']);
        MetricsMath::subStored($acc, $cur, (string) $quote['discounts']);
        return $acc;
    }

    private static function dimLabel(string $dim, string $key): string
    {
        return match ($dim) {
            'entity'     => \Dropdown::getDropdownName('glpi_entities', (int) $key),
            'department' => (int) $key > 0 ? \Dropdown::getDropdownName('glpi_groups', (int) $key) : __('Without department', 'companypurchasing'),
            'supplier'   => (int) $key > 0 ? \Dropdown::getDropdownName('glpi_suppliers', (int) $key) : __('Without supplier', 'companypurchasing'),
            default      => $key !== '' ? $key : __('Without category', 'companypurchasing'),
        };
    }

    private static function validDate(mixed $d): ?string
    {
        if (!is_string($d) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
            return null;
        }
        [$y, $m, $day] = array_map('intval', explode('-', $d));
        return checkdate($m, $day, $y) ? $d : null;
    }
}
