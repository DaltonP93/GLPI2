<?php

/**
 * Reglas PURAS de SI4-3 (ADR-0022 §3–§5) sobre el código companyqr de un activo y su etiqueta:
 *
 *   - sólo metadatos NO sensibles (`META_KEYS` + outcome): cualquier otra clave (p. ej. el token) ⇒ fail-closed;
 *   - mismo código que el ya registrado en la saga (si lo hay), del MISMO activo (itemtype + items_id);
 *   - ACTIVO (revocado/suspendido/otro ⇒ revisión manual: nunca se rota ni se reactiva automáticamente);
 *   - de la entidad de la unidad;
 *   - `public_code` = número de inventario de la unidad (= asset_tag de Snipe = `otherserial` GLPI). Es identificación
 *     VISIBLE, nunca autorización: el QR codifica la URL con el token opaco que arma companyqr.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class QrCodeRules
{
    /** Metadatos que companyqr puede devolver (igual a `CompanyQrApi::META_KEYS`; el selftest lo verifica). */
    public const META_KEYS = ['code_id', 'public_code', 'status', 'itemtype', 'items_id', 'entities_id'];
    /** Igual a `Code::STATUS_ACTIVE` de companyqr (el selftest lo verifica). */
    public const STATUS_ACTIVE = 'active';

    /**
     * @param array<string,mixed>|null $meta metadatos de companyqr (null = el activo no tiene código)
     * @return array{ok:bool, reason:string, class:string}
     */
    public static function check(?array $meta, string $itemtype, int $itemsId, int $entityId, string $publicCode, int $recordedCodeId): array
    {
        if ($meta === null) {
            return self::no('el activo no tiene código companyqr', 'qr_code_missing');
        }
        $extra = array_diff(array_keys($meta), array_merge(self::META_KEYS, ['outcome']));
        if ($extra !== []) {
            // Sólo se nombran las CLAVES (nunca sus valores): companyqr no debe entregar el token.
            return self::no('companyqr devolvió datos no permitidos (' . implode(',', $extra) . ')', 'qr_meta_unexpected');
        }
        $id = (int) ($meta['code_id'] ?? 0);
        if ($id <= 0) {
            return self::no('código companyqr sin id', 'qr_code_invalid');
        }
        if ($recordedCodeId > 0 && $id !== $recordedCodeId) {
            return self::no('el código del activo (#' . $id . ') no es el registrado en la saga (#' . $recordedCodeId . ')', 'qr_code_mismatch');
        }
        if ((string) ($meta['itemtype'] ?? '') !== $itemtype || (int) ($meta['items_id'] ?? 0) !== $itemsId) {
            return self::no('el código #' . $id . ' pertenece a otro activo', 'qr_code_other_asset');
        }
        $status = (string) ($meta['status'] ?? '');
        if ($status !== self::STATUS_ACTIVE) {
            $class = in_array($status, ['revoked', 'suspended'], true) ? 'qr_code_' . $status : 'qr_code_inactive';
            return self::no('el código #' . $id . ' no está activo (' . mb_substr($status, 0, 20) . '): no se rota ni se reactiva automáticamente', $class);
        }
        if ((int) ($meta['entities_id'] ?? -1) !== $entityId) {
            return self::no('el código #' . $id . ' es de otra entidad', 'qr_code_entity');
        }
        if ((string) ($meta['public_code'] ?? '') !== $publicCode) {
            return self::no('el código visible del activo no es el número de inventario de la unidad', 'qr_public_code');
        }
        return ['ok' => true, 'reason' => '', 'class' => ''];
    }

    /** ¿Bytes de un PDF completo? (no vacío, cabecera `%PDF-` y marca final `%%EOF`) */
    public static function isPdf(string $bytes): bool
    {
        return strlen($bytes) > 64 && str_starts_with($bytes, '%PDF-') && str_contains(substr($bytes, -1024), '%%EOF');
    }

    /** @return array{ok:bool, reason:string, class:string} */
    private static function no(string $reason, string $class): array
    {
        return ['ok' => false, 'reason' => $reason, 'class' => $class];
    }
}
