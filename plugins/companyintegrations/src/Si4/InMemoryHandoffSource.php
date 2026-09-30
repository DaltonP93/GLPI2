<?php

/**
 * DOBLE DE PRUEBA del puerto `HandoffSource` que reproduce la semántica documentada de `PurchasingIntegrationApi`
 * (ADR-0019 §6) con un reloj inyectable: sólo para tests de contrato/crash (el adaptador de producción es
 * `PurchasingHandoffSource`). El selftest en GLPI además ejercita la API REAL de Compras.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class InMemoryHandoffSource implements HandoffSource
{
    public int $maxAttempts = 10;
    /** @var array<int,array{uuid:string,token:string}> */
    public array $acks = [];
    /** @var array<int,array{uuid:string,error:string,next:int}> */
    public array $retries = [];
    /** @var array<int,array{uuid:string,error:string}> */
    public array $errors = [];

    /** @var array<string,array<string,mixed>> */
    private array $rows = [];
    /** @var callable():int */
    private $clock;
    /** @var array<int,int> */
    private array $activeEntities;

    /** @param callable():int $clock @param array<int,int> $activeEntities */
    public function __construct(callable $clock, array $activeEntities)
    {
        $this->clock = $clock;
        $this->activeEntities = $activeEntities;
    }

    /** @param array<string,mixed> $payload */
    public function add(array $payload): void
    {
        $uuid = (string) $payload['receipt_unit_uuid'];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->rows[$uuid] = [
            'uuid' => $uuid, 'entity' => (int) $payload['entity_id'], 'status' => 'PENDING', 'token' => null,
            'leased_until' => null, 'attempts' => 0, 'next_retry_at' => null, 'last_error' => null,
            'payload' => $payload, 'sha' => hash('sha256', $json),
        ];
    }

    /** @param array<int,int> $entities */
    public function setActiveEntities(array $entities): void
    {
        $this->activeEntities = $entities;
    }

    /** @return array<string,mixed>|null */
    public function row(string $uuid): ?array
    {
        return $this->rows[$uuid] ?? null;
    }

    /** Fuerza el vencimiento del lease (equivalente a `leased_until` en el pasado). */
    public function expire(string $uuid): void
    {
        if (isset($this->rows[$uuid]) && $this->rows[$uuid]['leased_until'] !== null) {
            $this->rows[$uuid]['leased_until'] = $this->now() - 5;
        }
    }

    public function claimPending(string $workerId, int $limit, int $leaseSeconds): array
    {
        $now = $this->now();
        $out = [];
        foreach ($this->rows as $uuid => $r) {
            if (count($out) >= $limit) {
                break;
            }
            if (!in_array($r['entity'], $this->activeEntities, true)) {
                continue;
            }
            $eligible = $r['status'] === 'PENDING'
                || ($r['status'] === 'RETRY' && ($r['next_retry_at'] === null || $r['next_retry_at'] <= $now))
                || ($r['status'] === 'LEASED' && $r['leased_until'] < $now);
            if (!$eligible) {
                continue;
            }
            $token = bin2hex(random_bytes(32));
            $this->rows[$uuid]['status'] = 'LEASED';
            $this->rows[$uuid]['token'] = $token;
            $this->rows[$uuid]['leased_until'] = $now + $leaseSeconds;
            $this->rows[$uuid]['attempts']++;
            $out[] = [
                'receipt_unit_uuid' => $uuid, 'lease_token' => $token,
                'leased_until' => gmdate('Y-m-d H:i:s', $now + $leaseSeconds),
                'attempts' => $this->rows[$uuid]['attempts'], 'payload_version' => 1,
                'payload' => $r['payload'], 'payload_sha256' => $r['sha'],
            ];
        }
        return $out;
    }

    public function getHandoff(string $receiptUnitUuid): ?array
    {
        $r = $this->rows[$receiptUnitUuid] ?? null;
        if ($r === null || !in_array($r['entity'], $this->activeEntities, true)) {
            return null;
        }
        return [
            'receipt_unit_uuid' => $r['uuid'], 'status' => $r['status'], 'attempts' => $r['attempts'],
            'payload_version' => 1, 'payload' => $r['payload'], 'payload_sha256' => $r['sha'],
            'next_retry_at' => $r['next_retry_at'] !== null ? gmdate('Y-m-d H:i:s', $r['next_retry_at']) : null,
            'last_error' => $r['last_error'],
        ];
    }

    public function acknowledgeProcessed(string $receiptUnitUuid, string $leaseToken): array
    {
        $res = $this->settle($receiptUnitUuid, $leaseToken, 'DONE', null, null);
        $this->acks[] = ['uuid' => $receiptUnitUuid, 'token' => $leaseToken];
        return $res;
    }

    public function markRetry(string $receiptUnitUuid, string $leaseToken, string $error, \DateTimeInterface $nextRetryAt): array
    {
        $res = $this->settle($receiptUnitUuid, $leaseToken, 'RETRY', $error, $nextRetryAt->getTimestamp());
        $this->retries[] = ['uuid' => $receiptUnitUuid, 'error' => $error, 'next' => $nextRetryAt->getTimestamp()];
        return $res;
    }

    public function markError(string $receiptUnitUuid, string $leaseToken, string $error): array
    {
        $res = $this->settle($receiptUnitUuid, $leaseToken, 'ERROR', $error, null);
        $this->errors[] = ['uuid' => $receiptUnitUuid, 'error' => $error];
        return $res;
    }

    /** @return array{status:string, idempotent:bool} */
    private function settle(string $uuid, string $token, string $to, ?string $error, ?int $next): array
    {
        $r = $this->rows[$uuid] ?? null;
        if ($r === null || !in_array($r['entity'], $this->activeEntities, true)) {
            throw new \RuntimeException('handoff inexistente o fuera de las entidades de la sesión');
        }
        $final = ($to === 'RETRY' && $r['attempts'] >= $this->maxAttempts) ? 'ERROR' : $to;
        $valid = $r['status'] === 'LEASED' && $r['token'] !== null && hash_equals($r['token'], $token)
            && $r['leased_until'] !== null && $r['leased_until'] >= $this->now();
        if ($valid) {
            $this->rows[$uuid]['status'] = $final;
            $this->rows[$uuid]['leased_until'] = null;
            $this->rows[$uuid]['last_error'] = $error;
            if ($final === 'RETRY') {
                $this->rows[$uuid]['next_retry_at'] = $next;
            }
            return ['status' => $final, 'idempotent' => false];
        }
        if ($r['token'] !== null && hash_equals($r['token'], $token) && $r['status'] === $final) {
            return ['status' => $final, 'idempotent' => true];
        }
        throw new \RuntimeException('lease inválido: vencido, token no vigente (re-tomado por otro worker) o handoff ya cerrado (fail-closed)');
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
