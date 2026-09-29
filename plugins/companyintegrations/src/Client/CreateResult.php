<?php

/**
 * Resultado CLASIFICADO de un `POST /api/v1/hardware` (SI4-1, ADR-0020). Value object sin dependencias de GLPI.
 *
 * El POST NO es idempotente en Snipe: el llamador decide según `kind` y jamás repite el POST a ciegas.
 *   CREATED     éxito verificado (id > 0 y el tag devuelto es el enviado)
 *   VALIDATION  `status:"error"` con campos (`fields`): p. ej. asset_tag ya tomado, serial repetido, model inexistente
 *   CONFLICT    HTTP 409 (Snipe v8.7.2 no lo emite para duplicados; puede venir de un proxy/versión futura)
 *   AUTH        401/403 (credencial/permiso/User-Agent bloqueado) — fail-closed
 *   RATE_LIMIT  429: rechazado ANTES del controlador (sin efecto) → reintentable (`retryAfterSec`)
 *   UNCERTAIN   timeout / transporte / 5xx / respuesta ilegible: el activo PUDO crearse → buscar antes de reintentar
 *   REJECTED    otro 4xx o `status:"error"` sin campos: no reintentable
 *   CIRCUIT     circuit breaker abierto: no se envió nada
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class CreateResult
{
    public const CREATED    = 'created';
    public const VALIDATION = 'validation';
    public const CONFLICT   = 'conflict';
    public const AUTH       = 'auth';
    public const RATE_LIMIT = 'rate_limit';
    public const UNCERTAIN  = 'uncertain';
    public const REJECTED   = 'rejected';
    public const CIRCUIT    = 'circuit';

    public string $kind;
    public int $assetId;
    public int $httpStatus;
    /** @var array<int,string> */
    public array $fields;
    public ?int $retryAfterSec;
    public string $detail;

    /** @param array<int,string> $fields */
    public function __construct(string $kind, int $httpStatus = 0, int $assetId = 0, array $fields = [], ?int $retryAfterSec = null, string $detail = '')
    {
        $this->kind          = $kind;
        $this->httpStatus    = $httpStatus;
        $this->assetId       = $assetId;
        $this->fields        = $fields;
        $this->retryAfterSec = $retryAfterSec;
        $this->detail        = $detail;
    }

    public function hasField(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }
}
