<?php

/**
 * Transporte de PRUEBA (contract tests): devuelve respuestas programadas y registra las llamadas.
 * Permite simular 2xx/4xx/5xx/429 y timeouts (HttpTransportException) SIN socket ni Snipe real.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class ArrayTransport implements HttpTransport
{
    /** @var array<int,HttpResponse|HttpTransportException> cola de respuestas/errores */
    private array $queue;
    /** @var array<int,array{method:string,url:string}> llamadas registradas */
    public array $calls = [];

    /** @param array<int,HttpResponse|HttpTransportException> $queue */
    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): HttpResponse
    {
        $this->calls[] = ['method' => strtoupper($method), 'url' => $url];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new HttpTransportException('ArrayTransport: cola vacía para ' . $method . ' ' . $url);
        }
        if ($next instanceof HttpTransportException) {
            throw $next;
        }
        return $next;
    }

    /** ¿Todas las llamadas fueron de sólo lectura (GET/HEAD)? (garantía read-only de SI-1). */
    public function allReadOnly(): bool
    {
        foreach ($this->calls as $c) {
            if (!in_array($c['method'], ['GET', 'HEAD'], true)) {
                return false;
            }
        }
        return true;
    }
}
