<?php

/**
 * DOBLE DE PRUEBA de `SagaStore` con la MISMA semántica de fencing que `DbSagaStore` pero con reloj inyectable
 * (tests de contrato sin BD). La implementación real con el reloj de la BD se prueba en el selftest de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class InMemorySagaStore implements SagaStore
{
    /** @var array<string,array<string,mixed>> */
    public array $rows = [];
    /** @var array<int,array<string,mixed>> */
    public array $log = [];
    /** @var callable():int */
    private $clock;

    /** @param callable():int $clock */
    public function __construct(callable $clock)
    {
        $this->clock = $clock;
    }

    public function acquire(string $uuid, array $meta, string $tokenSha256, string $leaseUntil, int $epoch, string $workerId): ?array
    {
        $until = (int) strtotime($leaseUntil . ' UTC');
        if (!isset($this->rows[$uuid])) {
            $this->rows[$uuid] = [
                'receipt_unit_uuid' => $uuid, 'entities_id' => $meta['entities_id'], 'requests_id' => $meta['requests_id'],
                'items_id' => $meta['items_id'], 'payload_sha256' => $meta['payload_sha256'], 'correlation_id' => $meta['correlation_id'],
                'state' => SagaState::PENDING, 'snipe_asset_id' => null, 'snipe_asset_tag' => null, 'snipe_outcome' => null,
                'snipe_company_id' => null, 'snipe_model_id' => null, 'snipe_status_id' => null,
                'lease_token_sha256' => $tokenSha256, 'lease_until' => $until, 'lease_epoch' => $epoch, 'worker_id' => $workerId,
                'attempts' => 1, 'remote_create_calls' => 0, 'last_error' => null, 'last_error_class' => null,
                'glpi_itemtype' => null, 'glpi_items_id' => null, 'glpi_entity_id' => null, 'glpi_outcome' => null,
                'glpi_create_calls' => 0, 'glpi_infocom_id' => null, 'infocom_outcome' => null, 'asset_bridge_id' => null,
                'resume_state' => null,
            ];
            $this->log[] = ['uuid' => $uuid, 'event' => 'acquire', 'to' => SagaState::PENDING];
            return $this->rows[$uuid];
        }
        $r = $this->rows[$uuid];
        if (hash_equals((string) $r['lease_token_sha256'], $tokenSha256)) {
            return $r;
        }
        if ($epoch <= (int) $r['lease_epoch']) {
            return null; // claim viejo o concurrente: sólo una época MAYOR desplaza al dueño
        }
        $this->rows[$uuid]['lease_token_sha256'] = $tokenSha256;
        $this->rows[$uuid]['lease_until'] = $until;
        $this->rows[$uuid]['lease_epoch'] = $epoch;
        $this->rows[$uuid]['worker_id'] = $workerId;
        $this->rows[$uuid]['attempts']++;
        $this->log[] = ['uuid' => $uuid, 'event' => 'takeover', 'to' => $r['state']];
        return $this->rows[$uuid];
    }

    public function transition(string $uuid, string $tokenSha256, string $fromState, array $set, string $event, string $detail = '', int $minRemainingSeconds = 0): bool
    {
        $r = $this->rows[$uuid] ?? null;
        $now = (int) ($this->clock)();
        if ($r === null || !hash_equals((string) $r['lease_token_sha256'], $tokenSha256)
            || $r['lease_until'] === null || $r['lease_until'] < $now + $minRemainingSeconds) {
            return false;
        }
        if (isset($set['snipe_asset_id']) && $set['snipe_asset_id'] !== null) {
            foreach ($this->rows as $u => $o) {
                if ($u !== $uuid && (int) $o['snipe_asset_id'] === (int) $set['snipe_asset_id']) {
                    throw new \RuntimeException('snipe_asset_id ya ligado a otra unidad (UNIQUE)');
                }
            }
        }
        $itemtype = array_key_exists('glpi_itemtype', $set) ? $set['glpi_itemtype'] : $r['glpi_itemtype'];
        $itemsId  = array_key_exists('glpi_items_id', $set) ? $set['glpi_items_id'] : $r['glpi_items_id'];
        if ($itemtype !== null && $itemsId !== null) {
            foreach ($this->rows as $u => $o) {
                if ($u !== $uuid && $o['glpi_itemtype'] === $itemtype && (int) $o['glpi_items_id'] === (int) $itemsId) {
                    throw new \RuntimeException('activo GLPI ya ligado a otra unidad (UNIQUE glpi_item)');
                }
            }
        }
        if (array_key_exists('resume_state', $set) && $set['resume_state'] !== null && !in_array($set['resume_state'], SagaState::POST_SNIPE, true)) {
            throw new \InvalidArgumentException('resume_state inválido');
        }
        foreach ($set as $k => $v) {
            $this->rows[$uuid][$k] = $v;
        }
        $this->log[] = ['uuid' => $uuid, 'event' => $event, 'from' => $fromState, 'to' => $this->rows[$uuid]['state'], 'detail' => $detail];
        return true;
    }

    public function holds(string $uuid, string $tokenSha256, int $minRemainingSeconds): bool
    {
        $r = $this->rows[$uuid] ?? null;
        return $r !== null && hash_equals((string) $r['lease_token_sha256'], $tokenSha256)
            && $r['lease_until'] !== null && $r['lease_until'] >= (int) ($this->clock)() + $minRemainingSeconds;
    }

    public function get(string $uuid): ?array
    {
        return $this->rows[$uuid] ?? null;
    }
}
