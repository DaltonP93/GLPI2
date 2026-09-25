<?php

/**
 * SAGA idempotente que lleva el estado del MOTOR a reflejar los CONTADORES FÍSICOS de recepción (P2D-3).
 *
 * Autoridades (no se mezclan):
 *   - `companyworkflow` es la autoridad del ESTADO (IN_PURCHASE / PARTIALLY_RECEIVED / RECEIVED).
 *   - Los contadores de Compras (`items.ordered_qty` / `items.received_qty`) son la autoridad del HECHO de
 *     recepción. Jamás se deshacen unidades físicas para "seguir" al motor.
 *
 * Nunca se ejecuta dentro de la transacción de recepción: la recepción local confirma PRIMERO y esta saga
 * corre después (serializada por el lock común de la solicitud). Marcador DURABLE: `requests.receiving_seq`
 * (se incrementa en la MISMA transacción que el inicio de compra y que cada lote) y
 * `requests.receiving_synced_seq` (último seq cuyo estado ya refleja el motor). `seq > synced_seq` ⇒
 * sincronización PENDIENTE, recuperable por la Acción automática `reconcileprojection` (contexto de sistema de
 * la CronTask nativa) o por el próximo intento en vivo. Convergente e idempotente: la transición usa
 * `expected_lock_version` y la condición `purchase_bound`/`receipt_bound` que sólo aporta este código.
 *
 * Reglas (`PurchasingWorkflow::receivingTarget`): 0 recibido ⇒ IN_PURCHASE; 0 < recibido < ordenado ⇒
 * PARTIALLY_RECEIVED; todas las líneas completas ⇒ RECEIVED. Motor "adelantado", fuera de la fase o con una
 * versión de definición anterior sin fase de compra ⇒ ANOMALÍA reportada (sin mutar nada).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Model\RequestItem;

class ReceivingSync
{
    public const ST_NOT_STARTED = 'not_started';
    public const ST_CONVERGED   = 'converged';
    public const ST_PENDING     = 'pending';
    public const ST_ANOMALY     = 'anomaly';

    public const ANOMALY_LEGACY_DEFINITION = 'legacy_definition';
    public const ANOMALY_ENGINE_AHEAD      = 'engine_ahead_or_outside_purchase';
    public const ANOMALY_INSTANCE          = 'instance_missing_or_closed';
    public const ANOMALY_COUNTERS          = 'counters_inconsistent';

    private WorkflowGateway $wf;
    private Audit $audit;
    private AdvisoryLock $lock;
    private StateProjection $projection;

    public function __construct(?WorkflowGateway $wf = null, ?Audit $audit = null, ?AdvisoryLock $lock = null)
    {
        $this->wf         = $wf ?? new WorkflowGateway();
        $this->audit      = $audit ?? new Audit();
        $this->lock       = $lock ?? new AdvisoryLock();
        $this->projection = new StateProjection($this->wf, $this->audit);
    }

    /**
     * Converge (bajo el lock de la solicitud). Nunca lanza por un rechazo del motor: lo devuelve como
     * `pending` (el marcador durable persiste). Lanza sólo si la solicitud no existe.
     *
     * @return array{status:string, state:string, target:string, seq:int, synced_seq:int, applied:array<int,string>, anomaly:?string, error:?string}
     */
    public function sync(int $requestId, string $source = 'live'): array
    {
        return (array) $this->lock->withRequestLock($requestId, fn (): array => $this->syncLocked($requestId, $source));
    }

    /** ¿Hay una sincronización pendiente según el marcador durable? (sólo lectura) */
    public static function isPending(Request $req): bool
    {
        return !empty($req->fields['purchase_started_at'])
            && (int) ($req->fields['receiving_seq'] ?? 0) > (int) ($req->fields['receiving_synced_seq'] ?? 0);
    }

    /** @return array{status:string, state:string, target:string, seq:int, synced_seq:int, applied:array<int,string>, anomaly:?string, error:?string} */
    private function syncLocked(int $requestId, string $source): array
    {
        $req = new Request();
        if ($requestId <= 0 || !$req->getFromDB($requestId)) {
            throw new \RuntimeException('solicitud inexistente');
        }
        // (1) seq PRIMERO y contadores DESPUÉS: los contadores leídos reflejan al menos todos los lotes hasta seq,
        //     así que marcar `synced_seq = seq` nunca afirma más de lo observado.
        $seq    = (int) ($req->fields['receiving_seq'] ?? 0);
        $synced = (int) ($req->fields['receiving_synced_seq'] ?? 0);
        $out = ['status' => self::ST_NOT_STARTED, 'state' => (string) ($req->fields['domain_state'] ?? ''), 'target' => '',
                'seq' => $seq, 'synced_seq' => $synced, 'applied' => [], 'anomaly' => null, 'error' => null];
        if (empty($req->fields['purchase_started_at'])) {
            return $out;
        }
        try {
            $target = PurchasingWorkflow::receivingTarget($this->counters($requestId));
        } catch (\Throwable $e) {
            return $this->anomaly($req, $out, self::ANOMALY_COUNTERS, '', $seq, $e->getMessage());
        }
        $out['target'] = $target;

        $instId = (int) ($req->fields['workflow_instances_id'] ?? 0);
        $inst = $instId > 0 ? $this->wf->loadInstance($instId) : null;
        if ($inst === null || !$this->wf->isOpen($inst)
            || (string) $inst->fields['itemtype'] !== Request::class || (int) $inst->fields['items_id'] !== $requestId) {
            return $this->anomaly($req, $out, self::ANOMALY_INSTANCE, $target, $seq);
        }
        $current = $this->wf->stateCode($inst);
        $out['state'] = $current;
        $path = PurchasingWorkflow::syncPath($current, $target);
        if ($path === null) {
            return $this->anomaly($req, $out, self::ANOMALY_ENGINE_AHEAD, $target, $seq);
        }
        if ($path !== [] && !$this->wf->definitionHasAction($inst, $path[0])) {
            // Instancia iniciada bajo una versión ANTERIOR de la definición (sin fase de compra): conserva su
            // versión; se reporta y NO se muta en silencio.
            return $this->anomaly($req, $out, self::ANOMALY_LEGACY_DEFINITION, $target, $seq);
        }

        foreach ($path as $action) {
            $lockBefore = (int) $inst->fields['lock_version'];
            $from = $this->wf->stateCode($inst);
            $res = $this->wf->transition($inst, $action, [
                'comment'               => 'receiving sync (' . $source . ')',
                // Condiciones de la definición: sólo este código las aporta (hecho local ya confirmado).
                'fields'                => ['purchase_bound' => 1, 'receipt_bound' => 1, 'receiving_seq' => $seq],
                'expected_lock_version' => $lockBefore,
            ]);
            if (!$res->success) {
                $out['status'] = self::ST_PENDING;
                $out['error']  = $res->code . ' — ' . $res->message;
                $this->projection->sync($req, $source);
                return $out;
            }
            $out['applied'][] = $action;
            $this->audit->recordOnce($requestId, PurchasingEvent::EV_RECEIVING_SYNCED, (int) $req->fields['entities_id'], [
                'action' => $action, 'from' => $from, 'to' => (string) ($res->data['to'] ?? ''), 'target' => $target,
                'receiving_seq' => $seq, 'workflow_lock_version' => $lockBefore, 'source' => $source,
            ], (string) $req->fields['correlation_id'], 'receiving-sync:' . $requestId . ':' . (int) $inst->getID() . ':' . $lockBefore . ':' . $action);
            $inst = $this->wf->loadInstance((int) $inst->getID());
            if ($inst === null) {
                return $this->anomaly($req, $out, self::ANOMALY_INSTANCE, $target, $seq);
            }
        }

        // (2) Convergido para `seq`: marcador monotónico (nunca retrocede; un lote posterior lo deja pendiente).
        /** @var \DBmysql $DB */
        global $DB;
        if ($synced < $seq) {
            $DB->update(Request::getTable(), ['receiving_synced_seq' => $seq], [
                'id' => $requestId, 'receiving_synced_seq' => ['<', $seq],
            ]);
        }
        $out['status'] = self::ST_CONVERGED;
        $out['state'] = $this->wf->stateCode($inst);
        $out['synced_seq'] = max($synced, $seq);
        $this->projection->sync($req, $source);
        return $out;
    }

    /** @return array<int,array{ordered_qty:int, received_qty:int}> */
    private function counters(int $requestId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'ordered_qty', 'received_qty'],
            'FROM'   => RequestItem::getTable(),
            'WHERE'  => ['requests_id' => $requestId],
            'ORDER'  => 'id ASC',
        ]) as $row) {
            $out[] = ['ordered_qty' => (int) $row['ordered_qty'], 'received_qty' => (int) $row['received_qty']];
        }
        return $out;
    }

    /**
     * Reporta (auditoría idempotente por seq) una anomalía NO convergible sin mutar nada.
     *
     * @param array<string,mixed> $out
     * @return array{status:string, state:string, target:string, seq:int, synced_seq:int, applied:array<int,string>, anomaly:?string, error:?string}
     */
    private function anomaly(Request $req, array $out, string $kind, string $target, int $seq, string $detail = ''): array
    {
        $out['status']  = self::ST_ANOMALY;
        $out['anomaly'] = $kind;
        $out['target']  = $target;
        try {
            $this->audit->recordOnce((int) $req->getID(), PurchasingEvent::EV_RECEIVING_ANOMALY, (int) $req->fields['entities_id'], [
                'anomaly' => $kind, 'state' => $out['state'], 'target' => $target, 'receiving_seq' => $seq,
                'detail' => substr($detail, 0, 250),
            ], (string) $req->fields['correlation_id'], 'receiving-anomaly:' . (int) $req->getID() . ':' . $kind . ':' . $seq);
        } catch (\Throwable) {
            // best-effort: la anomalía se devuelve igual al llamador (reconcile la reporta).
        }
        return $out;
    }
}
