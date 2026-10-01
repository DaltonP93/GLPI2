<?php

/**
 * FINALIZADOR durable de SI4-3 (ADR-0022 §6): cierra el hueco "ack aplicado, proceso caído antes de COMPLETED".
 *
 * Recorrido ROUND-ROBIN ACOTADO Y DURABLE de las sagas en QR_READY:
 *   - cada corrida inspecciona a lo sumo `$batch` sagas con `id > cursor` (orden de `id`);
 *   - el cursor persistente (`FinalizerCursor`, tabla propia) pasa a la ÚLTIMA saga INSPECCIONADA, se haya completado
 *     o no (outbox no DONE, handoff ilegible o no visible, hash distinto): una saga pendiente NUNCA bloquea las
 *     posteriores;
 *   - al llegar al final (lote incompleto o nada después del cursor) vuelve a 0 (wrap-around): las sagas antiguas se
 *     reconsideran en la ronda siguiente cuando su outbox cambie a DONE (nunca se dan por abandonadas);
 *   - concurrencia: el cursor avanza por compare-and-set; dos corridas pueden inspeccionar la misma saga, y
 *     `complete()` (guarda `state = QR_READY`) es la barrera exactamente-una-vez.
 * Garantía: inspección al-menos-una-vez eventual de cada saga QR_READY + transición exactamente-una-vez.
 *
 * Por cada saga consulta el outbox por la API pública de Compras (`getHandoff`). Sólo si la fila está DONE (y es el
 * mismo payload que procesó la saga) la pasa a COMPLETED con `SagaStore::complete()` (SIN depender del lease viejo: el
 * outbox ya cerró). NUNCA marca COMPLETED con el outbox en otro estado. Sin efectos externos (no llama a Snipe, GLPI
 * ni companyqr; nunca lee tablas de Compras).
 *
 * Lo ejecuta `Si4Worker` al inicio de cada corrida (antes de reclamar) y `finalizeOne()` justo después de cada ack.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class Si4Finalizer
{
    /** Estado DONE del outbox de Compras (`OutboxEntry::STATUS_DONE`; el selftest verifica que coincide). */
    public const OUTBOX_DONE = 'DONE';

    public const F_COMPLETED = 'completed';
    public const F_PENDING   = 'pending';
    public const F_SKIPPED   = 'skipped';

    /** Sagas inspeccionadas por corrida (cota del trabajo de cada pasada). */
    public const DEFAULT_BATCH = 200;

    private SagaStore $sagas;
    private HandoffSource $source;
    private FinalizerCursor $cursor;
    private int $batch;
    /** @var callable(string,string,array<string,mixed>):void */
    private $logger;

    /** @param callable(string,string,array<string,mixed>):void|null $logger */
    public function __construct(SagaStore $sagas, HandoffSource $source, FinalizerCursor $cursor, ?callable $logger = null, int $batch = self::DEFAULT_BATCH)
    {
        $this->sagas = $sagas;
        $this->source = $source;
        $this->cursor = $cursor;
        $this->batch = max(1, min(1000, $batch));
        $this->logger = $logger ?? static function (): void {};
    }

    /**
     * Una pasada acotada del recorrido round-robin (ver cabecera).
     *
     * @return array{scanned:int, completed:int, pending:int, skipped:int, cursor_from:int, cursor_to:int, wrapped:bool}
     */
    public function run(): array
    {
        $m = ['scanned' => 0, self::F_COMPLETED => 0, self::F_PENDING => 0, self::F_SKIPPED => 0, 'cursor_from' => 0, 'cursor_to' => 0, 'wrapped' => false];
        $from = $this->cursor->get();
        $m['cursor_from'] = $from;
        $rows = $this->sagas->listByStateAfter(SagaState::QR_READY, $from, $this->batch);
        if ($rows === [] && $from > 0) {
            // Nada después del cursor: fin de la ronda ⇒ wrap-around en la MISMA corrida.
            $m['wrapped'] = true;
            $rows = $this->sagas->listByStateAfter(SagaState::QR_READY, 0, $this->batch);
        }
        $last = 0;
        foreach ($rows as $row) {
            $last = (int) $row['id'];
            $m['scanned']++;
            $m[$this->finalizeOne((string) $row['receipt_unit_uuid'])]++;
        }
        // El cursor es la ÚLTIMA saga INSPECCIONADA (completada o no). Lote incompleto ⇒ se llegó al final ⇒ la próxima
        // corrida empieza otra ronda desde 0 (wrap-around).
        $next = count($rows) < $this->batch ? 0 : $last;
        $m['cursor_to'] = $next;
        if (!$this->cursor->advance($from, $next)) {
            ($this->logger)('info', 'finalizador SI-4: otra corrida movió el cursor (no se pisa)', ['cursor_from' => $from, 'cursor_to' => $next]);
        }
        if ($m['scanned'] > 0) {
            ($this->logger)('info', 'finalizador SI-4: sagas QR_READY revisadas', $m);
        }
        return $m;
    }

    /** @return string F_COMPLETED | F_PENDING (outbox aún no DONE) | F_SKIPPED (no visible, inválido o ya no QR_READY) */
    public function finalizeOne(string $uuid): string
    {
        $saga = $this->sagas->get($uuid);
        if ($saga === null || (string) $saga['state'] !== SagaState::QR_READY) {
            return ($saga['state'] ?? '') === SagaState::COMPLETED ? self::F_COMPLETED : self::F_SKIPPED;
        }
        $ctx = ['receipt_unit_uuid' => $uuid, 'correlation_id' => (string) ($saga['correlation_id'] ?? '')];
        try {
            $h = $this->source->getHandoff($uuid);
        } catch (\Exception $e) {
            ($this->logger)('error', 'finalizador: handoff ilegible', $ctx + ['error' => Si4Errors::sanitize($e->getMessage())]);
            return self::F_SKIPPED;
        }
        if ($h === null) {
            ($this->logger)('warning', 'finalizador: handoff no visible para la sesión', $ctx);
            return self::F_SKIPPED;
        }
        if (!hash_equals((string) ($saga['payload_sha256'] ?? ''), (string) ($h['payload_sha256'] ?? ''))) {
            ($this->logger)('error', 'finalizador: el handoff no es el procesado por la saga (hash distinto): no se completa', $ctx);
            return self::F_SKIPPED;
        }
        if ((string) ($h['status'] ?? '') !== self::OUTBOX_DONE) {
            return self::F_PENDING; // nunca COMPLETED con el outbox sin confirmar
        }
        if ($this->sagas->complete($uuid, 'outbox DONE (finalizador)')) {
            ($this->logger)('info', 'unidad COMPLETED: outbox DONE y saga cerrada (SI-4 completo)', $ctx + [
                'qr_code_id' => (int) ($saga['qr_code_id'] ?? 0), 'asset_bridge_id' => (int) ($saga['asset_bridge_id'] ?? 0),
            ]);
            return self::F_COMPLETED;
        }
        return (($this->sagas->get($uuid) ?? [])['state'] ?? '') === SagaState::COMPLETED ? self::F_COMPLETED : self::F_SKIPPED;
    }
}
