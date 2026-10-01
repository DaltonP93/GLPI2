<?php

/**
 * Error de companyqr visto por SI4-3. `kind` (mismos valores que `CompanyQrException` + `unavailable`):
 *   acl / unavailable ⇒ BLOCKED_CONFIG (se destraba otorgando derechos o activando companyqr)
 *   render            ⇒ BLOCKED_CONFIG (configuración de etiqueta; nada irreversible ocurrió)
 *   not_found / inactive / invalid ⇒ MANUAL_REVIEW
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class QrGatewayException extends \RuntimeException
{
    public const ACL         = 'acl';
    public const NOT_FOUND   = 'not_found';
    public const INACTIVE    = 'inactive';
    public const RENDER      = 'render';
    public const INVALID     = 'invalid';
    public const UNAVAILABLE = 'unavailable';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }

    /** ¿Se destraba configurando (derechos, plugin, etiqueta)? */
    public function isConfig(): bool
    {
        return in_array($this->kind, [self::ACL, self::UNAVAILABLE, self::RENDER], true);
    }
}
