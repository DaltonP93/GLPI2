<?php

/**
 * Implementación por defecto del puerto de firma certificada: **no-op documentado** (gate §8/§13).
 *
 * No firma ni verifica nada: en Fase 2 no hay proveedor ni PKI. Su existencia deja el punto de
 * integración listo sin introducir dependencias criptográficas externas ni confundir la evidencia
 * interna con una firma certificada.
 *
 * Clase PURA (sin dependencias de GLPI).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

final class NullSigner implements CertifiedSignerInterface
{
    public function sign(string $payload): SignatureResult
    {
        return SignatureResult::none('NullSigner: sin proveedor de firma certificada en Fase 2 (no-op).');
    }

    public function verify(string $payload, string $signature): bool
    {
        return false;
    }

    public function provider(): string
    {
        return 'null';
    }
}
