<?php

/**
 * Marcas DURABLES de integridad de aprobación (P2D-2) — tabla propia `..._integrity`.
 *
 * Contrato:
 *   - `markDirty()` se invoca DENTRO de la transacción local de la mutación sustantiva (mismo COMMIT): o
 *     persisten el cambio Y la marca, o ninguno. Registra solicitud, scope, causa, actor, estado del motor,
 *     lock_version e identidad (`idempotency_key` UNIQUE). También pone `requests.integrity_state=dirty`.
 *   - Una marca sólo se RESUELVE (`resolve()`) tras una `invalidateApprovals()` CONFIRMADA por el motor, o
 *     tras verificar contra el ledger del motor que no hay aprobación viva distinta del contenido actual.
 *     Nunca por una reparación fallida.
 *   - `resolve()` acota por `upToId` (las marcas vistas al iniciar la reparación): una marca escrita
 *     después no se resuelve por error.
 *   - Prioridad: `ApprovalPolicy::orderedCheckpoints()` (REQUEST_SCOPE antes que COMMERCIAL).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\IntegrityMark;
use GlpiPlugin\Companypurchasing\Model\Request;

class IntegrityLedger
{
    public const CLEAN = 'clean';
    public const DIRTY = 'dirty';

    /**
     * Marca como sucios los scopes indicados. DEBE llamarse dentro de la transacción de la mutación.
     *
     * @param array<int,string> $scopes
     * @throws \RuntimeException  si no persiste (el llamador hace ROLLBACK de la mutación)
     */
    public function markDirty(Request $req, array $scopes, string $cause, string $workflowState, int $workflowLock): void
    {
        $reqId = (int) $req->getID();
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        foreach (array_values(array_unique($scopes)) as $scope) {
            $id = (int) (new IntegrityMark())->add([
                'requests_id'           => $reqId,
                'scope_key'             => $scope,
                'status'                => IntegrityMark::STATUS_DIRTY,
                'cause'                 => substr($cause, 0, 60),
                'actor_users_id'        => (int) (Session::getLoginUserID() ?: 0),
                'workflow_state'        => substr($workflowState, 0, 60),
                'workflow_lock_version' => $workflowLock,
                'idempotency_key'       => 'dirty:' . $reqId . ':' . $scope . ':' . bin2hex(random_bytes(8)),
                'resolution'            => '',
                'date_creation'         => $now,
            ]);
            if ($id <= 0) {
                throw new \RuntimeException('no se pudo registrar la marca de integridad (fail-closed)');
            }
        }
        if ((string) ($req->fields['integrity_state'] ?? self::CLEAN) !== self::DIRTY
            && !$req->update(['id' => $reqId, 'integrity_state' => self::DIRTY])) {
            throw new \RuntimeException('no se pudo marcar la solicitud como pendiente de integridad (fail-closed)');
        }
    }

    /**
     * Marcas pendientes agrupadas por scope: scope → id máximo visto.
     *
     * @return array<string,int>
     */
    public function pending(int $requestId): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'scope_key'],
            'FROM'   => IntegrityMark::getTable(),
            'WHERE'  => ['requests_id' => $requestId, 'status' => IntegrityMark::STATUS_DIRTY],
            'ORDER'  => 'id ASC',
        ]) as $row) {
            $out[(string) $row['scope_key']] = max((int) ($out[(string) $row['scope_key']] ?? 0), (int) $row['id']);
        }
        return $out;
    }

    /**
     * Resuelve las marcas `dirty` de los scopes dados con id ≤ `$upToId` (las vistas al iniciar la
     * reparación). Si no queda ninguna marca pendiente, la solicitud vuelve a `integrity_state=clean`.
     * Transacción local propia; comprueba los writes.
     *
     * @param array<int,string> $scopes
     */
    public function resolve(int $requestId, array $scopes, int $upToId, string $resolution): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        if ($scopes === [] || $upToId <= 0) {
            return;
        }
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $DB->beginTransaction();
        try {
            $ok = $DB->update(IntegrityMark::getTable(), [
                'status'        => IntegrityMark::STATUS_RESOLVED,
                'resolution'    => substr($resolution, 0, 190),
                'resolved_by'   => (int) (Session::getLoginUserID() ?: 0),
                'date_resolved' => $now,
            ], [
                'requests_id' => $requestId,
                'scope_key'   => array_values($scopes),
                'status'      => IntegrityMark::STATUS_DIRTY,
                ['id' => ['<=', $upToId]],
            ]);
            if ($ok === false) {
                throw new \RuntimeException('no se pudo resolver la marca de integridad');
            }
            if ($this->pending($requestId) === []) {
                $req = new Request();
                if ($req->getFromDB($requestId) && (string) $req->fields['integrity_state'] !== self::CLEAN
                    && !$req->update(['id' => $requestId, 'integrity_state' => self::CLEAN])) {
                    throw new \RuntimeException('no se pudo limpiar el estado de integridad de la solicitud');
                }
            }
            $DB->commit();
        } catch (\Throwable $e) {
            try {
                if (!method_exists($DB, 'inTransaction') || $DB->inTransaction()) {
                    $DB->rollBack();
                }
            } catch (\Throwable) {
                // best-effort
            }
            throw $e;
        }
    }
}
