<?php

declare(strict_types=1);

namespace Tests\Unit\Product\Pricing;

use App\Product\Pricing\Currency;
use App\Product\Pricing\Money;
use App\Product\Pricing\ProductQuote;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductQuoteTest extends TestCase
{
    public function test_two_units_at_19_99_eur_total_39_98(): void
    {
        $unit = Money::parse('19.99', Currency::EUR);

        $total = ProductQuote::total($unit, 2);

        $this->assertSame(3998, $total->minor);
        $this->assertSame('39.98', $total->format());
        $this->assertSame(Currency::EUR, $total->currency);
        $this->assertSame(1999, $unit->minor);
    }

    public function test_keeps_ron_currency(): void
    {
        $this->assertSame(Currency::RON, ProductQuote::total(Money::parse('5', Currency::RON), 3)->currency);
    }

    public function test_zero_priced_product_totals_zero(): void
    {
        $this->assertSame(0, ProductQuote::total(Money::zero(Currency::EUR), 100)->minor);
    }

    /** @return iterable<string, array{int, int}> */
    public static function allowedQuantities(): iterable
    {
        yield 'minimum' => [1, 1999];
        yield 'maximum' => [100, 199900];
    }

    #[DataProvider('allowedQuantities')]
    public function test_accepts_quantity_boundaries(int $quantity, int $expectedMinor): void
    {
        $this->assertSame($expectedMinor, ProductQuote::total(Money::ofMinor(1999, Currency::EUR), $quantity)->minor);
    }

    /** @return iterable<string, array{int}> */
    public static function rejectedQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'above maximum' => [101];
        yield 'int min' => [PHP_INT_MIN];
        yield 'int max' => [PHP_INT_MAX];
    }

    #[DataProvider('rejectedQuantities')]
    public function test_rejects_quantity_outside_policy(int $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProductQuote::total(Money::ofMinor(1999, Currency::EUR), $quantity);
    }

    public function test_overflow_with_valid_quantity_fails_without_a_total(): void
    {
        $unit = Money::ofMinor(intdiv(PHP_INT_MAX, 100) + 1, Currency::EUR);

        try {
            ProductQuote::total($unit, 100);
            $this->fail('Expected OverflowException.');
        } catch (OverflowException) {
        }

        $this->assertSame(intdiv(PHP_INT_MAX, 100) + 1, $unit->minor);
    }

    public function test_largest_unit_price_for_max_quantity_still_quotes(): void
    {
        $unit = Money::ofMinor(intdiv(PHP_INT_MAX, 100), Currency::EUR);

        $this->assertSame(intdiv(PHP_INT_MAX, 100) * 100, ProductQuote::total($unit, 100)->minor);
    }
}
