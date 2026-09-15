<?php

/**
 * Colisión de versión al publicar una definición (dos publicaciones concurrentes del mismo code
 * calcularon la misma versión). Señaliza un reintento controlado; no es un error terminal.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyworkflow\Service;

final class VersionCollisionException extends \RuntimeException
{
}
