<?php

/**
 * Auditoría de NEGOCIO append-only (tabla `..._events`). Sin secretos, con `correlation_id`.
 *
 * No sustituye al `Log` nativo ni al ledger probatorio de `companyworkflow`; registra el espejo
 * semántico del dominio de compras (creación y mutaciones relevantes del borrador en P2D-1).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;
use GlpiPlugin\Companypurchasing\Model\PurchasingEvent;

// No es `final`: los tests deterministas de crash-safety pueden subclasearla para forzar el fallo del
// registro del evento de submit (verificar que la solicitud NO queda PENDING si el evento no persiste).
class Audit
{
    /** Claves que NUNCA se registran (evita filtrar secretos por accidente). */
    private const REDACT = ['password', 'passwd', 'token', 'secret', 'api_key', 'apikey', 'authorization'];

    /**
     * Registra un evento de negocio. FAIL-CLOSED: comprueba el resultado de `PurchasingEvent::add()` y
     * LANZA si no pudo persistir (un evento de auditoría perdido es un problema real). `idempotencyKey`
     * (opcional) fija la identidad idempotente durable del evento (UNIQUE en la tabla).
     *
     * @param array<string,mixed> $detail
     * @throws \RuntimeException
     */
    public function record(int $requestsId, string $event, int $entitiesId, array $detail = [], string $correlationId = '', ?string $idempotencyKey = null): void
    {
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $input = [
            'requests_id'    => $requestsId,
            'event'          => substr($event, 0, 60),
            'actor_users_id' => (int) (Session::getLoginUserID() ?: 0),
            'entities_id'    => $entitiesId,
            'correlation_id' => substr($correlationId, 0, 64),
            'detail'         => json_encode($this->sanitize($detail), JSON_UNESCAPED_UNICODE),
            'date_creation'  => $now,
        ];
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $input['idempotency_key'] = substr($idempotencyKey, 0, 190);
        }
        $eventId = (int) (new PurchasingEvent())->add($input);
        if ($eventId <= 0) {
            throw new \RuntimeException('no se pudo persistir el evento de auditoría: ' . $event);
        }
    }

    /**
     * Registro IDEMPOTENTE por `idempotencyKey` (obligatoria): si el evento ya existe no se duplica ni se
     * falla (reintentos de la saga P2D-2). Ante carrera con el UNIQUE, se re-verifica; cualquier otro fallo
     * de persistencia LANZA (fail-closed). Devuelve true si lo registró ahora.
     *
     * @param array<string,mixed> $detail
     */
    public function recordOnce(int $requestsId, string $event, int $entitiesId, array $detail, string $correlationId, string $idempotencyKey): bool
    {
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('recordOnce exige idempotency_key');
        }
        if ($this->exists($idempotencyKey)) {
            return false;
        }
        try {
            $this->record($requestsId, $event, $entitiesId, $detail, $correlationId, $idempotencyKey);
            return true;
        } catch (\Throwable $e) {
            if ($this->exists($idempotencyKey)) {
                return false; // otro worker lo registró en paralelo: idempotente
            }
            throw $e;
        }
    }

    public function exists(string $idempotencyKey): bool
    {
        return (new PurchasingEvent())->getFromDBByCrit(['idempotency_key' => substr($idempotencyKey, 0, 190)]);
    }

    /**
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    private function sanitize(array $detail): array
    {
        $out = [];
        foreach ($detail as $k => $v) {
            $key = strtolower((string) $k);
            if (in_array($key, self::REDACT, true)) {
                $out[$k] = '[redacted]';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = $this->sanitize($v);
            } elseif (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            } else {
                $out[$k] = '[object]';
            }
        }
        return $out;
    }
}
