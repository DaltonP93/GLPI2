<?php

/**
 * Construye una DEFINICIÓN de workflow VERSIONADA a partir de una especificación declarativa.
 *
 * Lo usa el plugin de dominio (p. ej. companypurchasing aporta la definición de Compras) y los
 * tests. `companyworkflow` NO hardcodea ningún dominio: aquí no hay estados de compras.
 *
 * Versionado: cada llamada a createVersion() crea una fila nueva (code, version+1) y desactiva
 * las versiones previas del mismo `code`. Las instancias en ejecución conservan su versión.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

use GlpiPlugin\Companyworkflow\Model\StateDef;
use GlpiPlugin\Companyworkflow\Model\Step;
use GlpiPlugin\Companyworkflow\Model\Transition;
use GlpiPlugin\Companyworkflow\Model\WorkflowDef;

final class DefinitionBuilder
{
    /**
     * @param array<string,mixed> $spec
     * @return WorkflowDef  la definición recién creada (activa)
     */
    public function createVersion(array $spec): WorkflowDef
    {
        /** @var \DBmysql $DB */
        global $DB;

        $code = (string) ($spec['code'] ?? '');
        if ($code === '') {
            throw new \InvalidArgumentException('spec.code requerido');
        }
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');

        // Próxima versión para este code (leemos la mayor existente; robusto entre versiones GLPI).
        $version = 1;
        foreach ($DB->request([
            'SELECT' => 'version',
            'FROM'   => WorkflowDef::getTable(),
            'WHERE'  => ['code' => $code],
            'ORDER'  => 'version DESC',
            'LIMIT'  => 1,
        ]) as $row) {
            $version = ((int) ($row['version'] ?? 0)) + 1;
        }

        // Desactivar versiones previas.
        $DB->update(WorkflowDef::getTable(), ['is_active' => 0], ['code' => $code]);

        $def = new WorkflowDef();
        $defId = (int) $def->add([
            'code'            => $code,
            'name'            => (string) ($spec['name'] ?? $code),
            'itemtype_target' => (string) ($spec['itemtype_target'] ?? ''),
            'version'         => $version,
            'is_active'       => 1,
            'entities_id'     => (int) ($spec['entities_id'] ?? 0),
            'is_recursive'    => (int) ($spec['is_recursive'] ?? 0),
            'date_creation'   => $now,
            'date_mod'        => $now,
        ]);
        $def->getFromDB($defId);

        // Estados: code → id.
        $stateIds = [];
        foreach (($spec['states'] ?? []) as $st) {
            $sd = new StateDef();
            $stateIds[(string) $st['code']] = (int) $sd->add([
                'workflowdefs_id' => $defId,
                'code'            => (string) $st['code'],
                'label'           => (string) ($st['label'] ?? $st['code']),
                'kind'            => (string) ($st['kind'] ?? StateDef::KIND_INTERMEDIATE),
                'is_editable'     => (int) ($st['is_editable'] ?? 0),
                'sla_hours'       => array_key_exists('sla_hours', $st) ? $st['sla_hours'] : null,
                'date_creation'   => $now,
                'date_mod'        => $now,
            ]);
        }

        // Transiciones (+ pasos/quórum).
        foreach (($spec['transitions'] ?? []) as $tr) {
            $fromId = (int) ($stateIds[(string) ($tr['from'] ?? '')] ?? 0);
            $toId   = (int) ($stateIds[(string) ($tr['to'] ?? '')] ?? 0);
            $cond   = $tr['condition'] ?? null;
            $condJson = is_array($cond) ? json_encode($cond, JSON_UNESCAPED_UNICODE) : ($cond !== null ? (string) $cond : null);

            $t = new Transition();
            $tId = (int) $t->add([
                'workflowdefs_id'   => $defId,
                'from_statedefs_id' => $fromId,
                'to_statedefs_id'   => $toId,
                'action'            => (string) ($tr['action'] ?? ''),
                'requires_comment'  => (int) ($tr['requires_comment'] ?? 0),
                'is_auto'           => (int) ($tr['is_auto'] ?? 0),
                'condition_json'    => $condJson,
                'required_right'    => (int) ($tr['required_right'] ?? 0),
                'date_creation'     => $now,
                'date_mod'          => $now,
            ]);

            foreach (($tr['steps'] ?? []) as $stp) {
                (new Step())->add([
                    'transitions_id' => $tId,
                    'level'          => (int) ($stp['level'] ?? 1),
                    'quorum_type'    => (string) ($stp['quorum_type'] ?? Step::QUORUM_COUNT),
                    'quorum_value'   => (int) ($stp['quorum_value'] ?? 1),
                    'approver_kind'  => (string) ($stp['approver_kind'] ?? Step::APPROVER_GROUP),
                    'approver_ref'   => (int) ($stp['approver_ref'] ?? 0),
                    'date_creation'  => $now,
                    'date_mod'       => $now,
                ]);
            }
        }

        return $def;
    }

    /** Estado inicial de una definición. */
    public function initialState(int $defId): ?StateDef
    {
        $sd = new StateDef();
        if ($sd->getFromDBByCrit(['workflowdefs_id' => $defId, 'kind' => StateDef::KIND_INITIAL])) {
            return $sd;
        }
        return null;
    }

    /** Definición ACTIVA por code (la versión vigente para nuevas instancias). */
    public function activeByCode(string $code): ?WorkflowDef
    {
        $def = new WorkflowDef();
        if ($def->getFromDBByCrit(['code' => $code, 'is_active' => 1])) {
            return $def;
        }
        return null;
    }
}
