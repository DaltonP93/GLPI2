<?php

/**
 * Adaptador de PRODUCCIÓN del puerto `HandoffSource`: delega 1:1 en `PurchasingIntegrationApi` de companypurchasing
 * (API pública del outbox). Sin SQL propio ni lógica adicional: la ACL (`RIGHT_INTEGRATION`), la multi-entidad, el
 * lease y la validación del payload los aplica Compras.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companypurchasing\Api\PurchasingIntegrationApi;

final class PurchasingHandoffSource implements HandoffSource
{
    private PurchasingIntegrationApi $api;

    /** @throws \RuntimeException companypurchasing no disponible (plugin inactivo) — fail-closed */
    public function __construct(?PurchasingIntegrationApi $api = null)
    {
        if ($api === null && !class_exists(PurchasingIntegrationApi::class)) {
            throw new \RuntimeException('companypurchasing no está disponible (PurchasingIntegrationApi)');
        }
        $this->api = $api ?? new PurchasingIntegrationApi();
    }

    public function claimPending(string $workerId, int $limit, int $leaseSeconds): array
    {
        return $this->api->claimPending($workerId, $limit, $leaseSeconds);
    }

    public function getHandoff(string $receiptUnitUuid): ?array
    {
        return $this->api->getHandoff($receiptUnitUuid);
    }

    public function acknowledgeProcessed(string $receiptUnitUuid, string $leaseToken): array
    {
        return $this->api->acknowledgeProcessed($receiptUnitUuid, $leaseToken);
    }

    public function markRetry(string $receiptUnitUuid, string $leaseToken, string $error, \DateTimeInterface $nextRetryAt): array
    {
        return $this->api->markRetry($receiptUnitUuid, $leaseToken, $error, $nextRetryAt);
    }

    public function markError(string $receiptUnitUuid, string $leaseToken, string $error): array
    {
        return $this->api->markError($receiptUnitUuid, $leaseToken, $error);
    }
}
