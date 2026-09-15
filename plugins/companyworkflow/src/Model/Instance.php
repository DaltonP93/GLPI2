<?php

/**
 * Instancia viva de un workflow sobre un objeto de dominio (glpi_plugin_companyworkflow_instances).
 *
 * Acoplamiento por referencia (`itemtype`/`items_id`): el motor NO conoce el dominio.
 * `lock_version` implementa control de concurrencia OPTIMISTA (evita doble aprobación por
 * reintento/carrera). `workflowdefs_id`+`def_version` preservan la versión con la que se inició.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Model;

use CommonDBTM;
use CronTask;
use GlpiPlugin\Companyworkflow\Service\SlaService;

class Instance extends CommonDBTM
{
    public static $rightname = 'plugin_companyworkflow';

    public const STATUS_OPEN      = 'open';
    public const STATUS_CLOSED    = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyworkflow_instances';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow instance', 'Workflow instances', $nb, 'companyworkflow');
    }

    public function isOpen(): bool
    {
        return ($this->fields['status'] ?? '') === self::STATUS_OPEN;
    }

    /**
     * Descripción de la tarea cron (API nativa de GLPI).
     * @param string $name
     * @return array{description:string}|false
     */
    public static function cronInfo($name)
    {
        if ($name === 'escalation') {
            return ['description' => __('Detect overdue workflow stages and escalate', 'companyworkflow')];
        }
        return false;
    }

    /**
     * Tarea cron: detección de SLA vencido + escalamiento (NUNCA aprueba sola).
     * @param CronTask $task
     * @return int  <0 error, 0 nada que hacer, >0 acciones realizadas
     */
    public static function cronEscalation(CronTask $task)
    {
        $done = (new SlaService())->processOverdue();
        if ($done > 0) {
            $task->addVolume($done);
        }
        return $done > 0 ? 1 : 0;
    }
}
