<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_of_creates_immutable_amount(): void
    {
        $m = Money::of(1250, 'GHS');

        $this->assertSame(1250, $m->amountMinor());
        $this->assertSame('GHS', $m->currency());
        $this->assertFalse($m->isZero());
        $this->assertTrue($m->isPositive());
        $this->assertFalse($m->isNegative());
    }

    public function test_zero(): void
    {
        $z = Money::zero('GHS');
        $this->assertTrue($z->isZero());
        $this->assertSame(0, $z->amountMinor());
    }

    public function test_from_decimal_string(): void
    {
        $m = Money::fromDecimalString('12.34', 'GHS');
        $this->assertSame(1234, $m->amountMinor());

        $neg = Money::fromDecimalString('-0.50', 'GHS');
        $this->assertSame(-50, $neg->amountMinor());
    }

    public function test_from_decimal_string_rejects_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimalString('abc', 'GHS');
    }

    public function test_currency_must_be_three_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'GH');
    }

    public function test_add_and_subtract(): void
    {
        $a = Money::of(1000, 'GHS');
        $b = Money::of(250, 'GHS');

        $this->assertSame(1250, $a->add($b)->amountMinor());
        $this->assertSame(750, $a->subtract($b)->amountMinor());
    }

    public function test_add_rejects_currency_mismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(100, 'GHS')->add(Money::of(100, 'USD'));
    }

    public function test_multiply_by(): void
    {
        $m = Money::of(150, 'GHS');
        $this->assertSame(450, $m->multiplyBy(3)->amountMinor());
    }

    public function test_abs_and_negate(): void
    {
        $neg = Money::of(-500, 'GHS');
        $this->assertSame(500, $neg->abs()->amountMinor());
        $this->assertSame(500, $neg->negate()->amountMinor());
        $this->assertSame(-500, Money::of(500, 'GHS')->negate()->amountMinor());
    }

    public function test_comparison(): void
    {
        $a = Money::of(100, 'GHS');
        $b = Money::of(200, 'GHS');

        $this->assertTrue($a->lessThan($b));
        $this->assertTrue($b->greaterThan($a));
        $this->assertTrue($a->equals(Money::of(100, 'GHS')));
        $this->assertFalse($a->equals(Money::of(100, 'USD')));
    }

    public function test_to_decimal_string_is_display_only(): void
    {
        $this->assertSame('12.34', Money::of(1234, 'GHS')->toDecimalString());
        $this->assertSame('-0.05', Money::of(-5, 'GHS')->toDecimalString());
    }

    public function test_json_serializes_to_minor_units(): void
    {
        $this->assertSame(1234, Money::of(1234, 'GHS')->jsonSerialize());
        $this->assertSame(1234, json_decode(json_encode(Money::of(1234, 'GHS')), true));
    }

    public function test_string_cast(): void
    {
        $this->assertSame('10.00 GHS', (string) Money::of(1000, 'GHS'));
    }
}
