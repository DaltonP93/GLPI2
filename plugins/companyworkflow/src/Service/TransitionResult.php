<?php

/**
 * Resultado inmutable de un intento de transición. Lógica PURA (unit-testable).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

final class TransitionResult
{
    // Códigos de resultado (fail-closed por defecto).
    public const OK               = 'ok';
    public const RECORDED         = 'recorded';        // voto registrado, aún sin quórum
    public const DENIED_ACL       = 'denied_acl';
    public const DENIED_ENTITY    = 'denied_entity';
    public const INVALID_ACTION   = 'invalid_action';
    public const CONDITION_FAILED = 'condition_failed';
    public const COMMENT_REQUIRED = 'comment_required';
    public const CONFLICT_VERSION = 'conflict_version'; // control optimista (versión antigua)
    public const DUPLICATE        = 'duplicate';        // doble voto/replay del mismo actor
    public const CLOSED           = 'closed';           // instancia no abierta
    public const ERROR            = 'error';

    public bool $success;
    public string $code;
    public string $message;
    /** @var array<string,mixed> */
    public array $data;

    /** @param array<string,mixed> $data */
    public function __construct(bool $success, string $code, string $message = '', array $data = [])
    {
        $this->success = $success;
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    /** @param array<string,mixed> $data */
    public static function ok(string $code = self::OK, string $message = '', array $data = []): self
    {
        return new self(true, $code, $message, $data);
    }

    /** @param array<string,mixed> $data */
    public static function fail(string $code, string $message = '', array $data = []): self
    {
        return new self(false, $code, $message, $data);
    }
}
