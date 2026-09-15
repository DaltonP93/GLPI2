<?php

/**
 * Transporte HTTP real basado en cURL. TLS SIEMPRE verificado (no se puede desactivar).
 * No registra el token (el saneado de logs se aplica en capas superiores).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Client;

final class CurlTransport implements HttpTransport
{
    public function send(string $method, string $url, array $headers, ?string $body, int $timeoutMs): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new HttpTransportException('cURL no disponible');
        }
        $ch = curl_init();
        if ($ch === false) {
            throw new HttpTransportException('No se pudo inicializar cURL');
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => max(1, $timeoutMs),
            CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) min($timeoutMs, 10000)),
            // TLS obligatorio (NUNCA desactivar la verificación).
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$respHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $respHeaders[trim($parts[0])] = trim($parts[1]);
                }
                return $len;
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new HttpTransportException('cURL: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new HttpResponse($status, $respHeaders, is_string($out) ? $out : '');
    }
}
