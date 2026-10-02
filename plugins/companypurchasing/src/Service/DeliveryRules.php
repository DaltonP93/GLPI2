<?php

/**
 * Reglas PURAS de la entrega física y del cierre (P2D-4; ADR-0023). Sin GLPI: unit-testables.
 *
 *   - `normalizeUuids()`  → identidad canónica `receipt_unit_uuid` (formato, únicos, acotados, orden estable).
 *   - `inputHash()`       → huella de la ENTRADA de una operación (misma clave + otra entrada ⇒ conflicto).
 *   - `gateStatus()`      → gate de inventario: inventariable ⇒ su handoff (outbox PROPIO de Compras) debe estar DONE.
 *   - `closeBlockers()`   → motivos por los que una solicitud todavía NO puede cerrarse (vacío ⇒ lista para cerrar).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class DeliveryRules
{
    // Gate de inventario (códigos estables; la UI los traduce a un motivo FUNCIONAL, sin datos técnicos).
    public const GATE_DELIVERABLE              = 'deliverable';
    public const GATE_INVENTORY_PENDING        = 'inventory_pending';
    public const GATE_INTEGRATION_IN_PROGRESS  = 'integration_in_progress';
    public const GATE_INTEGRATION_ERROR        = 'integration_error';
    public const GATE_ALREADY_DELIVERED        = 'already_delivered';

    // Motivos que bloquean el cierre administrativo.
    public const BLOCK_STATE              = 'state_not_delivered';
    public const BLOCK_LEGACY             = 'legacy_definition';
    public const BLOCK_NO_UNITS           = 'no_units';
    public const BLOCK_RECEIPT_PENDING    = 'receipt_pending';
    public const BLOCK_UNITS_NOT_DELIVERED = 'units_not_delivered';
    public const BLOCK_INVENTORY_NOT_DONE = 'inventory_not_done';
    public const BLOCK_INTEGRITY          = 'integrity_not_clean';

    /** Estados físicos de una unidad (mismos literales que `ReceiptUnit`; el selftest lo verifica). */
    public const PHYSICAL_RECEIVED  = 'RECEIVED';
    public const PHYSICAL_DELIVERED = 'DELIVERED';
    /** Estado DONE del outbox (mismo literal que `OutboxEntry::STATUS_DONE`; el selftest lo verifica). */
    public const OUTBOX_DONE = 'DONE';

    /**
     * Lista de `receipt_unit_uuid` validada y en ORDEN ESTABLE (minúsculas, únicos, 1..$max). FAIL-CLOSED.
     *
     * @return array<int,string>
     * @throws \InvalidArgumentException
     */
    public static function normalizeUuids(mixed $raw, int $max): array
    {
        if (!is_array($raw) || $raw === []) {
            throw new \InvalidArgumentException('la entrega no tiene unidades');
        }
        $out = [];
        foreach ($raw as $u) {
            if (!is_string($u)) {
                throw new \InvalidArgumentException('identificador de unidad inválido');
            }
            $u = strtolower(trim($u));
            if (preg_match(HandoffPayload::UUID_PATTERN, $u) !== 1) {
                throw new \InvalidArgumentException('identificador de unidad inválido');
            }
            if (isset($out[$u])) {
                throw new \InvalidArgumentException('unidad repetida en la entrega');
            }
            $out[$u] = true;
        }
        if (count($out) > max(1, $max)) {
            throw new \InvalidArgumentException('una entrega admite a lo sumo ' . max(1, $max) . ' unidades (fail-closed)');
        }
        $list = array_keys($out);
        sort($list, SORT_STRING);
        return $list;
    }

    /** Huella canónica de la ENTRADA de una entrega (para replay exacto vs. conflicto de clave). @param array<int,string> $uuids */
    public static function inputHash(int $requestId, array $uuids, int $recipientUserId, string $notes): string
    {
        sort($uuids, SORT_STRING);
        return hash('sha256', json_encode([
            'notes'        => $notes,
            'recipient'    => $recipientUserId,
            'request_id'   => $requestId,
            'units'        => array_values($uuids),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Gate de inventario de UNA unidad. No inventariable ⇒ entregable (no hay handoff). Inventariable ⇒ sólo con el
     * handoff DONE; PENDING/RETRY o sin fila ⇒ inventario pendiente; LEASED ⇒ integración en curso; ERROR o un
     * estado desconocido ⇒ error de integración (fail-closed).
     */
    public static function gateStatus(bool $inventoriable, ?string $outboxStatus): string
    {
        if (!$inventoriable) {
            return self::GATE_DELIVERABLE;
        }
        return match ($outboxStatus) {
            self::OUTBOX_DONE  => self::GATE_DELIVERABLE,
            null, 'PENDING', 'RETRY' => self::GATE_INVENTORY_PENDING,
            'LEASED'           => self::GATE_INTEGRATION_IN_PROGRESS,
            default            => self::GATE_INTEGRATION_ERROR,
        };
    }

    /**
     * Motivos que BLOQUEAN el cierre (vacío ⇒ se puede cerrar). Requisitos: motor en DELIVERED con la acción `close`
     * en su versión; al menos una unidad; ninguna recepción pendiente; TODAS las unidades entregadas; inventariables
     * con handoff DONE; integridad de aprobación limpia.
     *
     * @param array<int,array{ordered_qty:int, received_qty:int}> $lines
     * @param array<int,array{physical_state:string, is_inventoriable:int|bool, outbox_status:?string}> $units
     * @return array<int,string>
     */
    public static function closeBlockers(array $lines, array $units, string $engineState, bool $hasCloseAction, bool $integrityClean): array
    {
        $out = [];
        if ($engineState !== PurchasingWorkflow::S_DELIVERED) {
            $out[] = self::BLOCK_STATE;
        } elseif (!$hasCloseAction) {
            $out[] = self::BLOCK_LEGACY;
        }
        if ($units === []) {
            $out[] = self::BLOCK_NO_UNITS;
        }
        if ($lines === []) {
            $out[] = self::BLOCK_RECEIPT_PENDING;
        }
        foreach ($lines as $l) {
            if ((int) ($l['ordered_qty'] ?? 0) < 1 || (int) ($l['received_qty'] ?? 0) !== (int) ($l['ordered_qty'] ?? 0)) {
                $out[] = self::BLOCK_RECEIPT_PENDING;
                break;
            }
        }
        foreach ($units as $u) {
            if ((string) ($u['physical_state'] ?? '') !== self::PHYSICAL_DELIVERED) {
                $out[] = self::BLOCK_UNITS_NOT_DELIVERED;
                break;
            }
        }
        foreach ($units as $u) {
            if ((bool) ($u['is_inventoriable'] ?? false) && ($u['outbox_status'] ?? null) !== self::OUTBOX_DONE) {
                $out[] = self::BLOCK_INVENTORY_NOT_DONE;
                break;
            }
        }
        if (!$integrityClean) {
            $out[] = self::BLOCK_INTEGRITY;
        }
        return array_values(array_unique($out));
    }

    /** Prefijo abreviado y NO sensible de un UUID para la UI (8 hex). */
    public static function shortUuid(string $uuid): string
    {
        return substr($uuid, 0, 8);
    }
}
