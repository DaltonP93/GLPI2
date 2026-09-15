<?php

/**
 * Clasificación conservadora de reconciliación (dirección Snipe → GLPI). Lógica PURA.
 *
 * NUNCA auto-corrige un conflicto: sólo clasifica. Un match ambiguo termina en AMBIGUOUS,
 * jamás en auto-link. Sólo cuando la evidencia es inequívoca (compañía mapeada + exactamente un
 * candidato GLPI por serial y sin conflicto) sugiere MATCHED con el vínculo.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

final class ReconciliationClassifier
{
    public const MATCHED          = 'matched';
    public const SNIPE_ONLY       = 'snipe_only';
    public const GLPI_ONLY        = 'glpi_only';
    public const AMBIGUOUS        = 'ambiguous';
    public const COMPANY_UNMAPPED = 'company_unmapped';
    public const SERIAL_CONFLICT  = 'serial_conflict';
    public const ERROR            = 'error';

    /**
     * @param array<string,mixed>              $snipeAsset  ['id','asset_tag','serial','company_id']
     * @param array<string,mixed>|null         $bridge      fila existente de asset_bridge o null
     * @param int|null                         $mappedEntity entidad GLPI aprobada, o null si no mapeada
     * @param array<int,array<string,mixed>>   $candidates  activos GLPI que matchean por serial
     * @return array{classification:string, glpi_itemtype:?string, glpi_items_id:?int, reason:string, link:bool}
     */
    public function classify(array $snipeAsset, ?array $bridge, ?int $mappedEntity, array $candidates): array
    {
        $snipeSerial = trim((string) ($snipeAsset['serial'] ?? ''));

        // Compañía no mapeada → nunca inferir entidad.
        if ($mappedEntity === null) {
            return $this->result(self::COMPANY_UNMAPPED, null, null, 'compañía Snipe sin entidad GLPI aprobada', false);
        }

        // Ya existe puente: verificar coherencia de serial.
        if ($bridge !== null) {
            $bridgeSerial = trim((string) ($bridge['serial'] ?? ''));
            if ($snipeSerial !== '' && $bridgeSerial !== '' && $snipeSerial !== $bridgeSerial) {
                return $this->result(self::SERIAL_CONFLICT,
                    (string) ($bridge['glpi_itemtype'] ?? null) ?: null,
                    (int) ($bridge['glpi_items_id'] ?? 0) ?: null,
                    'serial diverge entre Snipe y el puente', false);
            }
            return $this->result(self::MATCHED,
                (string) ($bridge['glpi_itemtype'] ?? null) ?: null,
                (int) ($bridge['glpi_items_id'] ?? 0) ?: null,
                'ya puenteado', false);
        }

        // Sin puente: decidir por candidatos GLPI (match por serial).
        $n = count($candidates);
        if ($n === 0) {
            return $this->result(self::SNIPE_ONLY, null, null, 'sin activo GLPI coincidente', false);
        }
        if ($n > 1) {
            return $this->result(self::AMBIGUOUS, null, null, 'múltiples activos GLPI coinciden (no auto-link)', false);
        }

        // Exactamente un candidato inequívoco → sugerir MATCHED con vínculo.
        $c = $candidates[0];
        return $this->result(self::MATCHED,
            (string) ($c['itemtype'] ?? '') ?: null,
            (int) ($c['items_id'] ?? 0) ?: null,
            'match inequívoco por serial + compañía mapeada', true);
    }

    /**
     * @return array{classification:string, glpi_itemtype:?string, glpi_items_id:?int, reason:string, link:bool}
     */
    private function result(string $classification, ?string $itemtype, ?int $itemsId, string $reason, bool $link): array
    {
        return [
            'classification' => $classification,
            'glpi_itemtype'  => $itemtype,
            'glpi_items_id'  => $itemsId,
            'reason'         => $reason,
            'link'           => $link,
        ];
    }
}
