<?php

/**
 * Reconciliación READ-ONLY Snipe → GLPI. Cruza cada activo Snipe con el puente y con activos GLPI
 * (match por serial), clasifica (MATCHED/SNIPE_ONLY/AMBIGUOUS/COMPANY_UNMAPPED/SERIAL_CONFLICT/
 * ERROR) y PERSISTE resultados en tablas PROPIAS. NUNCA escribe en Snipe ni modifica el activo core
 * de GLPI; sólo cuando la evidencia es inequívoca crea la fila de puente (mapeo).
 *
 * Hardening SI-1:
 *  - PAGINACIÓN completa (limit/offset) con tope configurable y guardas anti-loop.
 *  - El `sync_status` del puente refleja la clasificación REAL (nunca "MATCHED" por defecto): un
 *    puente cuya compañía dejó de estar mapeada NO se reporta sano.
 *  - INTEGRIDAD del objetivo GLPI: para un puente existente se verifica que el objeto GLPI siga
 *    existiendo y con entidad coherente; si no, ERROR (no "MATCHED" silencioso).
 *  - RENAME de asset_tag: identidad estable (tag viejo→alias histórico, nuevo→actual, ambos
 *    resuelven al mismo puente); si el nuevo tag ya pertenece a otro puente → CONFLICTO fail-closed.
 *  - createBridge y rename son TRANSACCIONALES/race-safe (un fallo del alias no deja medio mapeo).
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
    private const MAX_PAGES = 10000; // guarda anti-loop absoluta

    private ReconciliationClassifier $classifier;

    public function __construct(?ReconciliationClassifier $classifier = null)
    {
        $this->classifier = $classifier ?? new ReconciliationClassifier();
    }

    /**
     * Ejecuta una pasada de reconciliación con PAGINACIÓN completa.
     * @param array<int,string> $itemtypes
     * @return array<string,int> resumen por clasificación
     */
    public function reconcile(SnipeItClient $client, array $itemtypes, int $pageSize = 50, int $maxAssets = 10000): array
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

        $pageSize  = max(1, $pageSize);
        $maxAssets = max(1, $maxAssets);
        $offset    = 0;
        $processed = 0;
        $pages     = 0;

        while ($processed < $maxAssets && $pages < self::MAX_PAGES) {
            $pages++;
            try {
                $rows = $client->listHardware($offset, $pageSize);
            } catch (SnipeException $e) {
                $this->persistResult($runId, $client->lastCorrelationId(), 0, '', ReconciliationClassifier::ERROR, null, null, null, 'listHardware: ' . $e->kind);
                $summary[ReconciliationClassifier::ERROR]++;
                break;
            }

            $n = count($rows);
            if ($n === 0) {
                break;
            }

            foreach ($rows as $asset) {
                try {
                    $class = $this->reconcileOne($runId, $client->lastCorrelationId(), $asset, $itemtypes);
                } catch (\Throwable $e) {
                    $class = ReconciliationClassifier::ERROR;
                    $this->persistResult($runId, $client->lastCorrelationId(), (int) ($asset['id'] ?? 0), (string) ($asset['asset_tag'] ?? ''), ReconciliationClassifier::ERROR, null, null, null, 'excepción: ' . $e->getMessage());
                }
                if (isset($summary[$class])) {
                    $summary[$class]++;
                }
                $processed++;
                if ($processed >= $maxAssets) {
                    break;
                }
            }

            if ($n < $pageSize) {
                break; // última página
            }
            $offset += $pageSize;
        }

        $summary['_processed'] = $processed;
        return $summary;
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<int,string>   $itemtypes
     */
    private function reconcileOne(string $runId, string $correlationId, array $asset, array $itemtypes): string
    {
        $snipeId   = (int) ($asset['id'] ?? 0);
        $tag       = (string) ($asset['asset_tag'] ?? '');
        $serial    = trim((string) ($asset['serial'] ?? ''));
        $companyId = (int) ($asset['company']['id'] ?? ($asset['company_id'] ?? 0));

        $mappedEntity = $companyId > 0 ? MapCompany::approvedEntityFor($companyId) : null;

        $bridge = new AssetBridge();
        $bridgeRow = $bridge->getFromDBByCrit(['snipe_asset_id' => $snipeId]) ? $bridge->fields : null;

        $candidates = ($serial !== '') ? $this->findGlpiBySerial($serial, $itemtypes) : [];

        $c = $this->classifier->classify($asset, $bridgeRow, $mappedEntity, $candidates);
        $class = $c['classification'];
        $reason = (string) $c['reason'];

        // --- Integridad del objetivo GLPI para un puente existente MATCHED ---
        if ($bridgeRow !== null && $class === ReconciliationClassifier::MATCHED) {
            if (!$this->targetIntegrityOk((string) $bridgeRow['glpi_itemtype'], (int) $bridgeRow['glpi_items_id'], (int) $bridgeRow['glpi_entity_id'])) {
                $class = ReconciliationClassifier::ERROR;
                $reason = 'objetivo GLPI inexistente o entidad divergente';
            }
        }

        // --- Rename de asset_tag (identidad estable) para puente existente aún válido ---
        if ($bridgeRow !== null && $tag !== '' && $tag !== (string) $bridgeRow['snipe_asset_tag']
            && in_array($class, [ReconciliationClassifier::MATCHED, ReconciliationClassifier::SERIAL_CONFLICT], true)) {
            $rn = $this->handleTagRename($bridgeRow, $tag);
            if ($rn === 'conflict') {
                $class = ReconciliationClassifier::ERROR;
                $reason = 'nuevo asset_tag ya pertenece a otro puente (conflicto, sin reasignar)';
            } else {
                $reason .= ' + tag renombrado (alias histórico)';
                $bridgeRow['snipe_asset_tag'] = $tag; // reflejar para la actualización de estado
            }
        }

        $this->persistResult($runId, $correlationId, $snipeId, $tag, $class, $c['glpi_itemtype'], $c['glpi_items_id'], $mappedEntity, $reason);

        // Crear puente sólo con evidencia INEQUÍVOCA y sin puente previo.
        if ($class === ReconciliationClassifier::MATCHED && $c['link'] === true && $bridgeRow === null
            && $c['glpi_itemtype'] !== null && $c['glpi_items_id'] !== null && $mappedEntity !== null) {
            $this->createBridge($snipeId, $tag, $serial, $c['glpi_itemtype'], (int) $c['glpi_items_id'], $mappedEntity, $correlationId);
        } elseif ($bridgeRow !== null) {
            // Puente existente: sync_status refleja la clasificación REAL (nunca MATCHED por defecto);
            // jamás cambia el vínculo ni auto-resuelve conflictos.
            $bridge->update([
                'id'                 => (int) $bridgeRow['id'],
                'sync_status'        => $this->mapClassificationToStatus($class),
                'last_reconciled_at' => date('Y-m-d H:i:s'),
                'last_error'         => $class === ReconciliationClassifier::MATCHED ? null : substr($reason, 0, 255),
            ]);
        }

        return $class;
    }

    /** ¿El objetivo GLPI del puente sigue existiendo y con entidad coherente? (read-only). */
    private function targetIntegrityOk(string $itemtype, int $itemsId, int $expectedEntity): bool
    {
        if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype) || !is_subclass_of($itemtype, CommonDBTM::class)) {
            return false;
        }
        /** @var CommonDBTM $obj */
        $obj = new $itemtype();
        if (!$obj->getFromDB($itemsId)) {
            return false; // el objeto desapareció
        }
        if (isset($obj->fields['entities_id']) && (int) $obj->fields['entities_id'] !== $expectedEntity) {
            return false; // entidad divergente
        }
        return true;
    }

    private function mapClassificationToStatus(string $class): string
    {
        return match ($class) {
            ReconciliationClassifier::MATCHED          => AssetBridge::STATUS_MATCHED,
            ReconciliationClassifier::SNIPE_ONLY       => AssetBridge::STATUS_SNIPE_ONLY,
            ReconciliationClassifier::AMBIGUOUS        => AssetBridge::STATUS_AMBIGUOUS,
            ReconciliationClassifier::COMPANY_UNMAPPED => AssetBridge::STATUS_COMPANY_UNMAPPED,
            ReconciliationClassifier::SERIAL_CONFLICT  => AssetBridge::STATUS_SERIAL_CONFLICT,
            default                                    => AssetBridge::STATUS_ERROR,
        };
    }

    /**
     * Rename de asset_tag manteniendo identidad estable. Devuelve '' si OK/renombrado, 'conflict' si
     * el nuevo tag ya pertenece a otro puente (fail-closed, sin reasignar). Transaccional.
     * @param array<string,mixed> $bridgeRow
     */
    private function handleTagRename(array $bridgeRow, string $newTag): string
    {
        /** @var \DBmysql $DB */
        global $DB;
        $bridgeId = (int) $bridgeRow['id'];
        $oldTag   = (string) $bridgeRow['snipe_asset_tag'];

        // ¿El nuevo tag ya pertenece a OTRO puente (por tag actual o por alias)?
        $other = new AssetBridge();
        if ($other->getFromDBByCrit(['snipe_asset_tag' => $newTag]) && (int) $other->getID() !== $bridgeId) {
            return 'conflict';
        }
        $aliasOther = new AssetTagAlias();
        if ($aliasOther->getFromDBByCrit(['asset_tag' => $newTag]) && (int) $aliasOther->fields['asset_bridge_id'] !== $bridgeId) {
            return 'conflict';
        }

        $now = date('Y-m-d H:i:s');
        $DB->beginTransaction();
        try {
            // Tag viejo → alias histórico (is_current=0). Upsert idempotente.
            $this->upsertAlias($bridgeId, $oldTag, 0, $now);
            // Tag nuevo → alias actual (is_current=1).
            $this->upsertAlias($bridgeId, $newTag, 1, $now);
            // Actualizar el tag actual del puente.
            $ok = (new AssetBridge())->update(['id' => $bridgeId, 'snipe_asset_tag' => $newTag, 'date_mod' => $now]);
            if (!$ok) {
                $this->safeRollback($DB);
                return 'conflict';
            }
            $DB->commit();
            return '';
        } catch (\Throwable) {
            $this->safeRollback($DB);
            return 'conflict';
        }
    }

    /** Inserta o actualiza el alias (asset_tag es UNIQUE). */
    private function upsertAlias(int $bridgeId, string $tag, int $isCurrent, string $now): void
    {
        if ($tag === '') {
            return;
        }
        $alias = new AssetTagAlias();
        if ($alias->getFromDBByCrit(['asset_tag' => $tag])) {
            $alias->update([
                'id'         => $alias->getID(),
                'asset_bridge_id' => $bridgeId,
                'is_current' => $isCurrent,
                'valid_to'   => $isCurrent === 0 ? $now : null,
            ]);
            return;
        }
        (new AssetTagAlias())->add([
            'asset_bridge_id' => $bridgeId,
            'asset_tag'       => $tag,
            'is_current'      => $isCurrent,
            'valid_from'      => $now,
            'valid_to'        => $isCurrent === 0 ? $now : null,
            'date_creation'   => $now,
        ]);
    }

    /**
     * Crea la fila de puente + alias de forma TRANSACCIONAL y race-safe (un fallo del alias no deja
     * medio mapeo). Idempotente: si ya existe un puente para el snipe_asset_id, no duplica.
     */
    private function createBridge(int $snipeId, string $tag, string $serial, string $itemtype, int $itemsId, int $entity, string $correlationId): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $now = date('Y-m-d H:i:s');

        $DB->beginTransaction();
        try {
            // Idempotencia concurrente: re-chequear que no exista ya el puente.
            if ((new AssetBridge())->getFromDBByCrit(['snipe_asset_id' => $snipeId])) {
                $this->safeRollback($DB);
                return;
            }

            $bridgeId = 0;
            try {
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
            } catch (\Throwable) {
                $bridgeId = 0; // UNIQUE por carrera → tratar como no creado
            }
            if ($bridgeId <= 0) {
                $this->safeRollback($DB);
                return;
            }

            if ($tag !== '') {
                $aliasOk = false;
                try {
                    $aliasOk = ((int) (new AssetTagAlias())->add([
                        'asset_bridge_id' => $bridgeId,
                        'asset_tag'       => $tag,
                        'is_current'      => 1,
                        'valid_from'      => $now,
                        'date_creation'   => $now,
                    ])) > 0;
                } catch (\Throwable) {
                    $aliasOk = false;
                }
                if (!$aliasOk) {
                    // Un fallo del alias no puede dejar medio mapeo.
                    $this->safeRollback($DB);
                    return;
                }
            }

            $DB->commit();
        } catch (\Throwable) {
            $this->safeRollback($DB);
        }
    }

    /**
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
                $out[] = ['itemtype' => $itemtype, 'items_id' => (int) ($row['id'] ?? 0), 'serial' => (string) ($row['serial'] ?? '')];
            }
        }
        return $out;
    }

    private function persistResult(string $runId, string $correlationId, int $snipeId, string $tag, string $classification, ?string $itemtype, ?int $itemsId, ?int $entity, string $detail): void
    {
        (new ReconResult())->add([
            'run_id'          => $runId,
            'correlation_id'  => $correlationId,
            'snipe_asset_id'  => $snipeId,
            'snipe_asset_tag' => $tag,
            'classification'  => $classification,
            'glpi_itemtype'   => $itemtype,
            'glpi_items_id'   => $itemsId,
            'glpi_entity_id'  => $entity,
            'detail'          => $detail !== '' ? substr($detail, 0, 255) : null,
            'date'            => date('Y-m-d H:i:s'),
            'date_creation'   => date('Y-m-d H:i:s'),
        ]);
    }

    private function safeRollback(\DBmysql $DB): void
    {
        try {
            if (!method_exists($DB, 'inTransaction') || $DB->inTransaction()) {
                $DB->rollBack();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }
}
