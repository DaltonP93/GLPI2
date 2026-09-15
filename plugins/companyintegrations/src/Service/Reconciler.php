<?php

/**
 * Reconciliación READ-ONLY Snipe → GLPI. Cruza cada activo Snipe con el puente y con activos GLPI
 * (match por serial), clasifica (MATCHED/SNIPE_ONLY/AMBIGUOUS/COMPANY_UNMAPPED/SERIAL_CONFLICT/
 * ERROR) y PERSISTE resultados en tablas PROPIAS. NUNCA escribe en Snipe ni modifica el activo
 * core de GLPI; sólo cuando la evidencia es inequívoca crea la fila de puente (mapeo).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Service;

use CommonDBTM;
use GlpiPlugin\Companyintegrations\Client\SnipeException;
use GlpiPlugin\Companyintegrations\Client\SnipeItClient;
use GlpiPlugin\Companyintegrations\Model\AssetBridge;
use GlpiPlugin\Companyintegrations\Model\AssetTagAlias;
use GlpiPlugin\Companyintegrations\Model\MapCompany;
use GlpiPlugin\Companyintegrations\Model\ReconResult;

final class Reconciler
{
    private ReconciliationClassifier $classifier;

    public function __construct(?ReconciliationClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new ReconciliationClassifier();
    }

    /**
     * Ejecuta una pasada de reconciliación.
     * @param array<int,string> $itemtypes
     * @return array<string,int> resumen por clasificación (+ run_id en 'run')
     */
    public function reconcile(SnipeItClient $client, array $itemtypes, int $limit = 50): array
    {
        $runId = CorrelationId::generate('run');
        $summary = [
            ReconciliationClassifier::MATCHED          => 0,
            ReconciliationClassifier::SNIPE_ONLY       => 0,
            ReconciliationClassifier::AMBIGUOUS        => 0,
            ReconciliationClassifier::COMPANY_UNMAPPED => 0,
            ReconciliationClassifier::SERIAL_CONFLICT  => 0,
            ReconciliationClassifier::ERROR            => 0,
        ];

        $rows = [];
        try {
            $rows = $client->listHardware(0, $limit);
        } catch (SnipeException $e) {
            // Falla de Snipe: se registra y se corta la pasada (no tumba GLPI).
            $this->persistResult($runId, $client->lastCorrelationId(), 0, '', ReconciliationClassifier::ERROR, null, null, null, 'listHardware: ' . $e->kind);
            $summary[ReconciliationClassifier::ERROR]++;
            return $summary;
        }

        foreach ($rows as $asset) {
            try {
                $classification = $this->reconcileOne($runId, $client->lastCorrelationId(), $asset, $itemtypes);
            } catch (\Throwable $e) {
                $classification = ReconciliationClassifier::ERROR;
                $this->persistResult($runId, $client->lastCorrelationId(),
                    (int) ($asset['id'] ?? 0), (string) ($asset['asset_tag'] ?? ''),
                    ReconciliationClassifier::ERROR, null, null, null, 'excepción: ' . $e->getMessage());
            }
            if (isset($summary[$classification])) {
                $summary[$classification]++;
            }
        }
        return $summary;
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<int,string>   $itemtypes
     */
    private function reconcileOne(string $runId, string $correlationId, array $asset, array $itemtypes): string
    {
        $snipeId  = (int) ($asset['id'] ?? 0);
        $tag      = (string) ($asset['asset_tag'] ?? '');
        $serial   = trim((string) ($asset['serial'] ?? ''));
        $companyId = (int) ($asset['company']['id'] ?? ($asset['company_id'] ?? 0));

        $mappedEntity = $companyId > 0 ? MapCompany::approvedEntityFor($companyId) : null;

        $bridge = new AssetBridge();
        $bridgeRow = $bridge->getFromDBByCrit(['snipe_asset_id' => $snipeId]) ? $bridge->fields : null;

        $candidates = ($serial !== '') ? $this->findGlpiBySerial($serial, $itemtypes) : [];

        $c = $this->classifier->classify($asset, $bridgeRow, $mappedEntity, $candidates);
        $class = $c['classification'];

        // Persistir el resultado (auditoría append-only).
        $this->persistResult($runId, $correlationId, $snipeId, $tag, $class,
            $c['glpi_itemtype'], $c['glpi_items_id'], $mappedEntity, $c['reason']);

        // Sólo cuando la evidencia es INEQUÍVOCA y NO existe puente → crear puente (tabla propia).
        if ($class === ReconciliationClassifier::MATCHED && $c['link'] === true && $bridgeRow === null
            && $c['glpi_itemtype'] !== null && $c['glpi_items_id'] !== null && $mappedEntity !== null) {
            $this->createBridge($snipeId, $tag, $serial, $c['glpi_itemtype'], (int) $c['glpi_items_id'], $mappedEntity, $correlationId);
        } elseif ($bridgeRow !== null) {
            // Puente existente: sólo refresca estado/timestamp (NUNCA cambia el vínculo ni auto-resuelve conflictos).
            $status = $class === ReconciliationClassifier::SERIAL_CONFLICT
                ? AssetBridge::STATUS_SERIAL_CONFLICT
                : AssetBridge::STATUS_MATCHED;
            $bridge->update([
                'id'                 => (int) $bridgeRow['id'],
                'sync_status'        => $status,
                'last_reconciled_at' => date('Y-m-d H:i:s'),
                'last_error'         => $class === ReconciliationClassifier::SERIAL_CONFLICT ? 'serial diverge' : null,
            ]);
        }

        return $class;
    }

    /**
     * Crea la fila de puente + alias de asset tag (tablas PROPIAS; no toca Snipe ni el activo GLPI).
     */
    private function createBridge(int $snipeId, string $tag, string $serial, string $itemtype, int $itemsId, int $entity, string $correlationId): void
    {
        $now = date('Y-m-d H:i:s');
        $bridgeId = (int) (new AssetBridge())->add([
            'snipe_asset_id'     => $snipeId,
            'snipe_asset_tag'    => $tag,
            'glpi_itemtype'      => $itemtype,
            'glpi_items_id'      => $itemsId,
            'glpi_entity_id'     => $entity,
            'serial'             => $serial !== '' ? $serial : null,
            'sync_status'        => AssetBridge::STATUS_MATCHED,
            'correlation_id'     => $correlationId,
            'last_reconciled_at' => $now,
            'date_creation'      => $now,
            'date_mod'           => $now,
        ]);
        if ($bridgeId > 0 && $tag !== '') {
            (new AssetTagAlias())->add([
                'asset_bridge_id' => $bridgeId,
                'asset_tag'       => $tag,
                'is_current'      => 1,
                'valid_from'      => $now,
                'date_creation'   => $now,
            ]);
        }
    }

    /**
     * Busca activos GLPI por serial usando la API soportada del modelo (read-only, sin SQL crudo
     * a core). Devuelve candidatos {itemtype, items_id, serial}.
     * @param array<int,string> $itemtypes
     * @return array<int,array<string,mixed>>
     */
    private function findGlpiBySerial(string $serial, array $itemtypes): array
    {
        $out = [];
        foreach ($itemtypes as $itemtype) {
            if (!class_exists($itemtype) || !is_subclass_of($itemtype, CommonDBTM::class)) {
                continue;
            }
            /** @var CommonDBTM $obj */
            $obj = new $itemtype();
            if (!method_exists($obj, 'find')) {
                continue;
            }
            foreach ($obj->find(['serial' => $serial]) as $row) {
                $out[] = [
                    'itemtype' => $itemtype,
                    'items_id' => (int) ($row['id'] ?? 0),
                    'serial'   => (string) ($row['serial'] ?? ''),
                ];
            }
        }
        return $out;
    }

    private function persistResult(
        string $runId,
        string $correlationId,
        int $snipeId,
        string $tag,
        string $classification,
        ?string $itemtype,
        ?int $itemsId,
        ?int $entity,
        string $detail
    ): void {
        (new ReconResult())->add([
            'run_id'         => $runId,
            'correlation_id' => $correlationId,
            'snipe_asset_id' => $snipeId,
            'snipe_asset_tag' => $tag,
            'classification' => $classification,
            'glpi_itemtype'  => $itemtype,
            'glpi_items_id'  => $itemsId,
            'glpi_entity_id' => $entity,
            'detail'         => $detail !== '' ? substr($detail, 0, 255) : null,
            'date'           => date('Y-m-d H:i:s'),
            'date_creation'  => date('Y-m-d H:i:s'),
        ]);
    }
}
