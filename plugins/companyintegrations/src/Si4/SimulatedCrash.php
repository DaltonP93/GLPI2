<?php

/**
 * Muerte SIMULADA del proceso en un punto exacto de la saga (sólo tests: la sonda del worker es null en producción).
 * Extiende `\Error` a propósito: el worker sólo atrapa `\Exception`, así que nada la "absorbe" y el efecto es el de
 * un proceso que cae (sin settle, sin escrituras posteriores; el lease vence).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class SimulatedCrash extends \Error
{
}
