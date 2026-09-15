<?php

/**
 * Resuelve el conjunto de APROBADORES (ids de usuario) de una etapa, por rol/grupo/perfil
 * (NUNCA por nombre de persona) e incorporando delegaciones vigentes.
 *
 * Soportado en v1: group, user, profile. `entity_manager` queda como extensión (devuelve el
 * conjunto vacío y se registra como deuda técnica; ver README). La ventana de delegación se
 * calcula con la lógica pura DelegationResolver.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

use GlpiPlugin\Companyworkflow\Model\Step;

final class ApproverResolver
{
    private DelegationResolver $delegations;

    public function __construct(?DelegationResolver $delegations = null)
    {
        $this->delegations = $delegations ?? new DelegationResolver();
    }

    /**
     * @param array<string,mixed> $step        fila de ..._steps
     * @return array<int,int> ids de usuarios aprobadores (base + delegados vigentes)
     */
    public function resolveForStep(array $step, int $workflowdefsId, int $entitiesId): array
    {
        $kind = (string) ($step['approver_kind'] ?? '');
        $ref  = (int) ($step['approver_ref'] ?? 0);

        $base = $this->resolveBase($kind, $ref, $entitiesId);
        $withDelegates = $this->expandDelegations($base, $workflowdefsId, $entitiesId);

        $max = (int) PluginConfig::get('max_resolved_approvers', '500');
        if ($max > 0 && count($withDelegates) > $max) {
            $withDelegates = array_slice($withDelegates, 0, $max);
        }
        return $withDelegates;
    }

    /** @return array<int,int> */
    private function resolveBase(string $kind, int $ref, int $entitiesId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $ids = [];

        switch ($kind) {
            case Step::APPROVER_USER:
                if ($ref > 0) {
                    $ids[$ref] = true;
                }
                break;

            case Step::APPROVER_GROUP:
                if ($ref > 0 && isset($DB)) {
                    foreach ($DB->request([
                        'SELECT' => 'users_id',
                        'FROM'   => 'glpi_groups_users',
                        'WHERE'  => ['groups_id' => $ref],
                    ]) as $row) {
                        $ids[(int) $row['users_id']] = true;
                    }
                }
                break;

            case Step::APPROVER_PROFILE:
                if ($ref > 0 && isset($DB)) {
                    $where = ['profiles_id' => $ref];
                    if ($entitiesId > 0) {
                        $where['entities_id'] = $entitiesId;
                    }
                    foreach ($DB->request([
                        'SELECT' => 'users_id',
                        'FROM'   => 'glpi_profiles_users',
                        'WHERE'  => $where,
                    ]) as $row) {
                        $ids[(int) $row['users_id']] = true;
                    }
                }
                break;

            case Step::APPROVER_ENTITY_MANAGER:
            default:
                // v1: no resuelto (deuda técnica documentada). Fail-closed → sin aprobadores.
                break;
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Añade delegados vigentes de cada aprobador base.
     * @param array<int,int> $base
     * @return array<int,int>
     */
    private function expandDelegations(array $base, int $workflowdefsId, int $entitiesId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $all = [];
        foreach ($base as $u) {
            $all[$u] = true;
        }
        if (!isset($DB) || $base === []) {
            return array_map('intval', array_keys($all));
        }

        $rows = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_companyworkflow_delegations',
            'WHERE' => ['is_active' => 1, 'users_id_from' => $base],
        ]) as $row) {
            $rows[] = $row;
        }
        foreach ($base as $u) {
            foreach ($this->delegations->effectiveDelegates($rows, $u, $workflowdefsId, $entitiesId) as $d) {
                $all[$d] = true;
            }
        }
        return array_map('intval', array_keys($all));
    }
}
