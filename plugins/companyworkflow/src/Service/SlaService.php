<?php

/**
 * SLA por etapa + escalamiento. Detecta instancias cuyo estado actual superó `sla_hours` y
 * registra evento de vencimiento + escalamiento (notifica). NUNCA aprueba por su cuenta.
 *
 * Invocado por la tarea cron `Instance::cronEscalation` y ejecutable on-demand (selftest).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

use GlpiPlugin\Companyworkflow\Model\HistoryEvent;
use GlpiPlugin\Companyworkflow\Model\Instance;
use GlpiPlugin\Companyworkflow\Model\StateDef;

final class SlaService
{
    private AuditBridge $audit;

    public function __construct(?AuditBridge $audit = null)
    {
        $this->audit = $audit ?? new AuditBridge();
    }

    /**
     * ¿La etapa venció? Lógica PURA (unit-testable): dado el inicio de etapa, las horas de SLA
     * y el "ahora", indica si se superó el plazo. sla_hours<=0 o nulo → nunca vence.
     */
    public function isOverdue(?int $slaHours, string $stageEnteredAt, ?string $now = null): bool
    {
        if ($slaHours === null || $slaHours <= 0) {
            return false;
        }
        $start = strtotime($stageEnteredAt);
        if ($start === false) {
            return false;
        }
        $nowTs = $now !== null ? strtotime($now) : time();
        if ($nowTs === false) {
            $nowTs = time();
        }
        return $nowTs > ($start + $slaHours * 3600);
    }

    /**
     * Recorre instancias abiertas y escala las vencidas. Devuelve cuántas escaló.
     * Idempotente por ejecución: sólo escala si no hay ya un evento de escalamiento posterior
     * a la última entrada de etapa.
     */
    public function processOverdue(): int
    {
        /** @var \DBmysql $DB */
        global $DB;
        if (!isset($DB) || !PluginConfig::boolean('sla_check_enabled')) {
            return 0;
        }

        // Instancias abiertas (usa el builder soportado; sin SQL crudo ni subconsultas).
        $open = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'current_statedefs_id'],
            'FROM'   => 'glpi_plugin_companyworkflow_instances',
            'WHERE'  => ['status' => Instance::STATUS_OPEN],
        ]) as $row) {
            $open[] = ['id' => (int) $row['id'], 'state' => (int) $row['current_statedefs_id']];
        }

        $escalated = 0;
        foreach ($open as $inst) {
            $state = new StateDef();
            if (!$state->getFromDB($inst['state'])) {
                continue;
            }
            $slaHours = $state->fields['sla_hours'] ?? null;
            if ($slaHours === null || (int) $slaHours <= 0) {
                continue;
            }
            $stateCode = (string) ($state->fields['code'] ?? '');

            $enteredAt      = $this->latestHistoryDate($inst['id'], ['to_code' => $stateCode]);
            $lastEscalation = $this->latestHistoryDate($inst['id'], ['event' => HistoryEvent::EVENT_ESCALATED]);

            if ($enteredAt === '' || !$this->isOverdue((int) $slaHours, $enteredAt)) {
                continue;
            }
            // Evita re-escalar sin cambio de etapa.
            if ($lastEscalation !== '' && $lastEscalation >= $enteredAt) {
                continue;
            }

            $this->audit->record($inst['id'], HistoryEvent::EVENT_SLA_BREACHED, $stateCode, $stateCode,
                'SLA vencido (' . (int) $slaHours . 'h)', true, ['sla_hours' => (int) $slaHours]);
            $this->audit->record($inst['id'], HistoryEvent::EVENT_ESCALATED, $stateCode, $stateCode,
                'Escalamiento automático (sin aprobar)', true);
            $escalated++;
        }
        return $escalated;
    }

    /**
     * Fecha más reciente del historial de una instancia que cumpla el criterio (o '' si ninguna).
     * @param array<string,mixed> $extraWhere
     */
    private function latestHistoryDate(int $instanceId, array $extraWhere): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $where = array_merge(['instances_id' => $instanceId], $extraWhere);
        foreach ($DB->request([
            'SELECT' => 'date',
            'FROM'   => 'glpi_plugin_companyworkflow_history',
            'WHERE'  => $where,
            'ORDER'  => 'date DESC',
            'LIMIT'  => 1,
        ]) as $row) {
            return (string) ($row['date'] ?? '');
        }
        return '';
    }
}
