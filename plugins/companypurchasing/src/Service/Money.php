<?php

/**
 * Value object monetario EXACTO (gate §Dinero).
 *
 * - Nunca usa `float`. El importe se recibe/entrega como **string decimal exacto**.
 * - Valida la escala permitida por moneda (`CurrencyPolicy`); PYG ⇒ 0 decimales. Un importe con más
 *   decimales de los permitidos **falla la validación** (no se redondea en silencio).
 * - Suma y multiplicación por entero son exactas (`Decimal`, aritmética de strings).
 * - Baseline de almacenamiento: `DECIMAL(20,6)` + `currency_code` (la escala interna de `Decimal` es 6).
 *
 * Inmutable: cada operación devuelve una nueva instancia.
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Companypurchasing\Service;

final class Money
{
    private string $micro;    // micro-unidades (entero no negativo, escala 6)
    private string $currency; // ISO-4217 (3 letras, mayúsculas)
    private int $scale;       // escala de presentación/validación de la moneda

    private function __construct(string $micro, string $currency, int $scale)
    {
        $this->micro    = $micro;
        $this->currency = $currency;
        $this->scale    = $scale;
    }

    /**
     * Construye un importe validado. FAIL-CLOSED ante moneda o escala inválidas.
     *
     * @param array<string,int> $scaleOverrides
     * @throws \InvalidArgumentException
     */
    public static function of(string $amount, string $currency, array $scaleOverrides = []): self
    {
        $currency = strtoupper(trim($currency));
        if (!CurrencyPolicy::isWellFormed($currency)) {
            throw new \InvalidArgumentException("moneda inválida: '{$currency}'");
        }
        $scale  = CurrencyPolicy::scale($currency, $scaleOverrides);
        $amount = trim($amount);
        if ($amount === '' || !Decimal::isValid($amount, $scale)) {
            throw new \InvalidArgumentException("importe inválido para {$currency} (escala {$scale}): '{$amount}'");
        }
        return new self(Decimal::toMicro($amount), $currency, $scale);
    }

    /** @param array<string,int> $scaleOverrides */
    public static function zero(string $currency, array $scaleOverrides = []): self
    {
        return self::of('0', $currency, $scaleOverrides);
    }

    /**
     * Reconstruye un importe leído de un `DECIMAL(20,6)` (que MySQL devuelve con 6 decimales) a la
     * escala de la moneda. FAIL-CLOSED: si el valor almacenado tuviera dígitos significativos más allá
     * de la escala permitida por la moneda, lanza (nunca redondea).
     *
     * @param array<string,int> $scaleOverrides
     * @throws \InvalidArgumentException|\RuntimeException
     */
    public static function ofStored(string $stored, string $currency, array $scaleOverrides = []): self
    {
        $currency = strtoupper(trim($currency));
        if (!CurrencyPolicy::isWellFormed($currency)) {
            throw new \InvalidArgumentException("moneda inválida: '{$currency}'");
        }
        $scale  = CurrencyPolicy::scale($currency, $scaleOverrides);
        $stored = trim($stored);
        if ($stored === '' || !Decimal::isValid($stored, Decimal::SCALE)) {
            throw new \InvalidArgumentException("valor almacenado inválido: '{$stored}'");
        }
        $money = new self(Decimal::toMicro($stored), $currency, $scale);
        $money->amount(); // dispara formatMicro → lanza si perdería dígitos significativos
        return $money;
    }

    /** Importe como string decimal EXACTO a la escala de la moneda (lo que consume la API/Firma). */
    public function amount(): string
    {
        return Decimal::formatMicro($this->micro, $this->scale);
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    /** Representación interna en micro-unidades (para persistir como DECIMAL o comparar). */
    public function microUnits(): string
    {
        return $this->micro;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self(Decimal::addStr($this->micro, $other->micro), $this->currency, $this->scale);
    }

    /**
     * Resta exacta. FAIL-CLOSED: lanza si el resultado fuera negativo (p. ej. un descuento mayor que el
     * subtotal): un total comercial negativo nunca se produce en silencio.
     */
    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self(Decimal::subStr($this->micro, $other->micro), $this->currency, $this->scale);
    }

    /** -1 / 0 / 1 (misma moneda). */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);
        return Decimal::cmpStr($this->micro, $other->micro);
    }

    /** Multiplica por una cantidad ENTERA no negativa (p. ej. precio unitario × unidades). */
    public function timesInt(int $n): self
    {
        return new self(Decimal::mulStrByInt($this->micro, $n), $this->currency, $this->scale);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->micro === $other->micro;
    }

    /**
     * Suma una lista de importes de la MISMA moneda.
     *
     * @param array<int,self> $moneys
     * @param array<string,int> $scaleOverrides
     */
    public static function sum(array $moneys, string $currency, array $scaleOverrides = []): self
    {
        $acc = self::zero($currency, $scaleOverrides);
        foreach ($moneys as $m) {
            $acc = $acc->plus($m);
        }
        return $acc;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException("no se pueden operar monedas distintas ({$this->currency} vs {$other->currency})");
        }
    }
}
