<?php

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * Immutable value object for monetary amounts stored as BIGINT minor units.
 *
 * Convention (config/omnis.php + 03-api.md):
 * - Scale 2 → 1 GHS = 100 pesewas.
 * - API fields end in Minor / _minor; never floats on the transaction path.
 */
final class Money implements JsonSerializable, Stringable
{
    private function __construct(
        private readonly int $amountMinor,
        private readonly string $currency,
    ) {
    }

    public static function of(int $amountMinor, string $currency = 'GHS'): self
    {
        $currency = strtoupper(trim($currency));

        if ($currency === '') {
            throw new InvalidArgumentException('Currency must not be empty.');
        }

        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO code.');
        }

        return new self($amountMinor, $currency);
    }

    public static function zero(string $currency = 'GHS'): self
    {
        return self::of(0, $currency);
    }

    /**
     * Parse a decimal string (e.g. "1234.56") into minor units.
     * Used only at system boundaries (import, display conversion); internal paths use integers.
     */
    public static function fromDecimalString(string $decimal, string $currency = 'GHS', int $scale = 2): self
    {
        $decimal = trim($decimal);

        if ($decimal === '' || ! is_numeric($decimal)) {
            throw new InvalidArgumentException("Invalid decimal amount: {$decimal}");
        }

        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');

        if (! preg_match('/^\d+(\.\d+)?$/', $decimal)) {
            throw new InvalidArgumentException("Invalid decimal amount: {$decimal}");
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');

        $minor = (int) $whole * (10 ** $scale) + (int) $fraction;

        if ($negative) {
            $minor = -$minor;
        }

        return self::of($minor, $currency);
    }

    public function amountMinor(): int
    {
        return $this->amountMinor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    public function isNegative(): bool
    {
        return $this->amountMinor < 0;
    }

    public function abs(): self
    {
        return $this->amountMinor < 0
            ? self::of(-$this->amountMinor, $this->currency)
            : $this;
    }

    public function negate(): self
    {
        return self::of(-$this->amountMinor, $this->currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of($this->amountMinor - $other->amountMinor, $this->currency);
    }

    /**
     * Multiply by an integer factor (e.g. quantity). Result remains integer minor units.
     */
    public function multiplyBy(int $factor): self
    {
        return self::of($this->amountMinor * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor
            && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor > $other->amountMinor;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor < $other->amountMinor;
    }

    /**
     * Format for display only. Never use the result for further calculation.
     */
    public function toDecimalString(int $scale = 2): string
    {
        $sign = $this->amountMinor < 0 ? '-' : '';
        $abs = abs($this->amountMinor);
        $divisor = 10 ** $scale;
        $whole = intdiv($abs, $divisor);
        $fraction = str_pad((string) ($abs % $divisor), $scale, '0', STR_PAD_LEFT);

        return $sign.$whole.'.'.$fraction;
    }

    public function jsonSerialize(): int
    {
        return $this->amountMinor;
    }

    public function __toString(): string
    {
        return $this->toDecimalString().' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Currency mismatch: {$this->currency} vs {$other->currency}"
            );
        }
    }
}
