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

final class Audit
{
    /** Claves que NUNCA se registran (evita filtrar secretos por accidente). */
    private const REDACT = ['password', 'passwd', 'token', 'secret', 'api_key', 'apikey', 'authorization'];

    /** @param array<string,mixed> $detail */
    public function record(int $requestsId, string $event, int $entitiesId, array $detail = [], string $correlationId = ''): void
    {
        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        (new PurchasingEvent())->add([
            'requests_id'    => $requestsId,
            'event'          => substr($event, 0, 60),
            'actor_users_id' => (int) (Session::getLoginUserID() ?: 0),
            'entities_id'    => $entitiesId,
            'correlation_id' => substr($correlationId, 0, 64),
            'detail'         => json_encode($this->sanitize($detail), JSON_UNESCAPED_UNICODE),
            'date_creation'  => $now,
        ]);
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
