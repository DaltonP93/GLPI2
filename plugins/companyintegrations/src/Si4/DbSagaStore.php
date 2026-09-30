<?php

/**
 * `SagaStore` de PRODUCCIÓN sobre tablas PROPIAS de companyintegrations (`si4_sagas` + bitácora `si4_saga_log`).
 * Fencing: época monótona del lease (`attempts` del claim) para la TOMA, y token + reloj ÚNICO de la BD (`NOW()`) para
 * cada escritura, igual que el lease del outbox de Compras (ADR-0019 §6 / ADR-0020 §6).
 * Sólo toca tablas de este plugin: nunca tablas de Compras ni del core.
 *
 * Cada transición incrementa `row_version` (el UPDATE siempre modifica la fila, así `affectedRows() === 1` distingue
 * "escrito" de "lease perdido" aunque los valores coincidan).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Model\Si4Saga;
use GlpiPlugin\Companyintegrations\Model\Si4SagaLog;

final class DbSagaStore implements SagaStore
{
    private const SETTABLE = [
        'state', 'snipe_asset_id', 'snipe_asset_tag', 'snipe_outcome', 'snipe_company_id', 'snipe_model_id',
        'snipe_status_id', 'remote_create_calls', 'last_error', 'last_error_class',
        // SI4-2 (ADR-0021)
        'glpi_itemtype', 'glpi_items_id', 'glpi_entity_id', 'glpi_outcome', 'glpi_create_calls', 'glpi_infocom_id',
        'infocom_outcome', 'asset_bridge_id', 'resume_state',
    ];
    private const DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    public function acquire(string $uuid, array $meta, string $tokenSha256, string $leaseUntil, int $epoch, string $workerId): ?array
    {
        $this->assertKeys($uuid, $tokenSha256);
        if (preg_match(self::DATETIME, $leaseUntil) !== 1) {
            throw new \InvalidArgumentException('lease_until inválido');
        }
        /** @var \DBmysql $DB */
        global $DB;
        $t = Si4Saga::getTable();
        for ($try = 0; $try < 2; $try++) {
            $DB->beginTransaction();
            try {
                $row = null;
                $res = $DB->doQuery("SELECT * FROM `{$t}` WHERE `receipt_unit_uuid` = '" . $uuid . "' FOR UPDATE");
                if ($res !== false && ($r = $DB->fetchAssoc($res))) {
                    $row = $r;
                }
                if ($row === null) {
                    $DB->doQuery("INSERT INTO `{$t}` (`receipt_unit_uuid`, `entities_id`, `requests_id`, `items_id`, `payload_sha256`,"
                        . " `correlation_id`, `state`, `lease_token_sha256`, `lease_until`, `lease_epoch`, `worker_id`, `attempts`, `remote_create_calls`,"
                        . " `row_version`, `date_creation`, `date_mod`) VALUES ('" . $uuid . "', " . (int) $meta['entities_id'] . ', '
                        . (int) $meta['requests_id'] . ', ' . (int) $meta['items_id'] . ", '" . $DB->escape((string) $meta['payload_sha256']) . "', '"
                        . $DB->escape(mb_substr((string) $meta['correlation_id'], 0, 64)) . "', '" . SagaState::PENDING . "', '" . $tokenSha256 . "', '"
                        . $leaseUntil . "', " . max(0, $epoch) . ", '" . $DB->escape(mb_substr($workerId, 0, 190)) . "', 1, 0, 1, NOW(), NOW())");
                    $this->log($uuid, 'acquire', null, SagaState::PENDING, '', $workerId);
                    $DB->commit();
                    return $this->get($uuid);
                }
                if (hash_equals((string) ($row['lease_token_sha256'] ?? ''), $tokenSha256)) {
                    $DB->commit();
                    return $row;
                }
                if ($epoch <= (int) $row['lease_epoch']) {
                    $DB->rollBack();
                    return null; // claim viejo o concurrente: sólo una época MAYOR desplaza al dueño (fail-closed)
                }
                $DB->doQuery("UPDATE `{$t}` SET `lease_token_sha256` = '" . $tokenSha256 . "', `lease_until` = '" . $leaseUntil . "',"
                    . ' `lease_epoch` = ' . max(0, $epoch) . ','
                    . " `worker_id` = '" . $DB->escape(mb_substr($workerId, 0, 190)) . "', `attempts` = `attempts` + 1,"
                    . " `row_version` = `row_version` + 1, `date_mod` = NOW() WHERE `id` = " . (int) $row['id']);
                $this->log($uuid, 'takeover', (string) $row['state'], (string) $row['state'], '', $workerId);
                $DB->commit();
                return $this->get($uuid);
            } catch (\RuntimeException $e) {
                $this->rollback($DB);
                if ($try === 0 && str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    continue; // carrera de INSERT: re-leer y aplicar la regla de toma
                }
                throw $e;
            }
        }
        return null;
    }

    public function transition(string $uuid, string $tokenSha256, string $fromState, array $set, string $event, string $detail = '', int $minRemainingSeconds = 0): bool
    {
        $this->assertKeys($uuid, $tokenSha256);
        /** @var \DBmysql $DB */
        global $DB;
        $t = Si4Saga::getTable();
        $parts = [];
        foreach ($set as $col => $val) {
            if (!in_array($col, self::SETTABLE, true)) {
                throw new \InvalidArgumentException('columna de saga no permitida: ' . $col);
            }
            if ($col === 'state' && !in_array($val, SagaState::ALL, true)) {
                throw new \InvalidArgumentException('estado de saga inválido');
            }
            if ($col === 'resume_state' && $val !== null && !in_array($val, SagaState::POST_SNIPE, true)) {
                throw new \InvalidArgumentException('resume_state inválido');
            }
            $parts[] = '`' . $col . '` = ' . ($val === null ? 'NULL' : (is_int($val) ? (string) $val : "'" . $DB->escape((string) $val) . "'"));
        }
        $parts[] = '`row_version` = `row_version` + 1';
        $parts[] = '`date_mod` = NOW()';
        $DB->beginTransaction();
        try {
            $DB->doQuery("UPDATE `{$t}` SET " . implode(', ', $parts)
                . " WHERE `receipt_unit_uuid` = '" . $uuid . "' AND `lease_token_sha256` = '" . $tokenSha256 . "'"
                . ' AND `lease_until` IS NOT NULL AND `lease_until` >= DATE_ADD(NOW(), INTERVAL ' . max(0, $minRemainingSeconds) . ' SECOND)');
            if ($DB->affectedRows() !== 1) {
                $DB->rollBack();
                return false;
            }
            $this->log($uuid, $event, $fromState, (string) ($set['state'] ?? $fromState), $detail, '');
            $DB->commit();
            return true;
        } catch (\RuntimeException $e) {
            $this->rollback($DB);
            throw new \RuntimeException('saga: escritura rechazada (' . Si4Errors::sanitize($e->getMessage()) . ')', 0, $e);
        }
    }

    public function holds(string $uuid, string $tokenSha256, int $minRemainingSeconds): bool
    {
        $this->assertKeys($uuid, $tokenSha256);
        /** @var \DBmysql $DB */
        global $DB;
        $res = $DB->doQuery('SELECT 1 AS ok FROM `' . Si4Saga::getTable() . "` WHERE `receipt_unit_uuid` = '" . $uuid . "'"
            . " AND `lease_token_sha256` = '" . $tokenSha256 . "' AND `lease_until` IS NOT NULL"
            . ' AND `lease_until` >= DATE_ADD(NOW(), INTERVAL ' . max(0, $minRemainingSeconds) . ' SECOND)');
        return $res !== false && $DB->numrows($res) === 1;
    }

    public function get(string $uuid): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;
        foreach ($DB->request(['FROM' => Si4Saga::getTable(), 'WHERE' => ['receipt_unit_uuid' => $uuid], 'LIMIT' => 1]) as $row) {
            return $row;
        }
        return null;
    }

    private function log(string $uuid, string $event, ?string $from, string $to, string $detail, string $workerId): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->doQuery('INSERT INTO `' . Si4SagaLog::getTable() . '` (`receipt_unit_uuid`, `event`, `from_state`, `to_state`, `detail`, `worker_id`, `date_creation`)'
            . " VALUES ('" . $uuid . "', '" . $DB->escape(mb_substr($event, 0, 40)) . "', "
            . ($from === null ? 'NULL' : "'" . $DB->escape($from) . "'") . ", '" . $DB->escape($to) . "', "
            . ($detail === '' ? 'NULL' : "'" . $DB->escape(Si4Errors::sanitize($detail)) . "'") . ', '
            . ($workerId === '' ? 'NULL' : "'" . $DB->escape(mb_substr($workerId, 0, 190)) . "'") . ', NOW())');
    }

    private function assertKeys(string $uuid, string $tokenSha256): void
    {
        if (preg_match(AssetTagDeriver::UUID_PATTERN, $uuid) !== 1) {
            throw new \InvalidArgumentException('receipt_unit_uuid inválido');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $tokenSha256) !== 1) {
            throw new \InvalidArgumentException('huella de lease inválida');
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
