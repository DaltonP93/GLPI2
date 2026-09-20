<?php

/**
 * Resultado inmutable de una operación de FIRMA DIGITAL CERTIFICADA (puerto, no evidencia interna).
 *
 * Separación estricta (gate §8): una evidencia de aprobación electrónica interna (identidad + hash +
 * timestamp + auditoría) **no** es una firma digital certificada. Este value object modela el
 * resultado del puerto certificado; en Fase 2 sólo existe `NullSigner` (no-op), por lo que
 * `certified` es siempre `false`.
 *
 * Clase PURA (sin dependencias de GLPI).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

final class SignatureResult
{
    public bool $certified;
    public string $provider;
    public string $signature;
    public string $message;

    public function __construct(bool $certified, string $provider, string $signature = '', string $message = '')
    {
        $this->certified = $certified;
        $this->provider  = $provider;
        $this->signature = $signature;
        $this->message   = $message;
    }

    /** Resultado "no certificado" (no hay proveedor/PKI en Fase 2). */
    public static function none(string $message = ''): self
    {
        return new self(false, 'null', '', $message);
    }
}
