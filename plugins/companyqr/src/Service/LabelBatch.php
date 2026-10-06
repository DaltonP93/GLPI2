<?php

/**
 * Lote de etiquetas para la impresión masiva (ADR-0024). Lógica PURA sobre un arreglo "almacén" (en producción,
 * `$_SESSION[LabelBatch::SESSION_KEY]`), así se prueba sin GLPI.
 *
 *  - La clave del lote es aleatoria (128 bits, 32 hex): no se puede adivinar ni enumerar.
 *  - Cada lote queda atado al usuario que lo creó y vence a los `TTL_SECONDS`.
 *  - Sólo guarda `code_id` (nunca tokens): el PDF se arma y se REVALIDA al pedirlo.
 *  - Como mucho `MAX_PENDING` lotes vivos por sesión (se descartan los más viejos).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyqr\Service;

final class LabelBatch
{
    public const SESSION_KEY = 'plugin_companyqr_label_batches';
    public const TTL_SECONDS = 900;
    public const MAX_PENDING = 5;

    /** Tope duro de etiquetas por lote, sea cual sea la configuración. */
    public const HARD_MAX = 500;
    public const DEFAULT_MAX = 200;

    /** Nueva clave de lote (32 caracteres hexadecimales). */
    public static function newKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isKeyWellFormed(string $key): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $key) === 1;
    }

    /** Tope efectivo a partir del valor configurado (texto libre): entero entre 1 y HARD_MAX. */
    public static function clampLimit(mixed $configured): int
    {
        $n = is_numeric($configured) ? (int) $configured : self::DEFAULT_MAX;
        return max(1, min(self::HARD_MAX, $n));
    }

    /**
     * Agrega códigos al lote `$key` (lo crea si no existe o venció). Sin duplicados, en el orden de llegada.
     * Si el lote existe pero es de OTRO usuario, no se toca y se crea uno nuevo.
     *
     * @param array<string,array{user:int, created:int, codes:list<int>}> $store
     * @param list<int> $codeIds
     * @return string clave del lote (la misma `$key` si se pudo reutilizar)
     */
    public static function append(array &$store, ?string $key, int $userId, array $codeIds, int $now): string
    {
        self::purge($store, $now);
        if ($key === null || !self::isKeyWellFormed($key) || !isset($store[$key]) || $store[$key]['user'] !== $userId) {
            $key = self::newKey();
            $store[$key] = ['user' => $userId, 'created' => $now, 'codes' => []];
        }
        $store[$key]['codes'] = self::uniqueIds(array_merge($store[$key]['codes'], $codeIds));
        self::trim($store, $key);
        return $key;
    }

    /**
     * Códigos del lote si existe, es del usuario y no venció; si no, `null`. No lo consume: se puede volver a abrir
     * (p. ej. reimprimir) mientras no venza.
     *
     * @param array<string,array{user:int, created:int, codes:list<int>}> $store
     * @return list<int>|null
     */
    public static function get(array $store, string $key, int $userId, int $now): ?array
    {
        if (!self::isKeyWellFormed($key) || !isset($store[$key])) {
            return null;
        }
        $batch = $store[$key];
        if ($batch['user'] !== $userId || self::expired($batch, $now)) {
            return null;
        }
        return $batch['codes'];
    }

    /** Cantidad de códigos ya encolados en el lote (0 si no existe). */
    public static function count(array $store, ?string $key): int
    {
        return ($key !== null && isset($store[$key])) ? count($store[$key]['codes']) : 0;
    }

    /** @param array<string,array{user:int, created:int, codes:list<int>}> $store */
    public static function purge(array &$store, int $now): void
    {
        foreach ($store as $k => $batch) {
            if (!is_array($batch) || !isset($batch['user'], $batch['created'], $batch['codes']) || self::expired($batch, $now)) {
                unset($store[$k]);
            }
        }
    }

    /**
     * Enteros positivos, sin repetir, en el orden original.
     *
     * @param array<mixed> $ids
     * @return list<int>
     */
    public static function uniqueIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0 && !isset($out[$n])) {
                $out[$n] = $n;
            }
        }
        return array_values($out);
    }

    /** @param array{created:int} $batch */
    private static function expired(array $batch, int $now): bool
    {
        return ($now - (int) $batch['created']) > self::TTL_SECONDS;
    }

    /** Deja como mucho MAX_PENDING lotes; nunca descarta `$keep`. */
    private static function trim(array &$store, string $keep): void
    {
        if (count($store) <= self::MAX_PENDING) {
            return;
        }
        uasort($store, static fn(array $a, array $b): int => $a['created'] <=> $b['created']);
        foreach (array_keys($store) as $k) {
            if (count($store) <= self::MAX_PENDING) {
                break;
            }
            if ($k !== $keep) {
                unset($store[$k]);
            }
        }
    }
}
