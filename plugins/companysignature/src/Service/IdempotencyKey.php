<?php

/**
 * Clave de idempotencia del listener (workflow → evidencia) — gate §13.
 *
 * Identidad ESTABLE del evento de workflow **+** versión documental **+** tipo de evidencia.
 * Un replay (retry · evento duplicado · restart · doble entrega) recomputa la MISMA clave, y la
 * UNIQUE de `evidences.idempotency_key` garantiza **una sola evidencia** por decisión.
 *
 * Tras una invalidación → nueva versión documental → la clave cambia (document_version distinto),
 * habilitando una nueva evidencia legítima para la nueva aprobación.
 *
 * Clase PURA (sin dependencias de GLPI) para poder testearla sin bootstrap.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

final class IdempotencyKey
{
    /**
     * Clave para una decisión de workflow (approved/rejected/returned).
     *
     * @param int    $instanceId        instancia de workflow
     * @param string $workflowEventRef  identidad estable del evento (p. ej. "from->to:action")
     * @param int    $documentVersion   versión documental vigente (0 si aún no hay)
     * @param string $evidenceType      tipo de evidencia (approved/rejected/returned/invalidated)
     */
    public function forDecision(int $instanceId, string $workflowEventRef, int $documentVersion, string $evidenceType): string
    {
        $parts = [
            'wf',
            (string) $instanceId,
            trim($workflowEventRef),
            'dv',
            (string) $documentVersion,
            'type',
            trim($evidenceType),
        ];
        // Hash acotado (64 chars) e inambiguo (separador que no aparece en los componentes).
        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * Clave para una invalidación disparada por el workflow. Reutiliza (si viene) la
     * `idempotency_key` que emitió `companyworkflow:approval_invalidated`; de lo contrario la deriva
     * de la identidad estable del evento + versión documental.
     */
    public function forInvalidation(int $instanceId, string $reasonKey, int $documentVersion): string
    {
        $seed = $reasonKey !== '' ? $reasonKey : ('inv:' . $instanceId . ':dv:' . $documentVersion);
        return $this->forDecision($instanceId, 'invalidation:' . $seed, $documentVersion, 'invalidated');
    }
}
