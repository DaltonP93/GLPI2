<?php

/**
 * Clave de idempotencia del listener/reconciliador (workflow → evidencia) — gate §13 + hardening §3.
 *
 * Identidad basada en el **id DURABLE de la fila de historial de `companyworkflow`**
 * (`workflow_history_id`, devuelto por `AuditBridge::record()`), **no** en `from->to:action` (que no
 * es único). Así:
 *   - un replay del MISMO evento histórico ⇒ la MISMA clave ⇒ una sola evidencia;
 *   - dos eventos históricos DISTINTOS (aunque compartan from/to/action) ⇒ claves distintas ⇒ evidencias distintas.
 *
 * La UNIQUE de `evidences.idempotency_key` es la garantía real de "exactamente una vez".
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
     * Clave para una evidencia derivada de una fila de historial de workflow.
     * Componentes mínimos (hardening §3): `workflow_history_id` + `document_version` + `evidence_type`.
     */
    public function forHistoryEvent(int $workflowHistoryId, int $documentVersion, string $evidenceType): string
    {
        $parts = [
            'wfh',
            (string) $workflowHistoryId,
            'dv',
            (string) $documentVersion,
            'type',
            trim($evidenceType),
        ];
        // Separador que no aparece en los componentes; hash acotado (64 chars).
        return hash('sha256', implode("\x1f", $parts));
    }

    /**
     * Clave para UNA evidencia de invalidación que afecta a UNA evidencia de aprobación concreta.
     * Incluye la evidencia afectada para permitir varias invalidaciones (una por aprobador en quórum)
     * bajo la misma fila de historial de invalidación, sin colisión de UNIQUE.
     */
    public function forInvalidation(int $workflowHistoryId, int $documentVersion, int $affectedEvidenceId): string
    {
        $parts = [
            'wfh',
            (string) $workflowHistoryId,
            'dv',
            (string) $documentVersion,
            'type',
            'invalidated',
            'ref',
            (string) $affectedEvidenceId,
        ];
        return hash('sha256', implode("\x1f", $parts));
    }
}
