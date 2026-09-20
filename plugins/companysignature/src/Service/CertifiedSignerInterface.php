<?php

/**
 * PUERTO de firma digital certificada (gate §8/§13). Sólo un contrato: **sin proveedor ni PKI**
 * en Fase 2. Se integra un adaptador real (HSM, proveedor cualificado, etc.) cuando negocio/legal
 * lo exijan, con su propio ADR de seguimiento.
 *
 * Regla dura: esto NO es la evidencia de aprobación electrónica interna (identidad + hash +
 * timestamp + auditoría), y una imagen/dibujo de firma **jamás** es una firma digital certificada.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

interface CertifiedSignerInterface
{
    /** Firma un payload (bytes canónicos). En Fase 2 el único proveedor es `NullSigner` (no-op). */
    public function sign(string $payload): SignatureResult;

    /** Verifica una firma certificada previa. En Fase 2 devuelve siempre `false` (no hay proveedor). */
    public function verify(string $payload, string $signature): bool;

    /** Identificador del proveedor (p. ej. "null"). */
    public function provider(): string;
}
