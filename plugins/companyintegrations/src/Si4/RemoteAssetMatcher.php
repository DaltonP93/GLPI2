<?php

/**
 * Clasifica el resultado de `lookupByTag(tag)` (filas vivas y soft-deleted) para create-or-reconcile. PURO.
 *
 *   NONE              no existe ⇒ se puede crear
 *   ONE               exactamente un activo VIVO, sin borrados, compañía y serial coherentes ⇒ vincular
 *   DELETED           sólo soft-deleted ⇒ alguien lo borró: NO se recrea (revisión humana)
 *   DUPLICATE         más de uno (vivos, o vivo + borrado) ⇒ revisión humana; nunca se elige uno
 *   COMPANY_MISMATCH  la compañía remota no es la mapeada para la entidad de la unidad (multi-entidad) ⇒ revisión
 *   SERIAL_MISMATCH   la unidad tiene serial y el remoto difiere ⇒ `serial_conflict` (nunca se sobrescribe)
 *
 * Los textos llegan escapados por `AssetsTransformer` (`e()`): se comparan decodificados.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

use GlpiPlugin\Companyintegrations\Client\SnipeEnvelope;

final class RemoteAssetMatcher
{
    public const NONE             = 'none';
    public const ONE              = 'one';
    public const DELETED          = 'deleted';
    public const DUPLICATE        = 'duplicate';
    public const COMPANY_MISMATCH = 'company_mismatch';
    public const SERIAL_MISMATCH  = 'serial_mismatch';

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array{kind:string, asset_id:int, detail:string}
     */
    public static function classify(array $rows, string $tag, int $expectedCompanyId, ?string $serial): array
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
        $company = (int) (is_array($a['company'] ?? null) ? ($a['company']['id'] ?? 0) : ($a['company_id'] ?? 0));
        if ($company !== $expectedCompanyId) {
            return ['kind' => self::COMPANY_MISMATCH, 'asset_id' => $id, 'detail' => 'compañía remota distinta de la mapeada'];
        }
        if ($serial !== null && SnipeEnvelope::text($a['serial'] ?? '') !== $serial) {
            return ['kind' => self::SERIAL_MISMATCH, 'asset_id' => $id, 'detail' => 'serial remoto distinto del recibido'];
        }
        return ['kind' => self::ONE, 'asset_id' => $id, 'detail' => ''];
    }
}
