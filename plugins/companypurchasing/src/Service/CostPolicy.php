<?php

/**
 * Política de COSTO ATRIBUIBLE por unidad (P2D-3; gate §6) como VALUE OBJECT inmutable y PURO.
 *
 * - `final_unit_price` de la línea de la cotización seleccionada se incluye SIEMPRE (base del costo).
 * - Ajustes de CABECERA de la cotización (`discounts`, `taxes`, `freight`) se incluyen SÓLO si la política lo
 *   dice (`include_*`). Por defecto NINGUNO: el prorrateo nunca es el método por defecto (gate §6).
 * - Un ajuste incluido se asigna a las líneas por `line_value` (precio final × cantidad) con el método
 *   determinista `line_value_largest_remainder` (ver `CostAllocator`).
 * - `currency` + `currency_scale`: la moneda de la compra y la escala EFECTIVA en el instante del pinneo
 *   (PYG ⇒ 0 siempre). Todo costo de esa compra (asignación, reparto por unidad, payload del handoff) usa esta
 *   escala; un cambio posterior de `currency_scale_overrides` sólo afecta compras NUEVAS.
 *
 * Se PINNEA por solicitud al iniciar la compra (`requests.cost_policies_id`), ANTES de la primera unidad:
 * una edición posterior de la configuración no altera costos de compras ya iniciadas. `canonical()` + `hash()`
 * dan la identidad del contenido (misma política ⇒ misma versión).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class CostPolicy
{
    public const SCHEMA = 1;

    /** Único método de asignación de ajustes de cabecera soportado (documentado en `CostAllocator`). */
    public const ALLOCATION = 'line_value_largest_remainder';

    /** Reparto del costo de línea entre sus unidades (documentado en `CostAllocator::unitCost`). */
    public const UNIT_SPLIT = 'floor_remainder_to_first_units';

    public const FLAGS = ['include_discounts', 'include_taxes', 'include_freight'];

    /** @var array<string,mixed> */
    private array $data;
    private int $id;

    /** @param array<string,mixed> $data ya normalizada */
    private function __construct(array $data, int $id)
    {
        $this->data = $data;
        $this->id   = $id;
    }

    /**
     * Construye y VALIDA (fail-closed). Acepta flags como bool/0/1/'0'/'1' y la escala como int o dígitos.
     *
     * @param array<string,mixed> $raw
     * @throws \RuntimeException
     */
    public static function fromArray(array $raw, int $id = 0): self
    {
        $data = ['schema' => (int) ($raw['schema'] ?? self::SCHEMA)];
        if ($data['schema'] !== self::SCHEMA) {
            throw new \RuntimeException('política de costo: schema desconocido (fail-closed)');
        }
        $currency = $raw['currency'] ?? null;
        if (!is_string($currency) || !CurrencyPolicy::isWellFormed($currency)) {
            throw new \RuntimeException("política de costo: falta 'currency' o no es ISO-4217 en mayúsculas (fail-closed)");
        }
        $scale = $raw['currency_scale'] ?? null;
        if (is_string($scale) && preg_match('/^\d$/', $scale) === 1) {
            $scale = (int) $scale;
        }
        if (!is_int($scale) || !CurrencyPolicy::allows($currency, $scale)) {
            throw new \RuntimeException("política de costo: 'currency_scale' ausente o no admisible para {$currency} (PYG ⇒ 0) (fail-closed)");
        }
        $data['currency'] = $currency;
        $data['currency_scale'] = $scale;
        foreach (self::FLAGS as $flag) {
            if (!array_key_exists($flag, $raw)) {
                throw new \RuntimeException("política de costo: falta '{$flag}' (fail-closed)");
            }
            $v = $raw[$flag];
            if (!in_array($v, [true, false, 0, 1, '0', '1'], true)) {
                throw new \RuntimeException("política de costo: '{$flag}' debe ser 0/1 (fail-closed)");
            }
            $data[$flag] = (int) (bool) (int) $v;
        }
        $alloc = (string) ($raw['allocation'] ?? self::ALLOCATION);
        $split = (string) ($raw['unit_split'] ?? self::UNIT_SPLIT);
        if ($alloc !== self::ALLOCATION || $split !== self::UNIT_SPLIT) {
            throw new \RuntimeException('política de costo: método de asignación no soportado (fail-closed)');
        }
        $data['allocation'] = $alloc;
        $data['unit_split'] = $split;
        ksort($data, SORT_STRING);
        return new self($data, $id);
    }

    /** JSON canónico (claves ordenadas, sin floats). */
    public function canonical(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonical());
    }

    public function id(): int
    {
        return $this->id;
    }

    public function currency(): string
    {
        return $this->data['currency'];
    }

    /** Escala monetaria PINNEADA de la compra (PYG ⇒ 0). */
    public function currencyScale(): int
    {
        return $this->data['currency_scale'];
    }

    /**
     * Mapa de escalas para `Money` con SÓLO la escala pinneada: los importes de esta compra nunca vuelven a
     * depender de `PluginConfig::currencyScaleOverrides()`.
     *
     * @return array<string,int>
     */
    public function scaleOverrides(): array
    {
        return [$this->data['currency'] => $this->data['currency_scale']];
    }

    public function includeDiscounts(): bool
    {
        return $this->data['include_discounts'] === 1;
    }

    public function includeTaxes(): bool
    {
        return $this->data['include_taxes'] === 1;
    }

    public function includeFreight(): bool
    {
        return $this->data['include_freight'] === 1;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
