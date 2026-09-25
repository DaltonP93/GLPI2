<?php

/**
 * Política de aprobación P2D-2 como VALUE OBJECT inmutable y PURO (unit-testable, sin GLPI).
 *
 * Contiene las reglas que definen la semántica del circuito de UNA solicitud:
 *   - `stage_scopes`       etapa de aprobación → scope que protege;
 *   - `scope_checkpoints`  scope → estado del motor que se reabre si ese scope cambia tras aprobarse;
 *   - `pdf_stages`         etapas cuya aprobación compone el PDF;
 *   - `quote_states`       estados donde Compras gestiona cotizaciones;
 *   - `amend_states`       estados donde Compras puede enmendar cantidades.
 *
 * Se PINNEA por solicitud (`requests.policies_id`, ver `PolicyStore`) al abandonar DRAFT: un cambio
 * administrativo posterior de la configuración NO altera retroactivamente solicitudes ya iniciadas.
 * `canonical()` + `hash()` dan una identidad determinista del contenido (misma política ⇒ misma versión).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class ApprovalPolicy
{
    public const MAP_KEYS  = ['stage_scopes', 'scope_checkpoints'];
    public const LIST_KEYS = ['pdf_stages', 'quote_states', 'amend_states'];

    /** @var array<string,mixed> */
    private array $data;
    private int $id;

    /** @param array<string,mixed> $data  ya normalizada y validada */
    private function __construct(array $data, int $id)
    {
        $this->data = $data;
        $this->id   = $id;
    }

    /**
     * Construye y VALIDA (fail-closed) una política a partir de sus reglas.
     *
     * @param array<string,mixed> $raw
     * @throws \RuntimeException
     */
    public static function fromArray(array $raw, int $id = 0): self
    {
        $data = self::normalize($raw);
        PurchasingWorkflow::validateMaps(
            $data['stage_scopes'],
            $data['scope_checkpoints'],
            [ScopeCatalog::SCOPE_REQUEST, ScopeCatalog::SCOPE_COMMERCIAL_FINANCIAL]
        );
        $known = array_merge(PurchasingWorkflow::STAGES, PurchasingWorkflow::EDITABLE, PurchasingWorkflow::FINAL);
        foreach (self::LIST_KEYS as $k) {
            $unknown = array_diff($data[$k], $known);
            if ($unknown !== []) {
                throw new \RuntimeException("política: '{$k}' con estados desconocidos: " . implode(', ', $unknown) . ' (fail-closed)');
            }
        }
        if (array_diff($data['pdf_stages'], PurchasingWorkflow::APPROVAL_STAGES) !== []) {
            throw new \RuntimeException('política: pdf_stages sólo admite etapas de aprobación (fail-closed)');
        }
        // P2D-3: en la fase de compra/recepción el contenido aprobado está CONGELADO; ninguna política puede
        // habilitar cotizar/enmendar ahí.
        foreach (['quote_states', 'amend_states'] as $k) {
            if (array_intersect($data[$k], PurchasingWorkflow::PURCHASE_STATES) !== []) {
                throw new \RuntimeException("política: '{$k}' no admite estados de la fase de compra/recepción (fail-closed)");
            }
        }
        return new self($data, $id);
    }

    /**
     * Forma canónica: mapas con claves ordenadas, listas deduplicadas y ordenadas (su orden no tiene
     * semántica), sólo strings. Claves ausentes/inválidas ⇒ excepción.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalize(array $raw): array
    {
        $out = [];
        foreach (self::MAP_KEYS as $k) {
            $v = $raw[$k] ?? null;
            if (!is_array($v) || $v === [] || array_is_list($v)) {
                throw new \RuntimeException("política: '{$k}' debe ser un mapa no vacío (fail-closed)");
            }
            $m = [];
            foreach ($v as $mk => $mv) {
                $m[(string) $mk] = (string) $mv;
            }
            ksort($m, SORT_STRING);
            $out[$k] = $m;
        }
        foreach (self::LIST_KEYS as $k) {
            $v = $raw[$k] ?? null;
            if (!is_array($v) || !array_is_list($v)) {
                throw new \RuntimeException("política: '{$k}' debe ser una lista (fail-closed)");
            }
            $l = array_values(array_unique(array_map('strval', $v)));
            sort($l, SORT_STRING);
            $out[$k] = $l;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** JSON canónico (determinista) de reglas ya normalizadas. @param array<string,mixed> $normalized */
    public static function canonical(array $normalized): string
    {
        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Identidad del contenido. @param array<string,mixed> $normalized */
    public static function hash(array $normalized): string
    {
        return hash('sha256', self::canonical($normalized));
    }

    public function id(): int
    {
        return $this->id;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /** @return array<string,string> */
    public function stageScopes(): array
    {
        return $this->data['stage_scopes'];
    }

    /** @return array<string,string> */
    public function scopeCheckpoints(): array
    {
        return $this->data['scope_checkpoints'];
    }

    /**
     * Scopes con su checkpoint, del MÁS TEMPRANO al más tardío (prioridad de reparación: REQUEST_SCOPE
     * antes que COMMERCIAL; reabrir uno anterior reinicia los posteriores).
     *
     * @return array<string,string>
     */
    public function orderedCheckpoints(): array
    {
        $cp = $this->data['scope_checkpoints'];
        uksort($cp, static function (string $a, string $b) use ($cp): int {
            return [PurchasingWorkflow::stageIndex($cp[$a]), $a] <=> [PurchasingWorkflow::stageIndex($cp[$b]), $b];
        });
        return $cp;
    }

    /** @return array<int,string> */
    public function pdfStages(): array
    {
        return $this->data['pdf_stages'];
    }

    /** @return array<int,string> */
    public function quoteStates(): array
    {
        return $this->data['quote_states'];
    }

    /** @return array<int,string> */
    public function amendStates(): array
    {
        return $this->data['amend_states'];
    }
}
