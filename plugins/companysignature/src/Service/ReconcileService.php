<?php

/**
 * Reconciliación DURABLE con estado propio (§3): HARVEST + WORKER.
 *
 * - HARVEST: lee el ledger de `companyworkflow` DESDE un high-watermark (`last_seen_history_id`),
 *   ENCOLA cada evento relevante en `reconcile_queue` (idempotente por `UNIQUE(workflow_history_id)`)
 *   y AVANZA el watermark. No hace full-scan del historial: sólo lo nuevo desde el cursor.
 * - WORKER: procesa los pendientes de la cola INDEPENDIENTEMENTE (orden causal por
 *   `workflow_history_id`), con reintentos y backoff. Un evento sin `evidence_ref`/snapshot queda
 *   `pending` (reintenta) sin bloquear a los posteriores, y no se pierde al avanzar el watermark.
 *
 * Sobrevive a reinicios (cola + watermark viven en BD) y es idempotente (la UNIQUE de la cola y de
 * `evidences` garantizan "exactamente una vez"). NUNCA aprueba/rechaza: sólo materializa evidencia
 * ya comprometida en el ledger.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

use Config;
use GlpiPlugin\Companysignature\Model\ReconcileTask;

final class ReconcileService
{
    private const MAX_ATTEMPTS = 24; // tras esto, un 'error' deja de reintentarse (queda registrado)

    private Materializer $materializer;

    public function __construct(?Materializer $materializer = null)
    {
        $this->materializer = $materializer ?? new Materializer();
    }

    /**
     * Harvest + Worker.
     * @return array{enqueued:int, processed:int, materialized:int, pending:int, errored:int}
     */
    public function run(): array
    {
        $enqueued = $this->harvest();
        $work = $this->work();
        return ['enqueued' => $enqueued] + $work;
    }

    /** Encola los eventos nuevos del ledger y avanza el watermark. Devuelve nº encolados. */
    public function harvest(): int
    {
        $wfApiClass = 'GlpiPlugin\\Companyworkflow\\Api\\WorkflowApi';
        if (!class_exists($wfApiClass)) {
            return 0;
        }
        $watermark = (int) PluginConfig::get('last_seen_history_id', '0');
        $batch     = max(1, (int) PluginConfig::get('reconcile_harvest_batch', '500'));

        /** @var object $api */
        $api = new $wfApiClass();
        $rows = $api->history([
            'events'   => [Materializer::WF_DECISION_RECORDED, Materializer::WF_TRANSITIONED, Materializer::WF_APPROVAL_INVALIDATED],
            'since_id' => $watermark,
            'limit'    => $batch,
        ]);

        $now = gmdate('Y-m-d H:i:s');
        $enqueued = 0;
        $maxId = $watermark;
        foreach ($rows as $row) {
            $hid = (int) ($row['id'] ?? 0);
            if ($hid <= 0) {
                continue;
            }
            $maxId = max($maxId, $hid);
            if ($this->queueExists($hid)) {
                continue; // idempotente
            }
            $ok = (new ReconcileTask())->add([
                'workflow_history_id' => $hid,
                'event'               => (string) ($row['event'] ?? ''),
                'instances_id'        => (int) ($row['instances_id'] ?? 0),
                'status'              => ReconcileTask::STATUS_PENDING,
                'attempts'            => 0,
                'next_retry_at'       => $now,
                'date_creation'       => $now,
                'date_mod'            => $now,
            ]);
            if ($ok) {
                $enqueued++;
            }
        }
        if ($maxId > $watermark) {
            // El watermark avanza SÓLO tras haber encolado durablemente lo escaneado.
            Config::setConfigurationValues(PluginConfig::CONTEXT, ['last_seen_history_id' => (string) $maxId]);
        }
        return $enqueued;
    }

    /**
     * Procesa pendientes/reintentables de la cola (orden causal).
     * @return array{processed:int, materialized:int, pending:int, errored:int}
     */
    public function work(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $now   = gmdate('Y-m-d H:i:s');
        $batch = max(1, (int) PluginConfig::get('reconcile_work_batch', '200'));

        // Candidatos: pendientes o errores aún reintetables. La elegibilidad por `next_retry_at` y el
        // tope de intentos se filtran en PHP (cola pequeña; evita SQL anidado frágil).
        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => ReconcileTask::getTable(),
            'WHERE'  => ['status' => [ReconcileTask::STATUS_PENDING, ReconcileTask::STATUS_ERROR]],
            'ORDER'  => 'workflow_history_id ASC',
            'LIMIT'  => $batch,
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        $processed = 0;
        $materialized = 0;
        $pending = 0;
        $errored = 0;
        foreach ($ids as $id) {
            $task = new ReconcileTask();
            if (!$task->getFromDB($id)) {
                continue;
            }
            // No reintentar errores agotados ni filas cuyo backoff aún no vence.
            if ((string) $task->fields['status'] === ReconcileTask::STATUS_ERROR && (int) $task->fields['attempts'] >= self::MAX_ATTEMPTS) {
                continue;
            }
            $retryAt = (string) ($task->fields['next_retry_at'] ?? '');
            if ($retryAt !== '' && $retryAt > $now) {
                continue;
            }
            $processed++;
            $attempts = (int) $task->fields['attempts'] + 1;
            $outcome = $this->materializer->materializeByHistoryId((int) $task->fields['workflow_history_id']);
            $materialized += (int) ($outcome['created'] ?? 0);
            $status = (string) ($outcome['status'] ?? Materializer::R_DONE);

            if ($status === Materializer::R_DONE) {
                $task->update(['id' => $id, 'status' => ReconcileTask::STATUS_DONE, 'attempts' => $attempts, 'last_error' => null, 'date_mod' => $now]);
            } elseif ($status === Materializer::R_ERROR) {
                $errored++;
                $task->update(['id' => $id, 'status' => ReconcileTask::STATUS_ERROR, 'attempts' => $attempts, 'last_error' => 'evidence_ref inconsistente', 'next_retry_at' => $this->backoff($attempts), 'date_mod' => $now]);
            } else { // pending
                $pending++;
                $task->update(['id' => $id, 'status' => ReconcileTask::STATUS_PENDING, 'attempts' => $attempts, 'last_error' => null, 'next_retry_at' => $this->backoff($attempts), 'date_mod' => $now]);
            }
        }
        return ['processed' => $processed, 'materialized' => $materialized, 'pending' => $pending, 'errored' => $errored];
    }

    private function queueExists(int $historyId): bool
    {
        return (new ReconcileTask())->getFromDBByCrit(['workflow_history_id' => $historyId]);
    }

    private function backoff(int $attempts): string
    {
        $delay = min($attempts * 60, 3600); // crece hasta 1h
        return gmdate('Y-m-d H:i:s', time() + $delay);
    }
}
