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
     *                                        last_error, last_error_class; SI4-2: glpi_itemtype, glpi_items_id,
     *                                        glpi_entity_id, glpi_outcome, glpi_create_calls, glpi_infocom_id,
     *                                        infocom_outcome, asset_bridge_id, resume_state; pin del destino GLPI
     *                                        (`GlpiMappingRules::PIN_COLUMNS`): las cuatro juntas y SÓLO si la saga
     *                                        aún no tiene pin (si ya lo tiene ⇒ false, nada escrito)
     * @throws \RuntimeException violación de unicidad (snipe_asset_id o activo GLPI ya ligado a otra unidad)
     * @throws \InvalidArgumentException pin incompleto
     */
    public function transition(string $uuid, string $tokenSha256, string $fromState, array $set, string $event, string $detail = '', int $minRemainingSeconds = 0): bool;

    /**
     * ¿Este worker SIGUE siendo el dueño con al menos `$minRemainingSeconds` de lease (reloj de la BD)? Sin escribir.
     * Se consulta antes de cada escritura en GLPI que no persiste una intención propia (SI4-2, ADR-0021 §10).
     */
    public function holds(string $uuid, string $tokenSha256, int $minRemainingSeconds): bool;

    /** @return array<string,mixed>|null */
    public function get(string $uuid): ?array;
}
