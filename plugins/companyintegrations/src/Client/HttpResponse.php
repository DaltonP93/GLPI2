<?php

/**
 * Respuesta HTTP inmutable (DTO). Sin dependencias de GLPI.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class HttpResponse
{
    public int $status;
    /** @var array<string,string> */
    public array $headers;
    public string $body;

    /** @param array<string,string> $headers */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status  = $status;
        // Normaliza claves de header a minúsculas.
        $norm = [];
        foreach ($headers as $k => $v) {
            $norm[strtolower((string) $k)] = (string) $v;
        }
        $this->headers = $norm;
        $this->body    = $body;
    }

    /** @return array<string,mixed>|null */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
