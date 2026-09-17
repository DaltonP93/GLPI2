<?php

/**
 * Listener de eventos de `companyworkflow` (gate §4/§10/§13 + hardening §1–§5).
 *
 * Consume (todos llevan `workflow_history_id`, id DURABLE de la fila del ledger):
 *   - `companyworkflow:decision_recorded`   → evidencia de DECISIÓN por aprobador (approve/reject/return).
 *   - `companyworkflow:transitioned`        → evidencia de TRANSICIÓN (evento separado).
 *   - `companyworkflow:approval_invalidated`→ evidencia(s) de INVALIDACIÓN (referencia exacta).
 *
 * Delega TODO en `Materializer`, que es idempotente, fail-closed de snapshot y recuperable por
 * reconciliación. El listener sólo traduce el evento a un `workflow_history_id`; si el proceso cae,
 * `plugins:companysignature:reconcile` materializa lo mismo exactamente una vez.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

final class WorkflowEventListener
{
    private Materializer $materializer;

    public function __construct(?Materializer $materializer = null)
    {
        $this->materializer = $materializer ?? new Materializer();
    }

    /** @param array<string,mixed> $payload */
    public function onDecisionRecorded(array $payload): int
    {
        return $this->handle($payload);
    }

    /** @param array<string,mixed> $payload */
    public function onTransitioned(array $payload): int
    {
        return $this->handle($payload);
    }

    /** @param array<string,mixed> $payload */
    public function onApprovalInvalidated(array $payload): int
    {
        return $this->handle($payload);
    }

    /** @param array<string,mixed> $payload */
    private function handle(array $payload): int
    {
        if (!PluginConfig::boolean('listen_workflow_events')) {
            return 0;
        }
        $historyId = (int) ($payload['workflow_history_id'] ?? 0);
        if ($historyId <= 0) {
            return 0; // sin id durable no hay materialización en vivo; lo tomará la reconciliación
        }
        // Si queda pendiente (aún sin evidence_ref/snapshot), la CronTask/reconcile lo materializa luego.
        return (int) ($this->materializer->materializeByHistoryId($historyId)['created'] ?? 0);
    }
}
