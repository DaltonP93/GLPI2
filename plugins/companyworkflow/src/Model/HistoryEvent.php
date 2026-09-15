<?php

/**
 * Evento de auditoría append-only (glpi_plugin_companyworkflow_history).
 *
 * Una fila por evento. NUNCA se borra (append-only). La invalidación de aprobaciones no borra:
 * agrega eventos `approval_invalidated` y reabre etapas.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Model;

use CommonDBTM;

class HistoryEvent extends CommonDBTM
{
    public static $rightname = 'plugin_companyworkflow';

    public const EVENT_STARTED             = 'started';
    public const EVENT_TRANSITIONED        = 'transitioned';
    public const EVENT_BALLOT_RECORDED     = 'ballot_recorded';
    public const EVENT_QUORUM_REACHED      = 'quorum_reached';
    public const EVENT_RETURNED            = 'returned';
    public const EVENT_REJECTED            = 'rejected';
    public const EVENT_CANCELLED           = 'cancelled';
    public const EVENT_APPROVAL_INVALIDATED = 'approval_invalidated';
    public const EVENT_SLA_BREACHED        = 'sla_breached';
    public const EVENT_ESCALATED           = 'escalated';
    public const EVENT_DELEGATED           = 'delegated';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_companyworkflow_history';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Workflow history event', 'Workflow history events', $nb, 'companyworkflow');
    }
}
