<?php

/**
 * Lectura de LISTADOS para la UI (P2D-4; ADR-0023 §8). Toda consulta vuelve a aplicar del lado SERVIDOR los
 * derechos + la entidad: los parámetros del navegador sólo FILTRAN dentro de lo permitido, nunca amplían.
 *
 *   - "mis solicitudes" (`RIGHT_VIEW_OWN`): sólo las propias (solicitante = usuario autenticado) en entidades activas.
 *   - alcance "entidad" (`RIGHT_VIEW_ENTITY`): todas las de las entidades activas.
 *
 * Búsqueda / historial GENERAL: los derechos operativos (MANAGE / RECEIVE / DELIVER) y la condición de aprobador NO
 * amplían este listado; dan sólo lectura CONTEXTUAL de la solicitud accionable (`RequestUiAccess`).
 *
 * Las filas son de PRESENTACIÓN (etiquetas i18n, importes exactos); jamás exponen hashes, tokens ni claves internas.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\Request;

final class RequestQuery
{
    public const SCOPE_MINE   = 'mine';
    public const SCOPE_ENTITY = 'entity';
    public const MAX_ROWS     = 500;

    /**
     * @param array{state?:mixed, from?:mixed, to?:mixed, category?:mixed, entities_id?:mixed, number?:mixed} $filters
     * @return array<int,array<string,mixed>>
     * @throws \RuntimeException sin derecho de vista (fail-closed)
     */
    public function search(array $filters, string $scope = self::SCOPE_MINE, int $limit = 200): array
    {
        $canOwn    = Session::haveRight(Request::$rightname, Request::RIGHT_VIEW_OWN);
        $canEntity = Session::haveRight(Request::$rightname, Request::RIGHT_VIEW_ENTITY);
        if (!$canOwn && !$canEntity) {
            throw new \RuntimeException('permiso denegado (VIEW_OWN / VIEW_ENTITY)');
        }
        $scope = ($scope === self::SCOPE_ENTITY && $canEntity) ? self::SCOPE_ENTITY : self::SCOPE_MINE;
        $entities = MetricsService::scopeEntities($filters['entities_id'] ?? null);
        if ($entities === []) {
            return [];
        }
        $where = ['entities_id' => $entities];
        if ($scope === self::SCOPE_MINE) {
            $where['users_id_requester'] = (int) (Session::getLoginUserID() ?: 0);
        }
        foreach (self::criteria($filters) as $c) {
            $where[] = $c;
        }
        /** @var \DBmysql $DB */
        global $DB;
        $rows = [];
        foreach ($DB->request(['FROM' => Request::getTable(), 'WHERE' => $where, 'ORDER' => ['date_mod DESC', 'id DESC'],
                               'LIMIT' => max(1, min(self::MAX_ROWS, $limit))]) as $r) {
            $rows[] = self::present($r);
        }
        return $rows;
    }

    /**
     * Criterios de FILTRO validados (formato estricto; lo inválido se ignora, nunca se interpola).
     *
     * @param array<string,mixed> $f
     * @return array<int,array<string,mixed>>
     */
    public static function criteria(array $f): array
    {
        $out = [];
        $state = is_string($f['state'] ?? null) ? trim($f['state']) : '';
        if ($state !== '' && preg_match('/^[A-Z_]{2,30}$/', $state) === 1) {
            $out[] = ['domain_state' => $state];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            $d = is_string($f[$k] ?? null) ? trim($f[$k]) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4))) {
                $out[] = ['date_creation' => [$op, $d . ($k === 'from' ? ' 00:00:00' : ' 23:59:59')]];
            }
        }
        $cat = is_string($f['category'] ?? null) ? trim($f['category']) : '';
        if ($cat !== '' && mb_strlen($cat) <= 190) {
            $out[] = ['category' => $cat];
        }
        $num = is_string($f['number'] ?? null) ? trim($f['number']) : '';
        if ($num !== '' && preg_match('/^[A-Za-z0-9\-\/]{1,60}$/', $num) === 1) {
            $out[] = ['number' => ['LIKE', '%' . $num . '%']];
        }
        return $out;
    }

    /** @param array<string,mixed> $r fila de `requests` @return array<string,mixed> */
    public static function present(array $r): array
    {
        $state = (string) ($r['domain_state'] ?? '');
        $cur = (string) ($r['currency_code'] ?? 'PYG');
        $amount = '';
        try {
            $amount = Money::ofStored((string) ($r['amount_estimated'] ?? '0'), $cur, PluginConfig::currencyScaleOverrides())->amount();
        } catch (\Throwable) {
            $amount = '';
        }
        $dept = (int) ($r['groups_id_department'] ?? 0);
        return [
            'id'            => (int) $r['id'],
            'number'        => (string) ($r['number'] ?? ''),
            'date_creation' => (string) ($r['date_creation'] ?? ''),
            'date_mod'      => (string) ($r['date_mod'] ?? ''),
            'category'      => (string) ($r['category'] ?? ''),
            'department'    => $dept > 0 ? \Dropdown::getDropdownName('glpi_groups', $dept) : '',
            'entity'        => \Dropdown::getDropdownName('glpi_entities', (int) ($r['entities_id'] ?? 0)),
            'amount'        => $amount,
            'amount_display' => $amount !== '' ? MetricsMath::display($amount, (string) ($_SESSION['glpilanguage'] ?? 'es_ES')) : '',
            'currency'      => $cur,
            'state'         => $state,
            'state_label'   => Labels::state($state),
            'phase_label'   => Labels::phase(PurchasingWorkflow::phaseOf($state)),
            'is_mine'       => (int) ($r['users_id_requester'] ?? 0) === (int) (Session::getLoginUserID() ?: 0),
        ];
    }
}
