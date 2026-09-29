<?php

/**
 * Persistencia DURABLE de la saga SI-4 por unidad con FENCING (ADR-0020 §5–§6).
 *
 * Dueño de la saga = sha256(lease_token) + `lease_until` + ÉPOCA del lease, copiados del claim del outbox. La época es
 * `attempts` del claim: Compras la incrementa en CADA toma y nunca la decrementa, así que es un token de fencing
 * MONÓTONO: una toma más nueva (época mayor) desplaza al dueño anterior; una más vieja nunca. Toda escritura es
 * condicionada: el mismo dueño y `lease_until >= ahora (+ margen)`, con el reloj ÚNICO de la BD en producción. Un
 * worker cuyo lease venció o fue re-tomado no puede escribir (devuelve false). `UNIQUE(receipt_unit_uuid)`: una sola
 * saga por unidad; `UNIQUE(snipe_asset_id)`: un activo remoto nunca queda ligado a dos unidades.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

interface SagaStore
{
    /**
     * Crea la saga (PENDING) o la TOMA para este lease si su época es MAYOR que la del dueño registrado.
     * Devuelve la fila vigente, o null si la época no es más nueva (claim viejo/concurrente: fail-closed).
     *
     * @param array{entities_id:int, requests_id:int, items_id:int, payload_sha256:string, correlation_id:string} $meta
     * @return array<string,mixed>|null
     */
    public function acquire(string $uuid, array $meta, string $tokenSha256, string $leaseUntil, int $epoch, string $workerId): ?array;

    /**
     * UPDATE condicionado al dueño y a `lease_until >= ahora + $minRemainingSeconds`; registra el evento en la bitácora
     * append-only en la misma transacción. false = lease perdido/insuficiente (no se escribió nada).
     *
     * @param array<string,scalar|null> $set  columnas: state, snipe_asset_id, snipe_asset_tag, snipe_outcome,
     *                                        snipe_company_id, snipe_model_id, snipe_status_id, remote_create_calls,
     *                                        last_error, last_error_class
     * @throws \RuntimeException violación de unicidad (p. ej. snipe_asset_id ya ligado a otra unidad)
     */
    public function transition(string $uuid, string $tokenSha256, string $fromState, array $set, string $event, string $detail = '', int $minRemainingSeconds = 0): bool;

    /** @return array<string,mixed>|null */
    public function get(string $uuid): ?array;
}
