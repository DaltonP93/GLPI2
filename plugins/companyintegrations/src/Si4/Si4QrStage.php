<?php

/**
 * Etapa QR de la saga SI-4 — incremento SI4-3 (ADR-0022). Continúa la MISMA saga desde `BRIDGED`:
 *
 *   0. ACL del usuario técnico en companyqr (RIGHT_GENERATE + RIGHT_PRINT) ANTES de cualquier escritura ⇒ si falta,
 *      BLOCKED_CONFIG (resume BRIDGED), sin bypass.
 *   1. Vínculos vigentes (SIN volver a Snipe: el puente es la fuente de verdad del vínculo verificado en SI4-2):
 *      `asset_bridge` exactamente 1:1 con la unidad (uuid, Snipe id/tag, activo GLPI, entidad) y activo GLPI vivo en
 *      la entidad de la unidad. Divergencia ⇒ MANUAL_REVIEW.
 *   2. Código companyqr del activo por la API pública (`ensureForItem`: get-or-create IDEMPOTENTE por activo; un crash
 *      tras crear el código y antes de registrarlo encuentra el MISMO código). Debe ser ACTIVO, del mismo activo y
 *      entidad y con `public_code` = número de inventario de la unidad (`QrCodeRules`). Revocado/suspendido/otra
 *      entidad/otro activo ⇒ MANUAL_REVIEW: nunca se rota ni se reactiva.
 *   3. Metadatos NO sensibles en la saga (`qr_code_id`, `qr_public_code`, `qr_outcome`). Nunca el token ni el PDF.
 *   4. Etiqueta renderizable con el renderer EXISTENTE de companyqr (el PDF se valida y se descarta).
 *   5. QR_READY (+ `label_ready_at` con el reloj de la BD).
 *
 * `revalidateForAck()` repite 1 + el código (ACTIVO, mismo activo, mismo `public_code`) JUSTO antes del
 * `acknowledgeProcessed()` que hace `Si4Worker` (último efecto externo). Esta etapa NO confirma el outbox.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companyintegrations\Si4;

final class Si4QrStage
{
    /** Lease restante (s) exigido antes de escribir en companyqr (operaciones locales de la BD). */
    public const QR_WRITE_BUDGET_SEC = 30;

    public const O_QR_READY   = 'qr_ready';
    public const O_LEASE_LOST = 'lease_lost';
    public const O_MANUAL     = 'manual';
    public const O_BLOCKED    = 'blocked';

    private SagaStore $sagas;
    private BridgeStore $bridges;
    private GlpiAssetGateway $glpi;
    private QrGateway $qr;
    /** @var callable(string,string):void|null */
    private $probe;

    /** @param callable(string,string):void|null $probe punto de inyección de fallas SÓLO para tests */
    public function __construct(SagaStore $sagas, BridgeStore $bridges, GlpiAssetGateway $glpi, QrGateway $qr, ?callable $probe = null)
    {
        $this->sagas = $sagas;
        $this->bridges = $bridges;
        $this->glpi = $glpi;
        $this->qr = $qr;
        $this->probe = $probe;
    }

    /**
     * BRIDGED (o BLOCKED_CONFIG con resume BRIDGED) ⇒ QR_READY. En QR_READY es un no-op (la revalidación previa al ack
     * la hace `revalidateForAck`).
     *
     * @return array{kind:string, why:string, class:string, state:string, resume:?string}
     */
    public function advance(string $uuid, string $tokenSha): array
    {
        $saga = $this->sagas->get($uuid);
        if ($saga === null) {
            return $this->out(self::O_LEASE_LOST, '');
        }
        $state = (string) $saga['state'];
        if ($state === SagaState::QR_READY) {
            return $this->out(self::O_QR_READY, $state);
        }
        if ($state === SagaState::COMPLETED) {
            return $this->out(self::O_LEASE_LOST, $state); // ya cerrada (outbox DONE): nada que hacer, sin escrituras
        }
        $stage = $state === SagaState::BLOCKED_CONFIG ? (string) ($saga['resume_state'] ?? '') : $state;
        if ($stage !== SagaState::BRIDGED) {
            return $this->manual($state, 'etapa inesperada para SI4-3: ' . $state, 'saga_state');
        }

        // 0) ACL de companyqr ANTES de escribir nada.
        $acl = $this->qr->rightsProblem();
        if ($acl !== null) {
            return $this->blocked($state, $acl, 'qr_acl');
        }

        // 1) Vínculos vigentes (puente 1:1 + activo GLPI), sin volver a Snipe.
        $link = $this->links($saga);
        if ($link !== null) {
            return $this->manual($state, $link['why'], $link['class']);
        }
        $itemtype = (string) $saga['glpi_itemtype'];
        $itemsId = (int) $saga['glpi_items_id'];
        $entity = (int) $saga['glpi_entity_id'];
        $tag = (string) $saga['snipe_asset_tag'];
        $recorded = (int) ($saga['qr_code_id'] ?? 0);

        // 2) Código companyqr: get-or-create idempotente por activo, con presupuesto de lease.
        if (!$this->sagas->holds($uuid, $tokenSha, self::QR_WRITE_BUDGET_SEC)) {
            return $this->out(self::O_LEASE_LOST, $state);
        }
        $this->probe('before_qr', $uuid);
        try {
            $meta = $this->qr->ensureForItem($itemtype, $itemsId);
        } catch (QrGatewayException $e) {
            return $e->isConfig() ? $this->blocked($state, 'companyqr: ' . $e->getMessage(), 'qr_acl')
                : $this->manual($state, 'companyqr: ' . $e->getMessage(), 'qr_' . $e->kind);
        }
        $this->probe('after_qr_code', $uuid);
        $chk = QrCodeRules::check($meta, $itemtype, $itemsId, $entity, $tag, $recorded);
        if (!$chk['ok']) {
            return $this->manual($state, $chk['reason'], $chk['class']);
        }
        $codeId = (int) $meta['code_id'];

        // 3) Metadatos NO sensibles (una sola vez; un retry encuentra el mismo código ya registrado).
        if ($recorded === 0) {
            $outcome = ($meta['outcome'] ?? '') === SagaState::OUTCOME_EXISTING ? SagaState::OUTCOME_EXISTING : SagaState::OUTCOME_CREATED;
            try {
                $ok = $this->sagas->transition($uuid, $tokenSha, $state, [
                    'qr_code_id' => $codeId, 'qr_public_code' => (string) $meta['public_code'], 'qr_outcome' => $outcome,
                ], 'qr_code_linked', $outcome . ' código #' . $codeId);
            } catch (\RuntimeException $e) {
                return $this->manual($state, 'no se pudo registrar el código QR: ' . $e->getMessage(), 'qr_link_unique');
            }
            if (!$ok) {
                return $this->out(self::O_LEASE_LOST, $state); // el próximo dueño encontrará el MISMO código
            }
        }
        $this->probe('after_qr_persisted', $uuid);

        // 4) Etiqueta renderizable con el renderer de companyqr (validada y descartada: nunca se persiste).
        try {
            $ready = QrCodeRules::isPdf($this->qr->renderLabelPdf($codeId));
        } catch (QrGatewayException $e) {
            return $e->isConfig() ? $this->blocked($state, 'etiqueta: ' . $e->getMessage(), $e->kind === QrGatewayException::RENDER ? 'qr_label' : 'qr_acl')
                : $this->manual($state, 'etiqueta: ' . $e->getMessage(), 'qr_' . $e->kind);
        }
        if (!$ready) {
            return $this->blocked($state, 'la etiqueta del código #' . $codeId . ' no es un PDF válido', 'qr_label');
        }
        $this->probe('after_label_render', $uuid);

        // 5) QR_READY.
        if (!$this->sagas->transition($uuid, $tokenSha, $state, [
            'state' => SagaState::QR_READY, 'label_ready_at' => SagaStore::NOW, 'resume_state' => null, 'last_error' => null, 'last_error_class' => null,
        ], 'qr_ready', 'código #' . $codeId . ' activo, etiqueta renderizable')) {
            return $this->out(self::O_LEASE_LOST, $state);
        }
        $this->probe('after_qr_ready', $uuid);
        return $this->out(self::O_QR_READY, SagaState::QR_READY);
    }

    /**
     * Revalidación PREVIA AL ACK (ADR-0022 §6): saga en QR_READY y dueño del lease; puente 1:1 y activo GLPI vigentes;
     * el código registrado sigue ACTIVO, del mismo activo y con el mismo `public_code`. Sólo lecturas. Divergencia ⇒
     * MANUAL_REVIEW (sin ack). No vuelve a Snipe.
     *
     * @return array{kind:string, why:string, class:string, state:string, resume:?string} O_QR_READY = se puede confirmar
     */
    public function revalidateForAck(string $uuid, string $tokenSha): array
    {
        $saga = $this->sagas->get($uuid);
        if ($saga === null || (string) $saga['state'] !== SagaState::QR_READY || !$this->sagas->holds($uuid, $tokenSha, 0)) {
            return $this->out(self::O_LEASE_LOST, (string) ($saga['state'] ?? ''));
        }
        $state = SagaState::QR_READY;
        $link = $this->links($saga);
        if ($link !== null) {
            return $this->manual($state, 'antes del ack: ' . $link['why'], $link['class']);
        }
        $codeId = (int) ($saga['qr_code_id'] ?? 0);
        try {
            $meta = $codeId > 0 ? $this->qr->getCode($codeId) : null;
        } catch (QrGatewayException $e) {
            return $e->isConfig() ? $this->blocked($state, 'antes del ack: companyqr: ' . $e->getMessage(), 'qr_acl')
                : $this->manual($state, 'antes del ack: companyqr: ' . $e->getMessage(), 'qr_' . $e->kind);
        }
        $chk = QrCodeRules::check($meta, (string) $saga['glpi_itemtype'], (int) $saga['glpi_items_id'], (int) $saga['glpi_entity_id'],
            (string) $saga['snipe_asset_tag'], $codeId);
        if (!$chk['ok']) {
            return $this->manual($state, 'antes del ack: ' . $chk['reason'], $chk['class']);
        }
        if ((string) ($meta['public_code'] ?? '') !== (string) ($saga['qr_public_code'] ?? '')) {
            return $this->manual($state, 'antes del ack: el código visible cambió desde QR_READY', 'qr_public_code');
        }
        return $this->out(self::O_QR_READY, $state);
    }

    /**
     * Puente 1:1 EXACTO con la saga y activo GLPI vivo en la entidad de la unidad. null = coherente.
     *
     * @param array<string,mixed> $saga
     * @return array{why:string, class:string}|null
     */
    private function links(array $saga): ?array
    {
        $itemtype = (string) ($saga['glpi_itemtype'] ?? '');
        $itemsId = (int) ($saga['glpi_items_id'] ?? 0);
        $entity = (int) ($saga['glpi_entity_id'] ?? -1);
        $bridgeId = (int) ($saga['asset_bridge_id'] ?? 0);
        if ($itemtype === '' || $itemsId <= 0 || $bridgeId <= 0 || (int) ($saga['snipe_asset_id'] ?? 0) <= 0 || (string) ($saga['snipe_asset_tag'] ?? '') === '') {
            return ['why' => 'saga sin activo GLPI, Snipe o puente registrados', 'class' => 'saga_incomplete'];
        }
        $want = [
            'receipt_unit_uuid' => (string) $saga['receipt_unit_uuid'], 'snipe_asset_id' => (int) $saga['snipe_asset_id'],
            'snipe_asset_tag' => (string) $saga['snipe_asset_tag'], 'glpi_itemtype' => $itemtype, 'glpi_items_id' => $itemsId, 'glpi_entity_id' => $entity,
        ];
        $b = BridgeMatcher::classify($this->bridges->findRelated($want['receipt_unit_uuid'], $want['snipe_asset_id'], $want['snipe_asset_tag'], $itemtype, $itemsId), $want);
        if ($b['kind'] !== BridgeMatcher::EXACT || (int) $b['id'] !== $bridgeId) {
            return ['why' => 'asset_bridge ya no coincide con la saga (' . $b['kind'] . ' ' . $b['detail'] . ')', 'class' => 'bridge_diverged'];
        }
        $cur = $this->glpi->get($itemtype, $itemsId);
        if ($cur === null || $cur['is_deleted'] === 1 || $cur['entities_id'] !== $entity) {
            return ['why' => 'el activo GLPI #' . $itemsId . ' ya no es válido (borrado, en la papelera o en otra entidad)', 'class' => 'glpi_asset_diverged'];
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function manual(string $state, string $why, string $class): array
    {
        return $this->out(self::O_MANUAL, $state, $why, $class);
    }

    /** @return array<string,mixed> */
    private function blocked(string $state, string $why, string $class): array
    {
        return $this->out(self::O_BLOCKED, $state, $why, $class, SagaState::BRIDGED);
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
