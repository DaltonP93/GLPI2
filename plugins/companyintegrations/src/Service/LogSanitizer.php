<?php

/**
 * Saneado de logs: NUNCA debe aparecer un token/credencial en logs ni auditoría. PURO.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

final class LogSanitizer
{
    private const REDACTION = '***REDACTED***';

    /** Claves cuyo valor siempre se redacta. */
    private const SENSITIVE_KEYS = ['authorization', 'token', 'secret', 'password', 'api_key', 'api-key', 'apikey', 'x-api-token'];

    public function redact(string $text): string
    {
        // Bearer <token>
        $text = preg_replace('/\bBearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ' . self::REDACTION, $text) ?? $text;
        // Authorization: ...
        $text = preg_replace('/(Authorization\s*[:=]\s*)\S+/i', '$1' . self::REDACTION, $text) ?? $text;
        // token=... / "token":"..."
        $text = preg_replace('/("?(?:token|api[_-]?key|secret|password)"?\s*[:=]\s*"?)[^"\s,&]+/i', '$1' . self::REDACTION, $text) ?? $text;
        return $text;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function redactArray(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $keyLc = strtolower((string) $k);
            $isSensitive = false;
            foreach (self::SENSITIVE_KEYS as $sk) {
                if (str_contains($keyLc, $sk)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $out[$k] = self::REDACTION;
            } elseif (is_array($v)) {
                $out[$k] = $this->redactArray($v);
            } elseif (is_string($v)) {
                $out[$k] = $this->redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
