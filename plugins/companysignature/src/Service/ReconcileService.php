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

        // FAIL-CLOSED (§1): el watermark sólo avanza sobre eventos DURABLEMENTE encolados y de forma
        // CONTIGUA (`history()` viene ordenado por id ASC). Si un evento no queda durable (`add()` falla
        // y tampoco lo insertó otro worker), se DETIENE el escaneo y el watermark queda en el último id
        // durable: la próxima corrida re-lee desde ahí y reintenta el hueco. Jamás "add FAIL → watermark
        // pasa por encima del hueco".
        $now = gmdate('Y-m-d H:i:s');
        $enqueued = 0;
        $results = []; // [{hid:int, durable:bool}] en orden id ASC
        foreach ($rows as $row) {
            $hid = (int) ($row['id'] ?? 0);
            if ($hid <= 0) {
                continue;
            }
            $durable = false;
            if ($this->queueExists($hid)) {
                $durable = true; // ya encolado (corrida previa u otro worker)
            } else {
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
                    $durable = true;
                } elseif ($this->queueExists($hid)) {
                    $durable = true; // carrera concurrente: otro worker lo insertó
                }
            }
            $results[] = ['hid' => $hid, 'durable' => $durable];
            if (!$durable) {
                break; // fail-closed: no escanear/avanzar más allá de un evento no durable
            }
        }
        $safe = self::safeWatermark($watermark, $results);
        if ($safe > $watermark) {
            // El watermark avanza SÓLO hasta el último id durable CONTIGUO.
            Config::setConfigurationValues(PluginConfig::CONTEXT, ['last_seen_history_id' => (string) $safe]);
        }
        return $enqueued;
    }

    /**
     * Calcula, de forma PURA (sin BD), hasta dónde puede avanzar el watermark: el mayor `hid` tal que
     * TODOS los eventos hasta él (en orden) quedaron durables. Al primer no-durable se detiene
     * (fail-closed §1). Público/estático para prueba unitaria determinista.
     *
     * @param array<int,array{hid:int, durable:bool}> $results  en orden id ASC
     */
    public static function safeWatermark(int $current, array $results): int
    {
        $safe = $current;
        foreach ($results as $r) {
            $hid = (int) ($r['hid'] ?? 0);
            if ($hid <= 0) {
                continue;
            }
            if (empty($r['durable'])) {
                break; // hueco: no avanzar más allá
            }
            if ($hid > $safe) {
                $safe = $hid; // MONOTÓNICO: sólo avanza, nunca retrocede
            }
        }
        return $safe;
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

        // ANTI-STARVATION (§2): la elegibilidad se filtra EN SQL, para que N tareas en backoff NO ocupen
        // el batch y hambreen a una tarea elegible posterior. Elegible =
        //   ( status = pending  OR  (status = error AND attempts < MAX) )   -- no reintentar errores agotados
        //   AND ( next_retry_at IS NULL OR next_retry_at <= now )           -- respeta el backoff vigente
        // Orden causal por `workflow_history_id`; un pendiente en backoff no bloquea a los posteriores.
        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => ReconcileTask::getTable(),
            'WHERE'  => [
                // (pending)  OR  (error con intentos por debajo del tope)
                ['OR' => [
                    ['status' => ReconcileTask::STATUS_PENDING],
                    ['status' => ReconcileTask::STATUS_ERROR, 'attempts' => ['<', self::MAX_ATTEMPTS]],
                ]],
                // backoff vencido (o sin backoff)
                ['OR' => [
                    ['next_retry_at' => null],
                    ['next_retry_at' => ['<=', $now]],
                ]],
            ],
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
                continue; // desapareció entre el SELECT y el proceso (otro worker): sin problema
            }
            $processed++;
            $attempts = (int) $task->fields['attempts'] + 1;
            $outcome = $this->materializer->materializeByHistoryId((int) $task->fields['workflow_history_id']);
            $materialized += (int) ($outcome['created'] ?? 0);
            $status = (string) ($outcome['status'] ?? Materializer::R_DONE);
            $reason = substr((string) ($outcome['reason'] ?? ''), 0, 190); // saneado, sin secretos

            if ($status === Materializer::R_DONE) {
                $task->update(['id' => $id, 'status' => ReconcileTask::STATUS_DONE, 'attempts' => $attempts, 'last_error' => null, 'date_mod' => $now]);
            } elseif ($status === Materializer::R_ERROR) {
                $errored++;
                $task->update(['id' => $id, 'status' => ReconcileTask::STATUS_ERROR, 'attempts' => $attempts, 'last_error' => ($reason !== '' ? $reason : 'inconsistencia'), 'next_retry_at' => $this->backoff($attempts), 'date_mod' => $now]);
            } else { // pending (dependencia/snapshot no listos): reintentar con backoff, sin marcar error
                $pending++;
                $task->update(['id' => $id, 'status' => ReconcileTask::STATUS_PENDING, 'attempts' => $attempts, 'last_error' => ($reason !== '' ? $reason : null), 'next_retry_at' => $this->backoff($attempts), 'date_mod' => $now]);
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
