<?php

/**
 * FINALIZADOR durable de SI4-3 (ADR-0022 §7): cierra el hueco "ack aplicado, proceso caído antes de COMPLETED".
 *
 * Recorre las sagas en QR_READY y consulta el outbox por la API pública de Compras (`getHandoff`). Sólo si la fila
 * está DONE (y es el mismo payload que procesó la saga) pasa la saga a COMPLETED con `SagaStore::complete()` (guarda
 * `state = QR_READY`, SIN depender del lease viejo: el outbox ya cerró). NUNCA marca COMPLETED con el outbox en otro
 * estado. Idempotente y sin efectos externos (no llama a Snipe, GLPI ni companyqr).
 *
 * Lo ejecuta `Si4Worker` al inicio de cada corrida (antes de reclamar) y justo después de cada ack exitoso.
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

    private SagaStore $sagas;
    private HandoffSource $source;
    /** @var callable(string,string,array<string,mixed>):void */
    private $logger;

    /** @param callable(string,string,array<string,mixed>):void|null $logger */
    public function __construct(SagaStore $sagas, HandoffSource $source, ?callable $logger = null)
    {
        $this->sagas = $sagas;
        $this->source = $source;
        $this->logger = $logger ?? static function (): void {};
    }

    /** @return array{scanned:int, completed:int, pending:int, skipped:int} */
    public function run(int $limit = 500): array
    {
        $m = ['scanned' => 0, self::F_COMPLETED => 0, self::F_PENDING => 0, self::F_SKIPPED => 0];
        foreach ($this->sagas->listByState(SagaState::QR_READY, $limit) as $uuid) {
            $m['scanned']++;
            $m[$this->finalizeOne($uuid)]++;
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
