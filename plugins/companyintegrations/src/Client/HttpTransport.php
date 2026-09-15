<?php

/**
 * Contrato de transporte HTTP (inyectable → permite tests de contrato sin socket ni GLPI).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

interface HttpTransport
{
    /**
     * Ejecuta una petición HTTP. Debe lanzar HttpTransportException ante timeout/red/TLS.
     *
     * @param array<string,string> $headers
     * @throws HttpTransportException
     */
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): HttpResponse;
}
