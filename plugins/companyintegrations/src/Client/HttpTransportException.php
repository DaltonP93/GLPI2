<?php

/**
 * Error de transporte HTTP (timeout, DNS, TLS, conexión). Sin dependencias de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class HttpTransportException extends \RuntimeException
{
}
