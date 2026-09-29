<?php

/**
 * ¿El activo encontrado por el tag determinista es REALMENTE esta unidad? (create-or-reconcile, ADR-0020). PURO.
 *
 * Se acepta (`ONE`) sólo si TODO coincide, sin corregir diferencias:
 *   - exactamente un activo VIVO con el `asset_tag` exacto y ningún soft-deleted con ese tag;
 *   - `company.id` == compañía mapeada para la entidad de la unidad;
 *   - `model.id`   == modelo mapeado para la categoría de la línea;
 *   - si la unidad trae serial, el serial remoto es EXACTAMENTE ese;
 *   - la marca de procedencia `receipt_unit_uuid=<uuid>` de ESTA unidad está en `notes` (y no la de otra);
 *   - y existió una intención de creación de ESTA saga (`$ownCreateAttempted`): un tag que ya existía antes de
 *     cualquier POST de la saga (caso A) nunca se adopta; sólo se recupera un POST incierto/crash propio (caso B).
 *
 * Clasificaciones (todas salvo NONE/ONE ⇒ MANUAL_REVIEW; nunca se sobrescribe, borra ni recrea):
 *   NONE, ONE, DELETED, DUPLICATE, COMPANY_MISMATCH, MODEL_MISMATCH, SERIAL_MISMATCH, OWNERSHIP_MISMATCH, PREEXISTING.
 *
 * Los textos llegan transformados por `AssetsTransformer` de Snipe v8.7.2: `asset_tag`/`serial` con `e()` y `notes` con
 * `Helper::parseEscapedMarkedownInline()` (Parsedown::line, safe mode): se normalizan (strip_tags + decode) antes de comparar.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Client\SnipeEnvelope;

final class RemoteAssetMatcher
{
    public const NONE               = 'none';
    public const ONE                = 'one';
    public const DELETED            = 'deleted';
    public const DUPLICATE          = 'duplicate';
    public const COMPANY_MISMATCH   = 'company_mismatch';
    public const MODEL_MISMATCH     = 'model_mismatch';
    public const SERIAL_MISMATCH    = 'serial_mismatch';
    public const OWNERSHIP_MISMATCH = 'ownership_mismatch';
    public const PREEXISTING        = 'preexisting';

    /** Clave de la marca de procedencia que SI-4 escribe en `notes` al crear (siempre AL FINAL de las notas). */
    public const MARKER_KEY = 'receipt_unit_uuid=';

    /** Marca de procedencia exacta de una unidad. */
    public static function marker(string $receiptUnitUuid): string
    {
        return self::MARKER_KEY . $receiptUnitUuid;
    }

    /**
     * @param array<int,array<string,mixed>> $rows  resultado de `lookupByTag` (vivos y soft-deleted)
     * @param bool $ownCreateAttempted  true si ESTA saga ya registró una intención de creación (caso B)
     * @return array{kind:string, asset_id:int, detail:string}
     */
    public static function classify(array $rows, string $expectedTag, int $expectedCompanyId, int $expectedModelId, ?string $expectedSerial, string $receiptUnitUuid, bool $ownCreateAttempted): array
    {
        $r = self::match($rows, $expectedTag, $expectedCompanyId, $expectedModelId, $expectedSerial, $receiptUnitUuid);
        if ($r['kind'] !== self::NONE && !$ownCreateAttempted) {
            // Caso A: el tag determinista ya existía antes de cualquier POST de esta saga ⇒ no se adopta.
            return ['kind' => self::PREEXISTING, 'asset_id' => $r['asset_id'], 'detail' => 'tag preexistente sin intención de creación de esta saga (' . $r['kind'] . ')'];
        }
        return $r;
    }

    /** @param array<int,array<string,mixed>> $rows @return array{kind:string, asset_id:int, detail:string} */
    private static function match(array $rows, string $tag, int $companyId, int $modelId, ?string $serial, string $uuid): array
    {
        $live = [];
        $deleted = 0;
        foreach ($rows as $r) {
            if (strcasecmp(SnipeEnvelope::text($r['asset_tag'] ?? ''), $tag) !== 0) {
                continue; // bytag es exacto; cualquier otra fila se ignora
            }
            $isDeleted = isset($r['deleted_at']) && $r['deleted_at'] !== null && $r['deleted_at'] !== '' && $r['deleted_at'] !== [];
            if ($isDeleted) {
                $deleted++;
            } else {
                $live[] = $r;
            }
        }
        if ($live === [] && $deleted === 0) {
            return ['kind' => self::NONE, 'asset_id' => 0, 'detail' => ''];
        }
        if ($live === []) {
            return ['kind' => self::DELETED, 'asset_id' => 0, 'detail' => 'activo remoto soft-deleted'];
        }
        if (count($live) > 1 || $deleted > 0) {
            return ['kind' => self::DUPLICATE, 'asset_id' => 0, 'detail' => 'tag con ' . count($live) . ' vivo(s) y ' . $deleted . ' borrado(s)'];
        }
        $a = $live[0];
        $id = (int) ($a['id'] ?? 0);
        if ($id <= 0) {
            return ['kind' => self::DUPLICATE, 'asset_id' => 0, 'detail' => 'fila remota sin id'];
        }
        if (self::refId($a, 'company', 'company_id') !== $companyId) {
            return ['kind' => self::COMPANY_MISMATCH, 'asset_id' => $id, 'detail' => 'compañía remota distinta de la mapeada'];
        }
        if (self::refId($a, 'model', 'model_id') !== $modelId) {
            return ['kind' => self::MODEL_MISMATCH, 'asset_id' => $id, 'detail' => 'modelo remoto distinto del mapeado'];
        }
        if ($serial !== null && SnipeEnvelope::text($a['serial'] ?? '') !== $serial) {
            return ['kind' => self::SERIAL_MISMATCH, 'asset_id' => $id, 'detail' => 'serial remoto distinto del recibido'];
        }
        if (!self::hasOwnMarker($a['notes'] ?? null, $uuid)) {
            return ['kind' => self::OWNERSHIP_MISMATCH, 'asset_id' => $id, 'detail' => 'sin la marca de procedencia de esta unidad'];
        }
        return ['kind' => self::ONE, 'asset_id' => $id, 'detail' => ''];
    }

    /**
     * ¿`notes` (tal como la devuelve el transformer) contiene EXACTAMENTE una marca y es la de esta unidad?
     * Otra marca, varias marcas o ninguna ⇒ false.
     */
    public static function hasOwnMarker(mixed $notes, string $uuid): bool
    {
        if (!is_string($notes) || $notes === '') {
            return false;
        }
        $plain = html_entity_decode(strip_tags($notes), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match_all('/receipt_unit_uuid=([0-9A-Za-z-]*)/', $plain, $m) !== 1) {
            return false;
        }
        return $m[1][0] === $uuid;
    }

    /** id de una referencia del transformer (`company` → `{id}`) o de la columna cruda (`company_id`). */
    private static function refId(array $a, string $ref, string $column): int
    {
        return (int) (is_array($a[$ref] ?? null) ? ($a[$ref]['id'] ?? 0) : ($a[$column] ?? 0));
    }
}
