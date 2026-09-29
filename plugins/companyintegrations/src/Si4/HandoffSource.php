<?php

/**
 * PUERTO de consumo del handoff de inventario (ADR-0020 §7). SI-4 consume EXCLUSIVAMENTE la API pública de
 * Compras (`PurchasingIntegrationApi`, ADR-0019 §6) a través de este puerto: nunca SQL contra tablas de Compras.
 *
 * Semántica (la de Compras): `claimPending` toma con lease (token nuevo, `attempts`+1); `acknowledgeProcessed` /
 * `markRetry` / `markError` exigen status LEASED + el token vigente + lease no vencido (reloj de la BD) y son
 * idempotentes tras el éxito; un lease vencido o re-tomado lanza `\RuntimeException`.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

interface HandoffSource
{
    /**
     * @return array<int,array{receipt_unit_uuid:string, lease_token:string, leased_until:string, attempts:int,
     *                          payload_version:int, payload:array<string,mixed>, payload_sha256:string}>
     */
    public function claimPending(string $workerId, int $limit, int $leaseSeconds): array;

    /** @return array<string,mixed>|null */
    public function getHandoff(string $receiptUnitUuid): ?array;

    /** @return array{status:string, idempotent:bool} */
    public function acknowledgeProcessed(string $receiptUnitUuid, string $leaseToken): array;

    /** @return array{status:string, idempotent:bool} */
    public function markRetry(string $receiptUnitUuid, string $leaseToken, string $error, \DateTimeInterface $nextRetryAt): array;

    /** @return array{status:string, idempotent:bool} */
    public function markError(string $receiptUnitUuid, string $leaseToken, string $error): array;
}
