<?php

/**
 * ¿La sesión puede REABRIR una aprobación si su mutación la vuelve obsoleta?
 *
 * Una mutación sustantiva posterior a una aprobación (precio/selección de cotización, cantidad) puede exigir
 * `WorkflowApi::invalidateApprovals()` (derecho `RIGHT_ACT` del motor) y registrar la nueva versión del
 * scope en Firma (`RIGHT_RECORD`). Se exige ANTES de confirmar la mutación: nunca se acepta un cambio de un
 * perfil incapaz de reabrir la aprobación que invalida. Fail-closed.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

use Session;

final class ReopenCapability
{
    /** Bits de los plugins propios (literales: este archivo no depende de ellos al cargarse). */
    public const WORKFLOW_RIGHTNAME  = 'plugin_companyworkflow';
    public const WORKFLOW_RIGHT_ACT  = 2;
    public const SIGNATURE_RIGHTNAME = 'plugin_companysignature';
    public const SIGNATURE_RECORD    = 2;

    public static function has(): bool
    {
        return Session::haveRight(self::WORKFLOW_RIGHTNAME, self::WORKFLOW_RIGHT_ACT)
            && Session::haveRight(self::SIGNATURE_RIGHTNAME, self::SIGNATURE_RECORD);
    }

    /** @throws \RuntimeException */
    public static function assert(): void
    {
        if (!self::has()) {
            throw new \RuntimeException('el perfil no puede reabrir aprobaciones (RIGHT_ACT del motor + registro en Firma): cambio rechazado (fail-closed)');
        }
    }
}
