<?php

/**
 * API pública de SÓLO LECTURA: estado de integración SI-4 por unidad recibida (P2D-4; ADR-0023 §4/§8).
 *
 * Para que la UI de Compras muestre, de forma segura, la fase de integración y —si existe y la sesión puede verlo—
 * el activo GLPI y su `public_code` companyqr, SIN leer por SQL las tablas privadas de companyintegrations.
 *
 *   forUnits(receiptUnitUuids) → [uuid => {phase, itemtype?, items_id?, public_code?}]
 *
 * Seguridad (fail-closed):
 *   - sólo sagas de entidades ACCESIBLES por la sesión (`Session::haveAccessToEntity`);
 *   - el activo y el `public_code` se devuelven sólo si la sesión puede VER el activo con la ACL NATIVA
 *     (`CommonDBTM::canViewItem`); si no, sólo la fase;
 *   - jamás devuelve tokens (QR/lease), ids de Snipe, `last_error`, worker ni datos técnicos.
 * Nunca escribe nada.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Api;

use CommonDBTM;
use Session;
use GlpiPlugin\Companyintegrations\Model\Si4Saga;
use GlpiPlugin\Companyintegrations\Si4\SagaState;

final class InventoryLinkApi
{
    public const PHASE_PENDING     = 'pending';
    public const PHASE_IN_PROGRESS = 'in_progress';
    public const PHASE_COMPLETED   = 'completed';
    public const PHASE_ATTENTION   = 'attention';

    /** Claves que puede devolver por unidad (whitelist; el selftest lo verifica). */
    public const KEYS = ['phase', 'itemtype', 'items_id', 'public_code'];

    public const MAX_UNITS = 500;

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** Fase COARSE (no sensible) de una saga. PURO. */
    public static function phaseOf(string $state): string
    {
        return match ($state) {
            SagaState::COMPLETED                                => self::PHASE_COMPLETED,
            SagaState::MANUAL_REVIEW, SagaState::BLOCKED_CONFIG => self::PHASE_ATTENTION,
            SagaState::PENDING                                  => self::PHASE_PENDING,
            default                                             => self::PHASE_IN_PROGRESS,
        };
    }

    /**
     * @param array<int,string> $receiptUnitUuids
     * @return array<string,array{phase:string, itemtype:?string, items_id:?int, public_code:?string}>
     */
    public function forUnits(array $receiptUnitUuids): array
    {
        $uuids = [];
        foreach ($receiptUnitUuids as $u) {
            if (is_string($u) && preg_match(self::UUID_PATTERN, strtolower(trim($u))) === 1) {
                $uuids[strtolower(trim($u))] = true;
            }
        }
        if ($uuids === [] || count($uuids) > self::MAX_UNITS) {
            return [];
        }
        $out = [];
        foreach ((new Si4Saga())->find(['receipt_unit_uuid' => array_keys($uuids)]) as $row) {
            if (!Session::haveAccessToEntity((int) $row['entities_id'])) {
                continue;
            }
            $link = ['phase' => self::phaseOf((string) $row['state']), 'itemtype' => null, 'items_id' => null, 'public_code' => null];
            $itemtype = (string) ($row['glpi_itemtype'] ?? '');
            $itemsId = (int) ($row['glpi_items_id'] ?? 0);
            if ($itemtype !== '' && $itemsId > 0 && is_a($itemtype, CommonDBTM::class, true)) {
                /** @var CommonDBTM $item */
                $item = new $itemtype();
                if ($item->getFromDB($itemsId) && $item->canViewItem()) {
                    $link['itemtype'] = $itemtype;
                    $link['items_id'] = $itemsId;
                    $pc = (string) ($row['qr_public_code'] ?? '');
                    $link['public_code'] = $pc !== '' ? $pc : null;
                }
            }
            $out[(string) $row['receipt_unit_uuid']] = $link;
        }
        return $out;
    }
}
