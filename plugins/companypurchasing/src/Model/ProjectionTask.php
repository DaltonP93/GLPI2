<?php

/**
 * Acción automática NATIVA (CronTask) de companypurchasing: reconciliación de la proyección
 * `requests.domain_state` con la instancia de `companyworkflow` (AUTORIDAD), por lotes con cursor y
 * wrap-around (`ApprovalOrchestrator::reconcileAll`), y detección de solicitudes enviadas sin instancia.
 *
 * P2D-3: converge además la saga de RECEPCIÓN (el motor refleja los contadores físicos ya confirmados; corre con
 * el contexto de sistema de la CronTask) y reporta anomalías e instancias con una versión anterior de la definición.
 *
 * P2D-4: la saga física converge también la ENTREGA total (RECEIVED → DELIVERED) y, como red de seguridad de las
 * notificaciones NATIVAS, recorre el ledger del motor desde un cursor (`NotificationDispatcher::sweep`): un hecho cuyo
 * listener se perdió igual se notifica (como mucho una vez por hecho).
 *
 * NUNCA aprueba, rechaza ni invalida: sólo proyecta estado confirmado y reporta anomalías.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Model;

use CommonGLPI;
use CronTask;
use GlpiPlugin\Companypurchasing\Service\ApprovalOrchestrator;
use GlpiPlugin\Companypurchasing\Service\NotificationDispatcher;

class ProjectionTask extends CommonGLPI
{
    public const CRON_NAME = 'reconcileprojection';
    public const BATCH = 200;

    public static function getTypeName($nb = 0)
    {
        return __('Purchasing state reconciliation', 'companypurchasing');
    }

    /** @return array<string,string>|false */
    public static function cronInfo($name)
    {
        if ($name === self::CRON_NAME) {
            return ['description' => __('Reconcile purchase request states with the workflow engine', 'companypurchasing')];
        }
        return false;
    }

    /**
     * @return int  <0 error, 0 nada que hacer, >0 acciones realizadas
     */
    public static function cronReconcileprojection(CronTask $task)
    {
        $r = (new ApprovalOrchestrator())->reconcileAll(self::BATCH);
        $n = ['scanned' => 0, 'raised' => 0, 'cursor' => 0];
        try {
            $n = (new NotificationDispatcher())->sweep(self::BATCH);
        } catch (\Throwable) {
            // best-effort: una notificación jamás compromete la reconciliación ni los hechos de negocio.
        }
        $task->log(sprintf('notificaciones: revisadas=%d disparadas=%d cursor=%d', $n['scanned'], $n['raised'], $n['cursor']));
        $task->log(sprintf(
            'revisadas=%d corregidas=%d sin_instancia=%d integridad_pendiente=%d recepcion_pendiente=%d anomalias_recepcion=%d definicion_anterior=%d(bloqueadas=%d) errores=%d cursor=%d',
            $r['checked'],
            $r['corrected'],
            count($r['orphans']),
            count($r['dirty']),
            count($r['receiving_pending']),
            count($r['receiving_anomalies']),
            count($r['legacy']),
            count($r['legacy_blocked']),
            $r['errors'],
            $r['cursor']
        ));
        if ($r['corrected'] > 0) {
            $task->addVolume($r['corrected']);
        }
        if ($r['errors'] > 0 || $r['orphans'] !== [] || $r['dirty'] !== [] || $r['receiving_pending'] !== []
            || $r['receiving_anomalies'] !== [] || $r['legacy_blocked'] !== []) {
            return -1; // visible en la Acción automática (anomalía a revisar)
        }
        return $r['corrected'] > 0 ? 1 : 0;
    }
}
