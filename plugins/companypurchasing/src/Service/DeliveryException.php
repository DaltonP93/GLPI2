<?php

/**
 * Resultado CONTROLADO y tipado de una entrega/cierre rechazados (P2D-4). La UI traduce `kind` a un mensaje
 * funcional i18n (sin detalles técnicos); los tests distinguen el motivo exacto (p. ej. una entrega concurrente
 * serializada por el lock ve la unidad ya entregada ⇒ `unit_not_deliverable`, no `concurrency_conflict`).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class DeliveryException extends \RuntimeException
{
    public const ACL                  = 'acl';
    public const ENTITY               = 'entity';
    public const INVALID              = 'invalid';
    public const STATE                = 'state';
    public const LEGACY_DEFINITION    = 'legacy_definition';
    public const RECIPIENT            = 'recipient';
    public const UNIT_NOT_DELIVERABLE = 'unit_not_deliverable';
    public const INVENTORY_GATE       = 'inventory_gate';
    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';
    public const CONCURRENCY_CONFLICT = 'concurrency_conflict';
    public const NOT_READY_TO_CLOSE   = 'not_ready_to_close';

    /** @param array<string,mixed> $details  datos NO sensibles para la UI (uuids abreviados, motivos de gate) */
    public function __construct(public readonly string $kind, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
