<?php

/**
 * Definición del PROCESO de compras sobre `companyworkflow` (P2D-2) + reglas PURAS de scopes/checkpoints.
 *
 * `companyworkflow` es el ÚNICO motor: aquí sólo se DESCRIBE el proceso (estados/transiciones) para
 * publicarlo con `DefinitionBuilder::createVersion()`. Aprobadores (grupos), quórum y SLA salen de la
 * CONFIGURACIÓN (nunca hardcodeados): publicar sin grupos configurados falla cerrado.
 *
 * P2D-3 extiende el MISMO proceso (nueva VERSIÓN de la definición; nunca se edita una publicada):
 * `APPROVED →start_purchase→ IN_PURCHASE →receive_partial→ PARTIALLY_RECEIVED →receive_complete→ RECEIVED`
 * (e `IN_PURCHASE →receive_complete→ RECEIVED`). Todos son estados del motor: no hay una segunda máquina de
 * estados. Las transiciones de compra/recepción exigen condiciones (`purchase_bound` / `receipt_bound`) que
 * sólo aporta el código de Compras tras confirmar el hecho local (la superficie HTTP genérica del motor no
 * pasa `fields`, así que no puede forzarlas). RECEIVED es INTERMEDIO (P2D-4 continúa: entrega).
 *
 * Reglas puras (unit-testables, sin GLPI):
 *   - `spec()`                → especificación declarativa de la definición.
 *   - `receivingTarget()`     → estado de recepción que corresponde a los contadores físicos.
 *   - `syncPath()`            → transiciones para llevar el motor del estado actual al objetivo.
 *   - `stageIndex()`          → orden de las etapas de aprobación.
 *   - `resetsScope()`         → ¿entrar a un estado reinicia las aprobaciones de un scope?
 *   - `liveDecisions()`       → decisiones `approved` VIVAS de un scope, derivadas del LEDGER autoritativo
 *                               del motor (no de flags locales que podrían quedar stale).
 *   - `validateMaps()`        → mapas etapa→scope y scope→checkpoint coherentes (fail-closed).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class PurchasingWorkflow
{
    // --- Estados del proceso (códigos de la definición publicada) ---
    public const S_DRAFT             = 'DRAFT';
    public const S_PENDING_AREA_HEAD = 'PENDING_AREA_HEAD';
    public const S_PURCHASING        = 'PURCHASING';
    public const S_PENDING_FINANCE   = 'PENDING_FINANCE';
    public const S_APPROVED          = 'APPROVED';
    public const S_RETURNED          = 'RETURNED';
    public const S_REJECTED          = 'REJECTED';
    public const S_CANCELLED         = 'CANCELLED';
    // P2D-3: fase de compra/recepción (posteriores a APPROVED).
    public const S_IN_PURCHASE        = 'IN_PURCHASE';
    public const S_PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    public const S_RECEIVED           = 'RECEIVED';

    /**
     * Circuito EN ORDEN (índice = posición). Incluye la fase de compra/recepción POSTERIOR a APPROVED: entrar
     * a esos estados NO reinicia aprobaciones (su índice es mayor que el de cualquier checkpoint).
     */
    public const STAGES = [
        self::S_PENDING_AREA_HEAD, self::S_PURCHASING, self::S_PENDING_FINANCE, self::S_APPROVED,
        self::S_IN_PURCHASE, self::S_PARTIALLY_RECEIVED, self::S_RECEIVED,
    ];

    /** Estados de la fase de compra/recepción: el contenido aprobado está CONGELADO. */
    public const PURCHASE_STATES = [self::S_IN_PURCHASE, self::S_PARTIALLY_RECEIVED, self::S_RECEIVED];

    /** Estados del motor en los que se admite registrar una recepción física. */
    public const RECEIVING_STATES = [self::S_IN_PURCHASE, self::S_PARTIALLY_RECEIVED];

    // Acciones P2D-3 (códigos de transición de la definición publicada).
    public const A_START_PURCHASE   = 'start_purchase';
    public const A_RECEIVE_PARTIAL  = 'receive_partial';
    public const A_RECEIVE_COMPLETE = 'receive_complete';

    /** Condición: el inicio de compra (congelamiento local) ya está confirmado por Compras. */
    public const PURCHASE_CONDITION = ['field' => 'purchase_bound', 'op' => 'eq', 'value' => 1];

    /** Condición: el avance de recepción está respaldado por contadores físicos confirmados. */
    public const RECEIPT_CONDITION = ['field' => 'receipt_bound', 'op' => 'eq', 'value' => 1];

    /** Etapas con paso de aprobación (grupo + quórum configurables). */
    public const APPROVAL_STAGES = [self::S_PENDING_AREA_HEAD, self::S_PURCHASING, self::S_PENDING_FINANCE];

    /** Estados editables por el solicitante (autoridad: `is_editable` del estado en el motor). */
    public const EDITABLE = [self::S_DRAFT, self::S_RETURNED];

    /** Estados finales (instancia cerrada). */
    public const FINAL = [self::S_REJECTED, self::S_CANCELLED];

    /** Condición que exige a toda aprobación venir ligada a evidencia (la aporta el orquestador). */
    public const EVIDENCE_CONDITION = ['field' => 'evidence_bound', 'op' => 'eq', 'value' => 1];

    // Valores de TransitionResult / HistoryEvent / StateDef / Step / WorkflowDef del motor, como literales
    // para que este archivo sea PURO (se verifican contra las constantes reales en el selftest).
    public const WF_KIND_INITIAL      = 'initial';
    public const WF_KIND_INTERMEDIATE = 'intermediate';
    public const WF_KIND_FINAL        = 'final';
    public const WF_QUORUM_COUNT      = 'count';
    public const WF_APPROVER_GROUP    = 'group';
    public const WF_RIGHT_ACT         = 2;
    public const EV_STARTED           = 'started';
    public const EV_TRANSITIONED      = 'transitioned';
    public const EV_INVALIDATED       = 'approval_invalidated';
    public const EV_DECISION          = 'decision_recorded';
    public const DECISION_APPROVED    = 'approved';

    /**
     * Especificación declarativa para `DefinitionBuilder::createVersion()`.
     *
     * @param array{groups:array<string,int>, quorum?:array<string,int>, sla?:array<string,?int>} $cfg
     *        `groups` = grupo aprobador por etapa de aprobación (> 0, obligatorio).
     * @return array<string,mixed>
     * @throws \InvalidArgumentException  configuración incompleta (fail-closed, sin publicar)
     */
    public static function spec(string $code, string $itemtype, array $cfg): array
    {
        if (preg_match('/^[A-Za-z0-9_\-]{3,100}$/', $code) !== 1) {
            throw new \InvalidArgumentException('workflow_code inválido');
        }
        $steps = [];
        foreach (self::APPROVAL_STAGES as $stage) {
            $group = (int) ($cfg['groups'][$stage] ?? 0);
            if ($group <= 0) {
                throw new \InvalidArgumentException("grupo aprobador no configurado para {$stage} (fail-closed)");
            }
            $quorum = (int) ($cfg['quorum'][$stage] ?? 1);
            if ($quorum < 1) {
                throw new \InvalidArgumentException("quórum inválido para {$stage} (fail-closed)");
            }
            $steps[$stage] = [[
                'level'         => 1,
                'quorum_type'   => self::WF_QUORUM_COUNT,
                'quorum_value'  => $quorum,
                'approver_kind' => self::WF_APPROVER_GROUP,
                'approver_ref'  => $group,
            ]];
        }
        $sla = static function (string $stage) use ($cfg): ?int {
            $v = $cfg['sla'][$stage] ?? null;
            return ($v === null || $v === '' || (int) $v <= 0) ? null : (int) $v;
        };
        $state = static fn (string $c, string $kind, int $editable = 0, ?int $slaH = null): array
            => ['code' => $c, 'label' => $c, 'kind' => $kind, 'is_editable' => $editable, 'sla_hours' => $slaH];
        $approve = static fn (string $from, string $to): array => [
            'from' => $from, 'to' => $to, 'action' => 'approve', 'required_right' => self::WF_RIGHT_ACT,
            'condition' => self::EVIDENCE_CONDITION, 'steps' => $steps[$from],
        ];
        $decide = static fn (string $from, string $to, string $action): array => [
            'from' => $from, 'to' => $to, 'action' => $action, 'required_right' => self::WF_RIGHT_ACT,
            'requires_comment' => 1,
        ];
        // P2D-3: sin paso de aprobación ni derecho extra del motor (READ): la autorización de negocio la da
        // Compras (MANAGE_PURCHASING / RECEIVE) y la CONDICIÓN impide dispararlas desde fuera de Compras.
        $bound = static fn (string $from, string $to, string $action, array $condition): array => [
            'from' => $from, 'to' => $to, 'action' => $action, 'condition' => $condition,
        ];

        return [
            'code'            => $code,
            'name'            => 'Purchasing request',
            'itemtype_target' => $itemtype,
            'entities_id'     => 0,
            'is_recursive'    => 1,
            'states'          => [
                $state(self::S_DRAFT, self::WF_KIND_INITIAL, 1),
                $state(self::S_PENDING_AREA_HEAD, self::WF_KIND_INTERMEDIATE, 0, $sla(self::S_PENDING_AREA_HEAD)),
                $state(self::S_PURCHASING, self::WF_KIND_INTERMEDIATE, 0, $sla(self::S_PURCHASING)),
                $state(self::S_PENDING_FINANCE, self::WF_KIND_INTERMEDIATE, 0, $sla(self::S_PENDING_FINANCE)),
                // APPROVED es INTERMEDIO: P2D-3 continúa desde aquí y la invalidación post-aprobación
                // (instancia abierta) sigue siendo posible.
                $state(self::S_APPROVED, self::WF_KIND_INTERMEDIATE),
                $state(self::S_RETURNED, self::WF_KIND_INTERMEDIATE, 1),
                // P2D-3: fase de compra/recepción. RECEIVED es INTERMEDIO (la entrega llega en P2D-4).
                $state(self::S_IN_PURCHASE, self::WF_KIND_INTERMEDIATE),
                $state(self::S_PARTIALLY_RECEIVED, self::WF_KIND_INTERMEDIATE),
                $state(self::S_RECEIVED, self::WF_KIND_INTERMEDIATE),
                $state(self::S_REJECTED, self::WF_KIND_FINAL),
                $state(self::S_CANCELLED, self::WF_KIND_FINAL),
            ],
            'transitions'     => [
                ['from' => self::S_DRAFT, 'to' => self::S_PENDING_AREA_HEAD, 'action' => 'submit'],
                ['from' => self::S_RETURNED, 'to' => self::S_PENDING_AREA_HEAD, 'action' => 'submit'],
                ['from' => self::S_DRAFT, 'to' => self::S_CANCELLED, 'action' => 'cancel'],
                ['from' => self::S_RETURNED, 'to' => self::S_CANCELLED, 'action' => 'cancel'],
                $approve(self::S_PENDING_AREA_HEAD, self::S_PURCHASING),
                $decide(self::S_PENDING_AREA_HEAD, self::S_REJECTED, 'reject'),
                $decide(self::S_PENDING_AREA_HEAD, self::S_RETURNED, 'return'),
                $approve(self::S_PURCHASING, self::S_PENDING_FINANCE),
                $decide(self::S_PURCHASING, self::S_REJECTED, 'reject'),
                $decide(self::S_PURCHASING, self::S_RETURNED, 'return'),
                $approve(self::S_PENDING_FINANCE, self::S_APPROVED),
                $decide(self::S_PENDING_FINANCE, self::S_REJECTED, 'reject'),
                // Gerencia devuelve a Compras (re-cotizar), no al solicitante.
                $decide(self::S_PENDING_FINANCE, self::S_PURCHASING, 'return'),
                // P2D-3: compra y recepción (proyección del hecho físico confirmado por Compras).
                $bound(self::S_APPROVED, self::S_IN_PURCHASE, self::A_START_PURCHASE, self::PURCHASE_CONDITION),
                $bound(self::S_IN_PURCHASE, self::S_PARTIALLY_RECEIVED, self::A_RECEIVE_PARTIAL, self::RECEIPT_CONDITION),
                $bound(self::S_IN_PURCHASE, self::S_RECEIVED, self::A_RECEIVE_COMPLETE, self::RECEIPT_CONDITION),
                $bound(self::S_PARTIALLY_RECEIVED, self::S_RECEIVED, self::A_RECEIVE_COMPLETE, self::RECEIPT_CONDITION),
            ],
        ];
    }

    /**
     * Índice de etapa: 0..3 en el circuito de aprobación, 4..6 en la fase de compra/recepción; -1 = antes del
     * circuito (DRAFT/RETURNED); -2 = final/desconocido.
     */
    public static function stageIndex(string $code): int
    {
        $i = array_search($code, self::STAGES, true);
        if ($i !== false) {
            return (int) $i;
        }
        return in_array($code, self::EDITABLE, true) ? -1 : -2;
    }

    /**
     * ¿Entrar al estado `$toCode` REINICIA las aprobaciones del scope cuyo checkpoint es `$checkpoint`?
     * Sí si se entra AL checkpoint o a un estado ANTERIOR del circuito (DRAFT/RETURNED incluidos).
     * Un estado final o desconocido también reinicia (conservador: nada queda "vivo").
     */
    public static function resetsScope(string $toCode, string $checkpoint): bool
    {
        $to = self::stageIndex($toCode);
        $cp = self::stageIndex($checkpoint);
        if ($to === -2) {
            return true;
        }
        return $to <= $cp;
    }

    /**
     * Decisiones `approved` VIVAS de un scope, derivadas del LEDGER del motor (fuente de verdad):
     * decisiones tomadas —en una etapa cuyo scope es `$scope`— durante una VISITA de estado iniciada en o
     * después de la última entrada de la instancia en el checkpoint del scope (o en un estado anterior).
     * Orden causal (id ascendente).
     *
     * @param array<int,array<string,mixed>> $rows        filas de `WorkflowApi::history()` de la instancia
     * @param array<string,string>           $stageScopes etapa → scope
     * @return array<int,array{id:int, from_code:string, meta:array<string,mixed>}>
     */
    public static function liveDecisions(array $rows, string $scope, string $checkpoint, array $stageScopes): array
    {
        usort($rows, static fn (array $a, array $b): int => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        $lastReset = 0;
        foreach ($rows as $r) {
            $ev = (string) ($r['event'] ?? '');
            if (in_array($ev, [self::EV_STARTED, self::EV_TRANSITIONED, self::EV_INVALIDATED], true)
                && self::resetsScope((string) ($r['to_code'] ?? ''), $checkpoint)) {
                $lastReset = (int) $r['id'];
            }
        }
        $live = [];
        foreach ($rows as $r) {
            if ((string) ($r['event'] ?? '') !== self::EV_DECISION) {
                continue;
            }
            $meta = self::meta($r);
            if ((string) ($meta['decision'] ?? '') !== self::DECISION_APPROVED) {
                continue;
            }
            $from = (string) ($r['from_code'] ?? '');
            if (($stageScopes[$from] ?? null) !== $scope) {
                continue;
            }
            // Viva si se tomó en una VISITA de estado iniciada en/después del último reinicio. (El motor
            // registra la decisión de un actor único DESPUÉS de la fila `transitioned`; en quórum, ANTES:
            // el orden de ids por sí solo no alcanza.) Visita no determinable ⇒ viva (conservador: se
            // verifica su contenido y, ante duda, se invalida).
            $visit = self::visitStart($rows, (int) $r['id'], $from);
            if ($visit !== 0 && $visit < $lastReset) {
                continue;
            }
            $live[] = ['id' => (int) $r['id'], 'from_code' => $from, 'meta' => $meta];
        }
        return $live;
    }

    /**
     * Inicio de la visita de estado `$fromCode` en la que se registró la fila `$decisionId`: la última
     * entrada (started/transitioned/approval_invalidated) a ese estado ANTERIOR a la decisión. 0 = no
     * determinable. `$rows` ya ordenadas por id.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    private static function visitStart(array $rows, int $decisionId, string $fromCode): int
    {
        $start = 0;
        foreach ($rows as $r) {
            $id = (int) ($r['id'] ?? 0);
            if ($id >= $decisionId) {
                break;
            }
            if (in_array((string) ($r['event'] ?? ''), [self::EV_STARTED, self::EV_TRANSITIONED, self::EV_INVALIDATED], true)
                && (string) ($r['to_code'] ?? '') === $fromCode) {
                $start = $id;
            }
        }
        return $start;
    }

    /**
     * Estado de recepción que corresponde a los CONTADORES FÍSICOS (autoridad del hecho de recepción):
     * 0 recibido ⇒ IN_PURCHASE; 0 < recibido < ordenado (en alguna línea) ⇒ PARTIALLY_RECEIVED; todas las
     * líneas completas ⇒ RECEIVED. FAIL-CLOSED ante contadores imposibles (sin líneas, ordenado < 1,
     * recibido negativo o mayor que lo ordenado).
     *
     * @param array<int,array{ordered_qty:int, received_qty:int}> $lines
     * @throws \RuntimeException
     */
    public static function receivingTarget(array $lines): string
    {
        if ($lines === []) {
            throw new \RuntimeException('solicitud sin líneas: no hay objetivo de recepción (fail-closed)');
        }
        $ordered = 0;
        $received = 0;
        foreach ($lines as $l) {
            $o = (int) ($l['ordered_qty'] ?? 0);
            $r = (int) ($l['received_qty'] ?? 0);
            if ($o < 1 || $r < 0 || $r > $o) {
                throw new \RuntimeException('contadores de recepción inconsistentes (fail-closed)');
            }
            $ordered += $o;
            $received += $r;
        }
        if ($received === 0) {
            return self::S_IN_PURCHASE;
        }
        return $received === $ordered ? self::S_RECEIVED : self::S_PARTIALLY_RECEIVED;
    }

    /**
     * Transiciones (acciones) para llevar el motor de `$current` a `$target` dentro de la fase de compra, en
     * orden, SUPONIENDO que la compra ya se inició localmente (hecho durable). Desde APPROVED primero
     * `start_purchase`. `[]` = ya convergido. `null` = NO convergible hacia adelante (el motor está "más
     * avanzado" que los contadores, o en un estado fuera de la fase): ANOMALÍA a reportar; jamás se retrocede el
     * motor ni se deshacen unidades físicas para seguirlo.
     *
     * @return array<int,string>|null
     */
    public static function syncPath(string $current, string $target): ?array
    {
        if (!in_array($target, self::PURCHASE_STATES, true)) {
            return null;
        }
        if ($current === $target) {
            return [];
        }
        if ($current === self::S_APPROVED) {
            $rest = self::syncPath(self::S_IN_PURCHASE, $target);
            return $rest === null ? null : array_merge([self::A_START_PURCHASE], $rest);
        }
        return match ([$current, $target]) {
            [self::S_IN_PURCHASE, self::S_PARTIALLY_RECEIVED]  => [self::A_RECEIVE_PARTIAL],
            [self::S_IN_PURCHASE, self::S_RECEIVED]            => [self::A_RECEIVE_COMPLETE],
            [self::S_PARTIALLY_RECEIVED, self::S_RECEIVED]     => [self::A_RECEIVE_COMPLETE],
            default                                            => null,
        };
    }

    /** Claves EXACTAS del contrato `evidence_ref` (companysignature `Materializer::resolveRef`). */
    public const EVIDENCE_REF_KEYS = ['document_versions_id', 'document_version', 'content_sha256'];

    /**
     * ¿La `evidence_ref` de una aprobación del ledger del motor corresponde EXACTAMENTE a la fila del ledger
     * propio de Compras para ese scope? Exige las TRES claves con formato válido y que coincidan con
     * `document_versions_id`, `document_version` y `content_sha256` de la fila, que la fila sea del scope
     * indicado y que su `payload_sha256` sea `$expectedPayloadSha`. Ref incompleta/alterada/ajena ⇒ false.
     * PURO.
     *
     * @param mixed                     $ref
     * @param array<string,mixed>|null  $ledgerRow  fila de `..._doc_versions` (o null si no existe)
     */
    public static function evidenceRefMatches(mixed $ref, ?array $ledgerRow, string $scope, string $expectedPayloadSha): bool
    {
        if (!is_array($ref) || $ledgerRow === null) {
            return false;
        }
        foreach (self::EVIDENCE_REF_KEYS as $k) {
            if (!array_key_exists($k, $ref)) {
                return false;
            }
        }
        $dvId = $ref['document_versions_id'];
        $ver  = $ref['document_version'];
        $hash = $ref['content_sha256'];
        if (!is_int($dvId) || !is_int($ver) || $dvId <= 0 || $ver <= 0
            || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            return false;
        }
        return (string) ($ledgerRow['scope_key'] ?? '') === $scope
            && (int) ($ledgerRow['document_version'] ?? 0) === $ver
            && (int) ($ledgerRow['document_versions_id'] ?? 0) === $dvId
            && hash_equals((string) ($ledgerRow['content_sha256'] ?? ''), $hash)
            && $expectedPayloadSha !== ''
            && hash_equals((string) ($ledgerRow['payload_sha256'] ?? ''), $expectedPayloadSha);
    }

    /**
     * Valida mapas configurables (fail-closed): cada etapa de aprobación tiene un scope conocido; cada
     * scope usado tiene checkpoint que es una etapa de aprobación ANTERIOR o IGUAL a las etapas que lo usan.
     *
     * @param array<string,string> $stageScopes
     * @param array<string,string> $checkpoints
     * @param array<int,string>    $knownScopes
     * @throws \RuntimeException
     */
    public static function validateMaps(array $stageScopes, array $checkpoints, array $knownScopes): void
    {
        foreach (self::APPROVAL_STAGES as $stage) {
            $scope = $stageScopes[$stage] ?? null;
            if (!is_string($scope) || !in_array($scope, $knownScopes, true)) {
                throw new \RuntimeException("etapa {$stage} sin scope válido (fail-closed)");
            }
            $cp = $checkpoints[$scope] ?? null;
            if (!is_string($cp) || !in_array($cp, self::APPROVAL_STAGES, true)) {
                throw new \RuntimeException("scope {$scope} sin checkpoint válido (fail-closed)");
            }
            if (self::stageIndex($cp) > self::stageIndex($stage)) {
                throw new \RuntimeException("checkpoint de {$scope} posterior a la etapa {$stage} (fail-closed)");
            }
        }
        foreach (array_keys($stageScopes) as $stage) {
            if (!in_array($stage, self::APPROVAL_STAGES, true)) {
                throw new \RuntimeException("stage_scopes referencia un estado que no es etapa de aprobación: {$stage}");
            }
        }
    }

    /** @return array<string,mixed> */
    private static function meta(array $row): array
    {
        if (isset($row['meta']) && is_array($row['meta'])) {
            return $row['meta'];
        }
        $raw = (string) ($row['meta_json'] ?? '');
        $m = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($m) ? $m : [];
    }
}
