<?php

/**
 * Acción automática NATIVA (CronTask) de companypurchasing: reconciliación de la proyección
 * `requests.domain_state` con la instancia de `companyworkflow` (AUTORIDAD), por lotes con cursor y
 * wrap-around (`ApprovalOrchestrator::reconcileAll`), y detección de solicitudes enviadas sin instancia.
 *
 * P2D-3: converge además la saga de RECEPCIÓN (el motor refleja los contadores físicos ya confirmados; corre con
 * el contexto de sistema de la CronTask) y reporta anomalías e instancias con una versión anterior de la definición.
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
