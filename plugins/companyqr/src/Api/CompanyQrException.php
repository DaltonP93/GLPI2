<?php

/**
 * Error tipado de `CompanyQrApi`. `kind` permite al consumidor decidir sin parsear mensajes:
 *   acl        el usuario de la sesión no tiene el derecho del plugin o no ve el activo (ACL nativa de GLPI)
 *   not_found  el activo o el código no existen
 *   inactive   el código no está ACTIVO (revocado/suspendido): no se imprime
 *   render     la etiqueta no pudo generarse
 *   invalid    argumentos inválidos (itemtype no es un CommonDBTM, id <= 0)
 * El mensaje nunca incluye el token.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Api;

final class CompanyQrException extends \RuntimeException
{
    public const ACL       = 'acl';
    public const NOT_FOUND = 'not_found';
    public const INACTIVE  = 'inactive';
    public const RENDER    = 'render';
    public const INVALID   = 'invalid';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
