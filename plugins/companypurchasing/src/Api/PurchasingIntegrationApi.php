<?php

/**
 * API PÚBLICA de integración del handoff de inventario (P2D-3; gate §7). Contrato para el futuro SI-4
 * (`companyintegrations`), que NO debe leer ni escribir por SQL las tablas privadas de Compras.
 *
 *   claimPending(workerId, limit, leaseSeconds)              → toma elegibles con LEASE (token opaco)
 *   getHandoff(receiptUnitUuid)                              → lectura por identidad canónica
 *   acknowledgeProcessed(receiptUnitUuid, leaseToken)        → DONE
 *   markRetry(receiptUnitUuid, leaseToken, error, nextRetryAt) → RETRY (o ERROR al agotar intentos)
 *   markError(receiptUnitUuid, leaseToken, error)            → ERROR (final)
 *
 * Protocolo de lease:
 *   - `claimPending()` es ATÓMICO (`SELECT … FOR UPDATE SKIP LOCKED` + UPDATE en una transacción): dos workers
 *     concurrentes nunca toman la misma fila. Elegibles: PENDING; RETRY con `next_retry_at` vencido; LEASED con
 *     `leased_until` vencido (lease abandonado). Cada toma genera un `lease_token` NUEVO (CSPRNG) y
 *     `leased_until = NOW() + leaseSeconds` (reloj ÚNICO de la BD) e incrementa `attempts`.
 *   - ack/retry/error exigen, en un UPDATE condicionado con el reloj de la BD: `status = LEASED`, el MISMO
 *     `lease_token` y `leased_until >= NOW()`. Un lease VENCIDO no confirma, aunque nadie lo haya re-tomado aún
 *     (la fila sigue reclamable); un worker re-tomado por otro tampoco (su token dejó de ser el vigente). Tras una
 *     confirmación exitosa, repetirla con el mismo token es idempotente.
 *   - El payload es INMUTABLE: `HandoffPayload::validatedPayload()` (versión, JSON canónico, hash, identidad
 *     redundante con la fila y con la unidad) se aplica IGUAL en cada toma y lectura (inconsistente ⇒ ERROR en
 *     `claimPending()` / excepción en `getHandoff()`; nunca se entrega).
 *
 * ACL: derecho dedicado de mínimo privilegio `plugin_companypurchasing` bit `RIGHT_INTEGRATION` (no Super-Admin)
 * + multi-entidad estricta (sólo filas de entidades activas de la sesión). `last_error` se sanea (sin secretos).
 *
 * Este plugin NO escribe a Snipe-IT, NO crea activos GLPI ni Infocom y NO invoca companyqr: sólo publica y
 * entrega el hecho de negocio recibido.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Api;

use Session;
use GlpiPlugin\Companypurchasing\Model\OutboxEntry;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;
use GlpiPlugin\Companypurchasing\Model\ReceiptUnit;
use GlpiPlugin\Companypurchasing\Model\Request;
use GlpiPlugin\Companypurchasing\Service\Audit;
use GlpiPlugin\Companypurchasing\Service\HandoffPayload;
use GlpiPlugin\Companypurchasing\Service\PluginConfig;

final class PurchasingIntegrationApi
{
    public const MAX_CLAIM = 100;
    private const WORKER_PATTERN = '/^[A-Za-z0-9._:@-]{1,190}$/';
    private const TOKEN_PATTERN  = '/^[0-9a-f]{64}$/';

    private Audit $audit;

    public function __construct(?Audit $audit = null)
    {
        $this->audit = $audit ?? new Audit();
    }

    /**
     * @return array<int,array{receipt_unit_uuid:string, lease_token:string, leased_until:string, attempts:int,
     *                          payload_version:int, payload:array<string,mixed>, payload_sha256:string}>
     */
    public function claimPending(string $workerId, int $limit, int $leaseSeconds): array
    {
        $this->assertRight();
        if (preg_match(self::WORKER_PATTERN, $workerId) !== 1) {
            throw new \InvalidArgumentException('workerId inválido');
        }
        if ($limit < 1 || $limit > self::MAX_CLAIM) {
            throw new \InvalidArgumentException('limit fuera de rango (1..' . self::MAX_CLAIM . ')');
        }
        if ($leaseSeconds < 1 || $leaseSeconds > PluginConfig::outboxMaxLeaseSeconds()) {
            throw new \InvalidArgumentException('leaseSeconds fuera de rango');
        }
        $entities = $this->activeEntities();
        if ($entities === []) {
            return [];
        }
        /** @var \DBmysql $DB */
        global $DB;
        $t = OutboxEntry::getTable();
        $claimed = [];
        $DB->beginTransaction();
        try {
            $res = $DB->doQuery(
                "SELECT `id`, `receipt_unit_uuid`, `receipt_units_id`, `requests_id`, `entities_id`, `payload_version`, `payload_json`, `payload_sha256`, `attempts`"
                . " FROM `{$t}` WHERE `entities_id` IN (" . implode(',', $entities) . ")"
                . " AND (`status` = '" . OutboxEntry::STATUS_PENDING . "'"
                . " OR (`status` = '" . OutboxEntry::STATUS_RETRY . "' AND (`next_retry_at` IS NULL OR `next_retry_at` <= NOW()))"
                . " OR (`status` = '" . OutboxEntry::STATUS_LEASED . "' AND `leased_until` < NOW()))"
                . " ORDER BY `id` ASC LIMIT " . $limit . " FOR UPDATE SKIP LOCKED"
            );
            $rows = [];
            while ($res !== false && ($row = $DB->fetchAssoc($res))) {
                $rows[] = $row;
            }
            $units = $this->unitsById(array_map(static fn (array $r): int => (int) $r['receipt_units_id'], $rows));
            foreach ($rows as $row) {
                try {
                    $payload = HandoffPayload::validatedPayload($row, $units[(int) $row['receipt_units_id']] ?? null);
                } catch (\RuntimeException $bad) {
                    // Handoff inconsistente (versión/JSON/hash/identidad): NUNCA se entrega; queda en ERROR visible.
                    $DB->doQuery("UPDATE `{$t}` SET `status` = '" . OutboxEntry::STATUS_ERROR . "', `lease_token` = NULL, `leased_by` = NULL,"
                        . " `leased_until` = NULL, `last_error` = 'payload integrity check failed', `date_mod` = NOW() WHERE `id` = " . (int) $row['id']);
                    $this->audit->recordOnce((int) $row['requests_id'], PurchasingEvent::EV_HANDOFF_ERROR, (int) $row['entities_id'], [
                        'receipt_unit_uuid' => (string) $row['receipt_unit_uuid'], 'reason' => 'payload_integrity',
                        'detail' => self::sanitizeError($bad->getMessage()),
                    ], '', 'handoff-error:' . $row['receipt_unit_uuid']);
                    continue;
                }
                $token = bin2hex(random_bytes(32));
                $DB->doQuery("UPDATE `{$t}` SET `status` = '" . OutboxEntry::STATUS_LEASED . "', `lease_token` = '" . $token . "',"
                    . " `leased_by` = '" . $DB->escape($workerId) . "', `leased_until` = DATE_ADD(NOW(), INTERVAL " . $leaseSeconds . " SECOND),"
                    . " `attempts` = `attempts` + 1, `date_mod` = NOW() WHERE `id` = " . (int) $row['id']);
                $claimed[(int) $row['id']] = [
                    'receipt_unit_uuid' => (string) $row['receipt_unit_uuid'],
                    'lease_token'       => $token,
                    'leased_until'      => '',
                    'attempts'          => (int) $row['attempts'] + 1,
                    'payload_version'   => (int) $row['payload_version'],
                    'payload'           => $payload,
                    'payload_sha256'    => (string) $row['payload_sha256'],
                ];
            }
            if ($claimed !== []) {
                foreach ($DB->request(['SELECT' => ['id', 'leased_until'], 'FROM' => $t, 'WHERE' => ['id' => array_keys($claimed)]]) as $r) {
                    $claimed[(int) $r['id']]['leased_until'] = (string) $r['leased_until'];
                }
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $this->rollback($DB);
            throw $e;
        }
        return array_values($claimed);
    }

    /**
     * @return array{receipt_unit_uuid:string, status:string, attempts:int, payload_version:int,
     *               payload:array<string,mixed>, payload_sha256:string, next_retry_at:?string, last_error:?string}|null
     */
    public function getHandoff(string $receiptUnitUuid): ?array
    {
        $this->assertRight();
        $row = $this->row($receiptUnitUuid);
        if ($row === null) {
            return null;
        }
        // MISMA validación que `claimPending()`: un handoff inconsistente nunca se devuelve (fail-closed).
        $payload = HandoffPayload::validatedPayload($row, $this->unitsById([(int) $row['receipt_units_id']])[(int) $row['receipt_units_id']] ?? null);
        return [
            'receipt_unit_uuid' => (string) $row['receipt_unit_uuid'],
            'status'            => (string) $row['status'],
            'attempts'          => (int) $row['attempts'],
            'payload_version'   => (int) $row['payload_version'],
            'payload'           => $payload,
            'payload_sha256'    => (string) $row['payload_sha256'],
            'next_retry_at'     => $row['next_retry_at'] !== null ? (string) $row['next_retry_at'] : null,
            'last_error'        => $row['last_error'] !== null ? (string) $row['last_error'] : null,
        ];
    }

    /** @return array{status:string, idempotent:bool} */
    public function acknowledgeProcessed(string $receiptUnitUuid, string $leaseToken): array
    {
        return $this->settle($receiptUnitUuid, $leaseToken, OutboxEntry::STATUS_DONE, null, null);
    }

    /** @return array{status:string, idempotent:bool} */
    public function markRetry(string $receiptUnitUuid, string $leaseToken, string $error, \DateTimeInterface|string $nextRetryAt): array
    {
        $epoch = $nextRetryAt instanceof \DateTimeInterface ? $nextRetryAt->getTimestamp() : strtotime($nextRetryAt);
        if ($epoch === false || $epoch <= 0) {
            throw new \InvalidArgumentException('nextRetryAt inválido');
        }
        return $this->settle($receiptUnitUuid, $leaseToken, OutboxEntry::STATUS_RETRY, $error, (int) $epoch);
    }

    /** @return array{status:string, idempotent:bool} */
    public function markError(string $receiptUnitUuid, string $leaseToken, string $error): array
    {
        return $this->settle($receiptUnitUuid, $leaseToken, OutboxEntry::STATUS_ERROR, $error, null);
    }

    /** Saneamiento de `last_error`: sin controles, sin secretos evidentes, ≤ 250. PURO. */
    public static function sanitizeError(string $error): string
    {
        $e = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $error) ?? '';
        // Orden: esquemas de autenticación y credenciales en URL ANTES que "clave=valor" (que sólo toma un token).
        $e = preg_replace('/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $e) ?? '';
        $e = preg_replace('#(https?://)[^/\s:@]+:[^/\s@]+@#i', '$1[redacted]@', $e) ?? '';
        $e = preg_replace('/\b(password|passwd|pwd|token|secret|api[_-]?key)\b\s*[:=]\s*\S+/i', '$1=[redacted]', $e) ?? '';
        return mb_substr(trim($e), 0, 250);
    }

    // ---------------------------------------------------------------- internals

    /**
     * Transición de ENTREGA con el lease VIGENTE: UPDATE condicionado por `status = LEASED`, el `lease_token` y
     * `leased_until >= NOW()` (reloj ÚNICO de la BD, igual que `claimPending()`). Vencido ⇒ no confirma (la fila
     * sigue reclamable). Tras el éxito, la misma confirmación con el mismo token es idempotente.
     *
     * @return array{status:string, idempotent:bool}
     */
    private function settle(string $uuid, string $token, string $to, ?string $error, ?int $retryEpoch): array
    {
        $this->assertRight();
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new \InvalidArgumentException('lease_token inválido');
        }
        $row = $this->row($uuid);
        if ($row === null) {
            throw new \RuntimeException('handoff inexistente o fuera de las entidades de la sesión');
        }
        /** @var \DBmysql $DB */
        global $DB;
        $t = OutboxEntry::getTable();
        $err = $error !== null ? self::sanitizeError($error) : null;
        $final = $to;
        if ($to === OutboxEntry::STATUS_RETRY && (int) $row['attempts'] >= PluginConfig::outboxMaxAttempts()) {
            $final = OutboxEntry::STATUS_ERROR; // intentos agotados ⇒ final
        }
        $set = "`status` = '" . $final . "', `leased_until` = NULL, `date_mod` = NOW()";
        $set .= $err !== null ? ", `last_error` = '" . $DB->escape($err) . "'" : ', `last_error` = NULL';
        if ($final === OutboxEntry::STATUS_DONE) {
            $set .= ', `processed_at` = NOW()';
        }
        if ($final === OutboxEntry::STATUS_RETRY) {
            $set .= ', `next_retry_at` = FROM_UNIXTIME(' . (int) $retryEpoch . ')';
        }
        $DB->beginTransaction();
        try {
            $DB->doQuery("UPDATE `{$t}` SET {$set} WHERE `id` = " . (int) $row['id']
                . " AND `status` = '" . OutboxEntry::STATUS_LEASED . "' AND `lease_token` = '" . $token . "'"
                . ' AND `leased_until` IS NOT NULL AND `leased_until` >= NOW()');
            $applied = $DB->affectedRows() === 1;
            if ($applied) {
                $event = match ($final) {
                    OutboxEntry::STATUS_DONE  => PurchasingEvent::EV_HANDOFF_DONE,
                    OutboxEntry::STATUS_RETRY => PurchasingEvent::EV_HANDOFF_RETRY,
                    default                   => PurchasingEvent::EV_HANDOFF_ERROR,
                };
                $this->audit->recordOnce((int) $row['requests_id'], $event, (int) $row['entities_id'], [
                    'receipt_unit_uuid' => $uuid, 'attempts' => (int) $row['attempts'], 'error' => $err,
                    'worker' => (string) ($row['leased_by'] ?? ''),
                ], '', 'handoff-' . strtolower($final) . ':' . $uuid . ':' . (int) $row['attempts']);
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $this->rollback($DB);
            throw $e;
        }
        if ($applied) {
            return ['status' => $final, 'idempotent' => false];
        }
        // Repetición de la MISMA confirmación con el MISMO token ⇒ idempotente; cualquier otra ⇒ rechazo.
        $now = $this->row($uuid);
        if ($now !== null && hash_equals((string) ($now['lease_token'] ?? ''), $token) && (string) $now['status'] === $final) {
            return ['status' => $final, 'idempotent' => true];
        }
        throw new \RuntimeException('lease inválido: vencido, token no vigente (re-tomado por otro worker) o handoff ya cerrado (fail-closed)');
    }

    /** Fila del outbox por UUID, sólo si pertenece a una entidad activa de la sesión. @return array<string,mixed>|null */
    private function row(string $uuid): ?array
    {
        if (preg_match(HandoffPayload::UUID_PATTERN, $uuid) !== 1) {
            throw new \InvalidArgumentException('receipt_unit_uuid inválido');
        }
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['FROM' => OutboxEntry::getTable(), 'WHERE' => ['receipt_unit_uuid' => $uuid], 'LIMIT' => 1]) as $row) {
            return Session::haveAccessToEntity((int) $row['entities_id']) ? $row : null;
        }
        return null;
    }

    /**
     * Unidades recibidas referenciadas por filas del outbox (lectura consistente; para la validación cruzada).
     *
     * @param array<int,int> $ids
     * @return array<int,array<string,mixed>> id → fila
     */
    private function unitsById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        /** @var \DBmysql $DB */
        global $DB;
        $out = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'receipt_unit_uuid', 'requests_id', 'items_id', 'entities_id', 'serial', 'currency_code', 'unit_cost'],
            'FROM'   => ReceiptUnit::getTable(),
            'WHERE'  => ['id' => $ids],
        ]) as $u) {
            $out[(int) $u['id']] = $u;
        }
        return $out;
    }

    /** @return array<int,int> */
    private function activeEntities(): array
    {
        $ids = array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []));
        return array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i >= 0)));
    }

    private function assertRight(): void
    {
        if (!Session::haveRight(Request::$rightname, Request::RIGHT_INTEGRATION)) {
            throw new \RuntimeException('permiso denegado (INTEGRATION)');
        }
    }

    private function rollback(\DBmysql $DB): void
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
