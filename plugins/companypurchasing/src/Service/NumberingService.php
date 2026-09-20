<?php

/**
 * Asignación de número visible, TRANSACCIONAL y concurrency-safe (gate §Numeración).
 *
 * Clave de secuencia `UNIQUE(entities_id, scope, year)`. La reserva:
 *   1) garantiza (idempotente) que exista la fila de secuencia (`INSERT IGNORE`, `next_number=1`);
 *   2) en una transacción, INCREMENTA `next_number` (bloqueo de fila InnoDB) y LEE el nuevo valor;
 *   3) devuelve `nuevo_next_number - 1` como número asignado.
 *
 * Dos procesos concurrentes se serializan por el bloqueo de fila del UPDATE ⇒ números DISTINTOS. No se
 * reutilizan números: si una operación que reservó un número falla/cancela después, queda un HUECO
 * (aceptado; nunca se recicla). Cada (entidad, año) tiene su propia secuencia independiente.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use GlpiPlugin\Companypurchasing\Model\NumberSequence;

final class NumberingService
{
    /** Scope de numeración de solicitudes (código, no dato de usuario). */
    public const SCOPE_REQUEST = 'request';

    private const MAX_RETRIES = 5;

    /**
     * Formato ESTABLE y determinista del número visible (helper puro, unit-testable).
     * Ej.: ('request', 2026, 7) → "REQUEST-2026-000007".
     */
    public static function formatNumber(string $scope, int $year, int $seq): string
    {
        return sprintf('%s-%d-%06d', strtoupper(trim($scope)), $year, $seq);
    }

    /**
     * Reserva y devuelve el siguiente número para (entidad, scope, año). Concurrency-safe.
     *
     * @throws \RuntimeException si no se pudo asignar tras los reintentos.
     */
    public function assign(int $entitiesId, string $scope, int $year): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $entitiesId = max(0, $entitiesId);
        $year       = (int) $year;
        $scope      = $this->normalizeScope($scope);
        $table      = NumberSequence::getTable();
        $now        = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $nowEsc     = $this->escapeTs($now);

        // (1) Garantizar la fila de secuencia (idempotente). next_number = 1 = "próximo a asignar".
        $DB->doQuery(
            "INSERT IGNORE INTO `{$table}` (`entities_id`, `scope`, `year`, `next_number`, `date_mod`) "
            . "VALUES ({$entitiesId}, '{$scope}', {$year}, 1, '{$nowEsc}')"
        );

        $last = null;
        for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
            try {
                $DB->beginTransaction();
                // (2) Incremento atómico bajo bloqueo de fila.
                $DB->doQuery(
                    "UPDATE `{$table}` SET `next_number` = `next_number` + 1, `date_mod` = '{$nowEsc}' "
                    . "WHERE `entities_id` = {$entitiesId} AND `scope` = '{$scope}' AND `year` = {$year}"
                );
                $newNext = 0;
                foreach ($DB->request([
                    'SELECT' => 'next_number',
                    'FROM'   => $table,
                    'WHERE'  => ['entities_id' => $entitiesId, 'scope' => $scope, 'year' => $year],
                    'LIMIT'  => 1,
                ]) as $row) {
                    $newNext = (int) $row['next_number'];
                }
                $DB->commit();

                if ($newNext < 2) {
                    throw new \RuntimeException('secuencia inconsistente');
                }
                return $newNext - 1;
            } catch (\Throwable $e) {
                $this->safeRollback($DB);
                $last = $e;
                usleep(1000 * (1 << $attempt)); // backoff 1ms,2ms,4ms…
            }
        }
        throw new \RuntimeException('no se pudo asignar número de solicitud' . ($last ? ': ' . $last->getMessage() : ''));
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if (preg_match('/^[a-z0-9_]{1,60}$/', $scope) !== 1) {
            throw new \InvalidArgumentException('scope de numeración inválido');
        }
        return $scope;
    }

    private function escapeTs(string $ts): string
    {
        // Timestamp propio (no input de usuario); se restringe a un formato de fecha/hora.
        return preg_match('/^[0-9 :\-]{1,25}$/', $ts) === 1 ? $ts : date('Y-m-d H:i:s');
    }

    private function safeRollback(\DBmysql $DB): void
    {
        try {
            if (!method_exists($DB, 'inTransaction') || $DB->inTransaction()) {
                $DB->rollBack();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }
}
