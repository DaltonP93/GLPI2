<?php

/**
 * Etapas GLPI de la saga SI-4 — incremento SI4-2 (ADR-0021). Continúa la MISMA saga desde `SNIPE_CREATED`:
 *
 *   0. Pre-validación SIN escribir nada: mapeo aprobado (itemtype + modelo GLPI), itemtype soportado, modelo existente,
 *      política monetaria del Infocom (moneda configurada, valor exacto en decimal(20,4)) y proveedor utilizable.
 *   1. GLPI_RESOLVED_OR_CREATED: BUSCAR PRIMERO por `otherserial` (= tag determinista de la saga) y por `serial`;
 *      `GlpiCandidateMatcher` decide crear / vincular (reclamando el número de inventario si estaba vacío) / revisión
 *      manual. Crear = intención persistida con presupuesto de lease + `add()` nativo + id guardado sin verificar +
 *      verificación con la misma búsqueda.
 *   2. INFOCOM_READY: un Infocom por activo con valor, proveedor, número de solicitud y fecha de entrega exactos; sólo
 *      se completan campos propios vacíos; distinto ⇒ revisión manual; se verifica releyendo.
 *   3. BRIDGED: `asset_bridge` 1:1 (uuid, Snipe id/tag, activo GLPI, entidad) + alias vigente, idempotente.
 *
 * Cada escritura en GLPI exige ser dueño del lease con ≥ GLPI_WRITE_BUDGET_SEC restantes. Devuelve un resultado que
 * `Si4Worker` asienta (MANUAL_REVIEW ⇒ markError; BLOCKED_CONFIG ⇒ markRetry tardío con `resume_state`). NUNCA hace
 * `acknowledgeProcessed()` ni toca companyqr.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class Si4GlpiStage
{
    /** Lease restante (s) exigido antes de cada escritura en GLPI (operaciones locales de la BD). */
    public const GLPI_WRITE_BUDGET_SEC = 30;

    public const O_BRIDGED    = 'bridged';
    public const O_LEASE_LOST = 'lease_lost';
    public const O_MANUAL     = 'manual';
    public const O_BLOCKED    = 'blocked';

    private SagaStore $sagas;
    private GlpiAssetGateway $glpi;
    private GlpiMappingResolver $mapping;
    private BridgeStore $bridges;
    private string $infocomCurrency;
    /** @var callable(string,string):void|null */
    private $probe;

    /** @param callable(string,string):void|null $probe punto de inyección de fallas SÓLO para tests */
    public function __construct(SagaStore $sagas, GlpiAssetGateway $glpi, GlpiMappingResolver $mapping, BridgeStore $bridges, string $infocomCurrency, ?callable $probe = null)
    {
        $this->sagas = $sagas;
        $this->glpi = $glpi;
        $this->mapping = $mapping;
        $this->bridges = $bridges;
        $this->infocomCurrency = $infocomCurrency;
        $this->probe = $probe;
    }

    /**
     * @param array<string,mixed> $payload handoff v1 (ya validado e inmutable)
     * @return array{kind:string, why:string, class:string, state:string, resume:?string}
     *         `state` = estado vigente de la saga (origen de la transición que asienta el worker); `resume` = etapa desde
     *         la que reanudar si `kind` = O_BLOCKED
     */
    public function advance(string $uuid, string $tokenSha, array $payload, string $corr): array
    {
        $saga = $this->sagas->get($uuid);
        if ($saga === null) {
            return $this->out(self::O_LEASE_LOST, '');
        }
        $state = (string) $saga['state'];
        if ($state === SagaState::BRIDGED) {
            return $this->out(self::O_BRIDGED, $state); // ya completa: no-op idempotente (sin lecturas ni escrituras)
        }
        $stage = $state === SagaState::BLOCKED_CONFIG ? (string) ($saga['resume_state'] ?? '') : $state;
        if (!in_array($stage, SagaState::POST_SNIPE, true)) {
            return $this->manual($state, 'etapa inesperada para SI4-2: ' . $state, 'saga_state');
        }
        $tag = (string) ($saga['snipe_asset_tag'] ?? '');
        $snipeId = (int) ($saga['snipe_asset_id'] ?? 0);
        if ($tag === '' || $snipeId <= 0) {
            return $this->manual($state, 'saga sin activo Snipe verificado', 'saga_incomplete');
        }
        $entity = (int) ($payload['entity_id'] ?? -1);
        $serial = is_string($payload['serial'] ?? null) && $payload['serial'] !== '' ? $payload['serial'] : null;

        // 0) Pre-validación: nada se escribe en GLPI si la unidad no puede completarse con exactitud.
        $target = $this->mapping->resolve($payload);
        if (!$target['ok']) {
            return $this->blocked($state, $stage, $target['reason'], 'glpi_mapping');
        }
        // Una saga que ya registró su itemtype (intención o activo) lo conserva aunque el mapeo cambie después.
        $recordedType = (string) ($saga['glpi_itemtype'] ?? '');
        $itemtype = $recordedType !== '' ? $recordedType : $target['itemtype'];
        $modelId = $itemtype === $target['itemtype'] ? $target['model_id'] : 0;
        $problem = $this->glpi->targetProblem($itemtype, $modelId);
        if ($problem !== null) {
            return $this->blocked($state, $stage, $problem, 'glpi_mapping');
        }
        $plan = InfocomPolicy::plan($payload, $this->infocomCurrency);
        if (!$plan['ok']) {
            return $this->manual($state, $plan['reason'], $plan['class']);
        }
        if (!$this->glpi->supplierUsable((int) $plan['fields']['suppliers_id'])) {
            return $this->manual($state, 'proveedor de la compra inexistente o en la papelera', 'infocom_supplier');
        }

        // 1) Activo GLPI.
        if ($stage === SagaState::SNIPE_CREATED) {
            $r = $this->resolveAsset($uuid, $tokenSha, $saga, $state, $itemtype, $modelId, $entity, $serial, $tag, $payload);
            if ($r !== null) {
                return $r;
            }
            $saga = $this->sagas->get($uuid) ?? $saga;
            $state = (string) $saga['state'];
            $stage = SagaState::GLPI_RESOLVED_OR_CREATED;
        } else {
            $itemsId = (int) ($saga['glpi_items_id'] ?? 0);
            $cur = $itemsId > 0 ? $this->glpi->get($itemtype, $itemsId) : null;
            if ($cur === null || $cur['is_deleted'] === 1 || $cur['entities_id'] !== $entity) {
                return $this->manual($state, 'el activo GLPI #' . $itemsId . ' ya no es válido (borrado, en la papelera o en otra entidad)', 'glpi_asset_diverged');
            }
        }
        $itemsId = (int) $saga['glpi_items_id'];

        // 2) Infocom.
        if ($stage === SagaState::GLPI_RESOLVED_OR_CREATED) {
            $r = $this->ensureInfocom($uuid, $tokenSha, $state, $itemtype, $itemsId, $plan['fields']);
            if ($r !== null) {
                return $r;
            }
            $saga = $this->sagas->get($uuid) ?? $saga;
            $state = (string) $saga['state'];
        } else {
            $d = InfocomPolicy::reconcile($this->glpi->getInfocom($itemtype, $itemsId) ?? [], $plan['fields']);
            if ($d['fill'] !== [] || $d['conflicts'] !== []) {
                return $this->manual($state, 'el Infocom del activo ya no coincide con la unidad (' . implode(',', array_merge(array_keys($d['fill']), $d['conflicts'])) . ')', 'infocom_diverged');
            }
        }

        // 3) AssetBridge 1:1.
        return $this->bridge($uuid, $tokenSha, $state, $saga, $itemtype, $itemsId, $entity, $serial, $corr);
    }

    /**
     * @param array<string,mixed> $saga @param array<string,mixed> $payload
     * @return array<string,mixed>|null null = activo resuelto y registrado (GLPI_RESOLVED_OR_CREATED)
     */
    private function resolveAsset(string $uuid, string $tokenSha, array $saga, string $state, string $itemtype, int $modelId, int $entity, ?string $serial, string $tag, array $payload): ?array
    {
        $this->probe('before_glpi_search', $uuid);
        $recorded = (int) ($saga['glpi_items_id'] ?? 0); // creado por esta saga y aún sin verificar
        $c = GlpiCandidateMatcher::classify($this->glpi->findCandidates($itemtype, $tag, $serial), $entity, $tag, $serial);
        if ($c['kind'] === GlpiCandidateMatcher::ONE) {
            if ($recorded > 0 && $recorded !== $c['id']) {
                return $this->manual($state, 'el activo GLPI encontrado no es el que registró esta saga (#' . $recorded . ')', 'glpi_id_mismatch');
            }
            $this->probe('after_glpi_found', $uuid);
            if ($c['claim']) {
                if (!$this->glpi->canUpdate($itemtype, $c['id'])) {
                    return $this->blocked($state, SagaState::SNIPE_CREATED, 'el usuario técnico no puede actualizar ' . $itemtype . ' #' . $c['id'], 'acl');
                }
                if (!$this->sagas->holds($uuid, $tokenSha, self::GLPI_WRITE_BUDGET_SEC)) {
                    return $this->out(self::O_LEASE_LOST, $state);
                }
                $ok = $this->glpi->setInventoryNumber($itemtype, $c['id'], $tag);
                $after = $this->glpi->get($itemtype, $c['id']);
                if (!$ok || $after === null || $after['otherserial'] !== $tag) {
                    return $this->manual($state, 'GLPI no registró el número de inventario en ' . $itemtype . ' #' . $c['id'], 'glpi_claim_refused');
                }
            }
            $own = (int) ($saga['glpi_create_calls'] ?? 0) > 0 && !$c['claim'];
            return $this->recordResolved($uuid, $tokenSha, $state, $itemtype, $c['id'], $entity, $own ? SagaState::OUTCOME_CREATED : SagaState::OUTCOME_LINKED);
        }
        if ($c['kind'] !== GlpiCandidateMatcher::NONE) {
            return $this->manual($state, 'GLPI ' . $c['kind'] . ': ' . $c['detail'], 'glpi_' . $c['kind']);
        }
        if ($recorded > 0) {
            // Esta saga YA creó el activo y su número de inventario no lo encuentra: nunca se crea un segundo.
            return $this->manual($state, 'el activo GLPI creado por esta saga (#' . $recorded . ') ya no aparece por su número de inventario', 'glpi_created_missing');
        }

        // Crear: intención persistida con presupuesto de lease, luego UN add() nativo.
        if (!$this->glpi->canCreate($itemtype, $entity)) {
            return $this->blocked($state, SagaState::SNIPE_CREATED, 'el usuario técnico no puede crear ' . $itemtype . ' en la entidad ' . $entity, 'acl');
        }
        $calls = (int) ($saga['glpi_create_calls'] ?? 0) + 1;
        if (!$this->sagas->transition($uuid, $tokenSha, $state, ['glpi_itemtype' => $itemtype, 'glpi_create_calls' => $calls],
            'glpi_create_intent', $itemtype . ' add #' . $calls, self::GLPI_WRITE_BUDGET_SEC)) {
            return $this->out(self::O_LEASE_LOST, $state);
        }
        $this->probe('before_glpi_create', $uuid);
        $id = $this->glpi->create($itemtype, [
            'entities_id' => $entity, 'serial' => $serial, 'otherserial' => $tag, 'model_id' => $modelId,
            'name' => mb_substr((string) ($payload['description'] ?? ''), 0, 255),
        ]);
        $this->probe('after_glpi_create', $uuid);
        if ($id <= 0) {
            return $this->manual($state, 'GLPI rechazó el alta de ' . $itemtype . ' (reglas, unicidad o hook)', 'glpi_add_refused');
        }
        try {
            $ok = $this->sagas->transition($uuid, $tokenSha, $state, ['glpi_items_id' => $id, 'glpi_entity_id' => $entity],
                'glpi_created_unverified', $itemtype . ' #' . $id);
        } catch (\RuntimeException $e) {
            return $this->manual($state, 'no se pudo registrar el activo GLPI: ' . $e->getMessage(), 'glpi_link_unique');
        }
        if (!$ok) {
            return $this->out(self::O_LEASE_LOST, $state); // el próximo dueño lo encontrará por su número de inventario
        }
        $this->probe('after_glpi_recorded_unverified', $uuid);
        $v = GlpiCandidateMatcher::classify($this->glpi->findCandidates($itemtype, $tag, $serial), $entity, $tag, $serial);
        if ($v['kind'] !== GlpiCandidateMatcher::ONE || $v['id'] !== $id || $v['claim']) {
            return $this->manual($state, 'verificación posterior del alta GLPI: ' . $v['kind'] . ' ' . $v['detail'], 'glpi_post_verify');
        }
        return $this->recordResolved($uuid, $tokenSha, $state, $itemtype, $id, $entity, SagaState::OUTCOME_CREATED);
    }

    /** @return array<string,mixed>|null */
    private function recordResolved(string $uuid, string $tokenSha, string $state, string $itemtype, int $id, int $entity, string $outcome): ?array
    {
        try {
            $ok = $this->sagas->transition($uuid, $tokenSha, $state, [
                'state' => SagaState::GLPI_RESOLVED_OR_CREATED, 'glpi_itemtype' => $itemtype, 'glpi_items_id' => $id,
                'glpi_entity_id' => $entity, 'glpi_outcome' => $outcome, 'resume_state' => null, 'last_error' => null, 'last_error_class' => null,
            ], 'glpi_resolved', $outcome . ' ' . $itemtype . ' #' . $id);
        } catch (\RuntimeException $e) {
            return $this->manual($state, 'no se pudo ligar el activo GLPI: ' . $e->getMessage(), 'glpi_link_unique');
        }
        if (!$ok) {
            return $this->out(self::O_LEASE_LOST, $state);
        }
        $this->probe('after_glpi_recorded', $uuid);
        return null;
    }

    /**
     * @param array<string,int|string> $plan
     * @return array<string,mixed>|null null = INFOCOM_READY registrado
     */
    private function ensureInfocom(string $uuid, string $tokenSha, string $state, string $itemtype, int $itemsId, array $plan): ?array
    {
        $ic = $this->glpi->getInfocom($itemtype, $itemsId);
        $d = InfocomPolicy::reconcile($ic, $plan);
        if ($d['conflicts'] !== []) {
            return $this->manual($state, 'el Infocom existente tiene otros valores en ' . implode(',', $d['conflicts']) . ' (no se pisan)', 'infocom_conflict');
        }
        $icId = (int) ($ic['id'] ?? 0);
        $outcome = SagaState::OUTCOME_UNCHANGED;
        if ($ic === null || $d['fill'] !== []) {
            if (!$this->glpi->canInfocom($itemtype, $itemsId, $icId)) {
                return $this->blocked($state, SagaState::GLPI_RESOLVED_OR_CREATED, 'el usuario técnico no puede ' . ($ic === null ? 'crear' : 'actualizar') . ' el Infocom', 'acl');
            }
            if (!$this->sagas->holds($uuid, $tokenSha, self::GLPI_WRITE_BUDGET_SEC)) {
                return $this->out(self::O_LEASE_LOST, $state);
            }
            if ($ic === null) {
                $outcome = SagaState::OUTCOME_CREATED;
                if ($this->glpi->addInfocom($itemtype, $itemsId, $plan) <= 0 && $this->glpi->getInfocom($itemtype, $itemsId) === null) {
                    return $this->manual($state, 'GLPI rechazó el Infocom del activo', 'infocom_refused');
                }
            } else {
                $outcome = SagaState::OUTCOME_UPDATED;
                if (!$this->glpi->updateInfocom($icId, $d['fill'])) {
                    return $this->manual($state, 'GLPI rechazó completar el Infocom #' . $icId, 'infocom_refused');
                }
            }
        }
        $this->probe('after_infocom_write', $uuid);
        $ic2 = $this->glpi->getInfocom($itemtype, $itemsId);
        $d2 = InfocomPolicy::reconcile($ic2, $plan);
        if ($ic2 === null || $d2['fill'] !== [] || $d2['conflicts'] !== []) {
            return $this->manual($state, 'verificación posterior del Infocom: no quedó con los valores de la unidad', 'infocom_post_verify');
        }
        if (!$this->sagas->transition($uuid, $tokenSha, $state, [
            'state' => SagaState::INFOCOM_READY, 'glpi_infocom_id' => (int) $ic2['id'], 'infocom_outcome' => $outcome,
            'resume_state' => null, 'last_error' => null, 'last_error_class' => null,
        ], 'infocom_ready', $outcome . ' #' . (int) $ic2['id'])) {
            return $this->out(self::O_LEASE_LOST, $state);
        }
        $this->probe('after_infocom_ready', $uuid);
        return null;
    }

    /**
     * @param array<string,mixed> $saga
     * @return array<string,mixed>
     */
    private function bridge(string $uuid, string $tokenSha, string $state, array $saga, string $itemtype, int $itemsId, int $entity, ?string $serial, string $corr): array
    {
        $want = [
            'receipt_unit_uuid' => $uuid, 'snipe_asset_id' => (int) $saga['snipe_asset_id'], 'snipe_asset_tag' => (string) $saga['snipe_asset_tag'],
            'glpi_itemtype' => $itemtype, 'glpi_items_id' => $itemsId, 'glpi_entity_id' => $entity,
        ];
        $b = BridgeMatcher::classify($this->bridges->findRelated($uuid, $want['snipe_asset_id'], $want['snipe_asset_tag'], $itemtype, $itemsId), $want);
        if ($b['kind'] === BridgeMatcher::CONFLICT) {
            return $this->manual($state, 'asset_bridge: ' . $b['detail'], 'bridge_conflict');
        }
        if ($b['kind'] !== BridgeMatcher::EXACT && !$this->sagas->holds($uuid, $tokenSha, self::GLPI_WRITE_BUDGET_SEC)) {
            return $this->out(self::O_LEASE_LOST, $state);
        }
        try {
            if ($b['kind'] === BridgeMatcher::NONE) {
                $bridgeId = $this->bridges->insert($want + ['serial' => $serial, 'correlation_id' => $corr]);
            } elseif ($b['kind'] === BridgeMatcher::ADOPT) {
                $bridgeId = $b['id'];
                $this->bridges->adopt($bridgeId, $uuid, $want['snipe_asset_tag'], $corr);
            } else {
                $bridgeId = $b['id'];
                $this->bridges->ensureAlias($bridgeId, $want['snipe_asset_tag']);
            }
        } catch (\RuntimeException $e) {
            return $this->manual($state, 'asset_bridge rechazado: ' . $e->getMessage(), 'bridge_conflict');
        }
        $this->probe('after_bridge_write', $uuid);
        if (!$this->sagas->transition($uuid, $tokenSha, $state, [
            'state' => SagaState::BRIDGED, 'asset_bridge_id' => $bridgeId, 'resume_state' => null, 'last_error' => null, 'last_error_class' => null,
        ], 'bridged', 'asset_bridge #' . $bridgeId)) {
            return $this->out(self::O_LEASE_LOST, $state);
        }
        $this->probe('after_bridged', $uuid);
        return $this->out(self::O_BRIDGED, SagaState::BRIDGED);
    }

    /** @return array<string,mixed> */
    private function manual(string $state, string $why, string $class): array
    {
        return $this->out(self::O_MANUAL, $state, $why, $class);
    }

    /** @return array<string,mixed> */
    private function blocked(string $state, string $resume, string $why, string $class): array
    {
        return $this->out(self::O_BLOCKED, $state, $why, $class, $resume);
    }

    /** @return array<string,mixed> */
    private function out(string $kind, string $state, string $why = '', string $class = '', ?string $resume = null): array
    {
        return ['kind' => $kind, 'why' => $why, 'class' => substr($class, 0, 30), 'state' => $state, 'resume' => $resume];
    }

    private function probe(string $point, string $uuid): void
    {
        if ($this->probe !== null) {
            ($this->probe)($point, $uuid);
        }
    }
}
