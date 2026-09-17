<?php

/**
 * Cola DURABLE de reconciliación (tabla `glpi_plugin_companysignature_reconcile_queue`) + CronTask.
 *
 * Estado propio por evento del ledger (§3): `UNIQUE(workflow_history_id)` ⇒ idempotente;
 * `status` (pending/done/error), `attempts`, `last_error` (saneado), `next_retry_at`. El *harvest*
 * encola y avanza el high-watermark; el *worker* procesa los pendientes independientemente, de modo
 * que un evento sin snapshot/evidence_ref no bloquea a los posteriores ni se pierde al avanzar el cursor.
 *
 * La CronTask nativa `reconcile` ejecuta harvest+worker. NUNCA aprueba/rechaza: sólo materializa
 * evidencia YA comprometida en el ledger.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Model;

use CommonDBTM;
use CronTask;
use GlpiPlugin\Companysignature\Service\ReconcileService;

class ReconcileTask extends CommonDBTM
{
    public static $rightname = 'plugin_companysignature';

    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE    = 'done';
    public const STATUS_ERROR   = 'error';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companysignature_reconcile_queue';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Evidence reconcile task', 'Evidence reconcile tasks', $nb, 'companysignature');
    }

    /**
     * Descripción de la tarea cron (API nativa de GLPI).
     * @param string $name
     * @return array{description:string}|false
     */
    public static function cronInfo($name)
    {
        if ($name === 'reconcile') {
            return ['description' => __('Durably reconcile approval evidence from the workflow ledger', 'companysignature')];
        }
        return false;
    }

    /**
     * Tarea cron: harvest (encola nuevos eventos) + worker (materializa pendientes). Nunca aprueba solo.
     * @param CronTask $task
     * @return int  <0 error, 0 nada que hacer, >0 acciones realizadas
     */
    public static function cronReconcile(CronTask $task)
    {
        $res = (new ReconcileService())->run();
        $done = (int) ($res['materialized'] ?? 0) + (int) ($res['enqueued'] ?? 0);
        if ($done > 0) {
            $task->addVolume($done);
        }
        return $done > 0 ? 1 : 0;
    }
}
