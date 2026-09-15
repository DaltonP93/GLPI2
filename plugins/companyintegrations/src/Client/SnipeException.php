<?php

/**
 * Excepción del cliente Snipe-IT con un `kind` clasificado. Sin dependencias de GLPI.
 * El mensaje NUNCA debe contener el token (se sanea en capas superiores antes de loguear).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class SnipeException extends \RuntimeException
{
    public const AUTH         = 'auth';
    public const CONFLICT     = 'conflict';
    public const RATE_LIMIT   = 'rate_limit';
    public const TRANSPORT    = 'transport';
    public const CIRCUIT_OPEN = 'circuit_open';
    public const CLIENT       = 'client';

    public string $kind;
    public string $correlationId;

    public function __construct(string $kind, string $message, string $correlationId = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->kind          = $kind;
        $this->correlationId = $correlationId;
    }
}
