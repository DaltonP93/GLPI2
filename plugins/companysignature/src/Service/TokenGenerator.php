<?php

/**
 * Generador de `verification_token` opacos para companysignature (gate §13).
 *
 * El token IDENTIFICA una evidencia; **no** autentica ni autoriza (eso lo hace GLPI: login + ACL +
 * entidad). Propiedades exigidas: **opaco, aleatorio, no secuencial y NO derivado del hash** del
 * contenido (para no filtrar el `content_sha256` ni permitir enumeración).
 *
 * Clase PURA (sin dependencias de GLPI) para poder testearla sin bootstrap. Mismo patrón base32
 * que `companyqr/src/Service/TokenGenerator.php` (no se acopla la clase entre plugins — D3).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companysignature\Service;

final class TokenGenerator
{
    /** Alfabeto base32 (RFC 4648) en minúsculas, URL/QR-safe, sin ambigüedades de padding. */
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    /**
     * Token aleatorio opaco.
     *
     * @param int $bytes Bytes de entropía (mínimo 20 = 160 bits). Por defecto 24 (192 bits).
     */
    public function generate(int $bytes = 24): string
    {
        $bytes = max(20, $bytes);
        return self::base32Encode(random_bytes($bytes));
    }

    /** Valida el formato (charset y longitud mínima) sin tocar base de datos. */
    public static function isWellFormed(string $token): bool
    {
        // 20 bytes -> 32 chars base32; damos margen inferior razonable.
        return $token !== '' && strlen($token) >= 32 && preg_match('/^[a-z2-7]+$/', $token) === 1;
    }

    /** Codificación base32 (RFC 4648) sin padding, en minúsculas. */
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
