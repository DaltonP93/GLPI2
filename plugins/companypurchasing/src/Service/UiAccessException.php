<?php

/**
 * Rechazo TIPADO de lectura en la UI (P2D-4; ADR-0023 §8): la capa HTTP lo traduce a 404 / 403 por su tipo, nunca
 * comparando el texto del mensaje.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class UiAccessException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    public const DENIED    = 'denied';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'solicitud inexistente');
    }

    public static function denied(): self
    {
        return new self(self::DENIED, 'sin permiso para leer la solicitud');
    }
}
