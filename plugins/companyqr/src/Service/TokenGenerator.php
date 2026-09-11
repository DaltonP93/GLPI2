<?php

/**
 * Generador de tokens opacos para companyqr.
 *
 * El token IDENTIFICA (evita enumeración trivial); NO autentica ni autoriza.
 * Ver docs/adr/ADR-0011-companyqr.md y docs/security/security-baseline.md.
 *
 * Clase PURA (sin dependencias de GLPI) para poder testearla sin bootstrap.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

final class TokenGenerator
{
    /** Alfabeto base32 (RFC 4648, en minúsculas, URL-safe, sin caracteres ambiguos de padding). */
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    /**
     * Genera un token aleatorio opaco.
     *
     * @param int $bytes Bytes de entropía (mínimo 16 = 128 bits). Por defecto 20 (160 bits).
     * @return string Token base32 en minúsculas, seguro para URL/QR.
     */
    public function generate(int $bytes = 20): string
    {
        $bytes = max(16, $bytes);
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Valida el formato de un token (charset y longitud mínima), sin tocar base de datos.
     */
    public static function isWellFormed(string $token): bool
    {
        // 16 bytes -> 26 chars base32; damos margen inferior razonable.
        return $token !== '' && strlen($token) >= 24 && preg_match('/^[a-z2-7]+$/', $token) === 1;
    }

    /**
     * Codificación base32 (RFC 4648) sin padding, en minúsculas.
     */
    private static function base32Encode(string $data): string
    {
        $out = '';
        $value = 0;
        $bits = 0;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $value = ($value << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $out .= self::ALPHABET[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $out .= self::ALPHABET[($value << (5 - $bits)) & 31];
        }

        return $out;
    }
}
