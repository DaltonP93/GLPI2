<?php

/**
 * PROYECCIÓN del estado del motor en `requests.domain_state` (P2D-2).
 *
 * `companyworkflow` es la AUTORIDAD; `domain_state` es sólo una cache derivada para listados. Esta clase
 * NO decide estados: copia el estado CONFIRMADO de la instancia (leído fresco) y su `lock_version`. Ante
 * discrepancia, el motor gana. Idempotente (sin cambios ⇒ sin escritura). No incrementa el
 * `lock_version` de negocio de la solicitud (no es una mutación de negocio).
 *
 * Auto-reparación: si una caída ocurrió entre `startInstance()` y el enlace local, re-enlaza la instancia
 * por `(itemtype, items_id)` (UNIQUE en el motor).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\Request;

class StateProjection
{
    private WorkflowGateway $wf;
    private Audit $audit;

    public function __construct(?WorkflowGateway $wf = null, ?Audit $audit = null)
    {
        $this->wf    = $wf ?? new WorkflowGateway();
        $this->audit = $audit ?? new Audit();
    }

    /** Listener: proyecta la solicitud dueña de la instancia (si es de Compras). */
    public function syncInstance(int $instanceId): bool
    {
        $inst = $this->wf->loadInstance($instanceId);
        if ($inst === null || (string) $inst->fields['itemtype'] !== Request::class) {
            return false;
        }
        $req = new Request();
        if (!$req->getFromDB((int) $inst->fields['items_id'])) {
            return false;
        }
        return $this->sync($req)['changed'];
    }

    /**
     * @param string $source  'live' (orquestador/listener) | 'reconcile' (audita la corrección)
     * @return array{changed:bool, linked:bool, instance:bool, from:string, to:string, lock_version:int}
     */
    public function sync(Request $req, string $source = 'live'): array
    {
        $reqId = (int) $req->getID();
        $req->getFromDB($reqId); // relectura fresca
        $from = (string) ($req->fields['domain_state'] ?? '');
        $none = ['changed' => false, 'linked' => false, 'instance' => false, 'from' => $from, 'to' => $from, 'lock_version' => (int) ($req->fields['workflow_lock_version'] ?? 0)];

        $instId = (int) ($req->fields['workflow_instances_id'] ?? 0);
        $inst = $instId > 0 ? $this->wf->loadInstance($instId) : $this->wf->findInstance(Request::class, $reqId);
        if ($inst === null) {
            return $none;
        }
        if ((string) $inst->fields['itemtype'] !== Request::class || (int) $inst->fields['items_id'] !== $reqId) {
            throw new \RuntimeException('la instancia enlazada no pertenece a la solicitud (fail-closed)');
        }
        $code = $this->wf->stateCode($inst);
        $lock = (int) $inst->fields['lock_version'];
        if ($code === '') {
            return ['instance' => true] + $none;
        }
        $fields = [];
        if ($instId !== (int) $inst->getID()) {
            $fields['workflow_instances_id'] = (int) $inst->getID();
        }
        if ($from !== $code) {
            $fields['domain_state'] = $code;
        }
        if ((int) ($req->fields['workflow_lock_version'] ?? 0) !== $lock) {
            $fields['workflow_lock_version'] = $lock;
        }
        if ($fields === []) {
            return ['changed' => false, 'linked' => false, 'instance' => true, 'from' => $from, 'to' => $code, 'lock_version' => $lock];
        }
        $fields['id'] = $reqId;
        $fields['workflow_synced_at'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        if (!$req->update($fields)) {
            throw new \RuntimeException('no se pudo proyectar el estado del workflow en la solicitud');
        }
        if ($source === 'reconcile' && $from !== $code) {
            $this->audit->recordOnce($reqId, PurchasingEvent::EV_STATE_RECONCILED, (int) $req->fields['entities_id'], [
                'from' => $from, 'to' => $code, 'workflow_lock_version' => $lock,
            ], (string) ($req->fields['correlation_id'] ?? ''), 'reconciled:' . $reqId . ':' . $lock);
        }
        return [
            'changed'      => true,
            'linked'       => isset($fields['workflow_instances_id']),
            'instance'     => true,
            'from'         => $from,
            'to'           => $code,
            'lock_version' => $lock,
        ];
    }
}
