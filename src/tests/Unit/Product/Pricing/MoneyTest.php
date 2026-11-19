<?php

declare(strict_types=1);

namespace Tests\Unit\Product\Pricing;

use App\Product\Pricing\Currency;
use App\Product\Pricing\CurrencyMismatch;
use App\Product\Pricing\Money;
use Error;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function validDecimals(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'zero with two decimals' => ['0.00', 0];
        yield 'zero with one decimal' => ['0.0', 0];
        yield 'one cent' => ['0.01', 1];
        yield 'half' => ['0.5', 50];
        yield 'integer' => ['19', 1900];
        yield 'one decimal' => ['19.9', 1990];
        yield 'two decimals' => ['19.99', 1999];
        yield 'trailing zero kept' => ['10.10', 1010];
        yield 'large' => ['123456789', 12345678900];
    }

    #[DataProvider('validDecimals')]
    public function test_parses_valid_decimal(string $decimal, int $expectedMinor): void
    {
        $money = Money::parse($decimal, Currency::EUR);

        $this->assertSame($expectedMinor, $money->minor);
        $this->assertSame(Currency::EUR, $money->currency);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDecimals(): iterable
    {
        yield 'empty' => [''];
        yield 'negative' => ['-1'];
        yield 'negative decimal' => ['-19.99'];
        yield 'negative zero' => ['-0'];
        yield 'plus sign' => ['+1'];
        yield 'leading space' => [' 19.99'];
        yield 'trailing space' => ['19.99 '];
        yield 'trailing newline' => ["19.99\n"];
        yield 'three fraction digits' => ['19.999'];
        yield 'exponent' => ['1e3'];
        yield 'uppercase exponent' => ['1E3'];
        yield 'leading zero' => ['01'];
        yield 'leading zero with decimals' => ['01.00'];
        yield 'double zero' => ['00'];
        yield 'missing integer part' => ['.50'];
        yield 'missing fraction digits' => ['19.'];
        yield 'comma separator' => ['19,99'];
        yield 'thousands separator' => ['1,000'];
        yield 'underscore separator' => ['1_000'];
        yield 'two dots' => ['1.2.3'];
        yield 'hex' => ['0x1A'];
        yield 'letters' => ['abc'];
        yield 'trailing letter' => ['19.9a'];
        yield 'non-ascii digits' => ['١٩'];
        yield 'nan' => ['NAN'];
        yield 'infinity' => ['INF'];
    }

    #[DataProvider('invalidDecimals')]
    public function test_rejects_malformed_decimal(string $decimal): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse($decimal, Currency::EUR);
    }

    public function test_rejects_extremely_long_digit_string_as_overflow(): void
    {
        $this->expectException(OverflowException::class);

        Money::parse(str_repeat('9', 100_000), Currency::EUR);
    }

    public function test_rejects_extremely_long_malformed_string_as_invalid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::parse(str_repeat('9', 100_000).'x', Currency::EUR);
    }

    public function test_parses_exactly_php_int_max_minor_units(): void
    {
        [$major, $fraction] = self::maxAsMajorAndFraction();

        $money = Money::parse($major.'.'.$fraction, Currency::EUR);

        $this->assertSame(PHP_INT_MAX, $money->minor);
    }

    public function test_rejects_decimal_one_minor_unit_above_php_int_max(): void
    {
        [$major, $fraction] = self::maxAsMajorAndFraction();
        $next = (int) $fraction === 99
            ? ($major + 1).'.00'
            : $major.'.'.str_pad((string) ((int) $fraction + 1), 2, '0', STR_PAD_LEFT);

        $this->expectException(OverflowException::class);

        Money::parse($next, Currency::EUR);
    }

    public function test_rejects_integer_part_above_php_int_max(): void
    {
        $tooBig = ((string) PHP_INT_MAX).'0';

        $this->expectException(OverflowException::class);

        Money::parse($tooBig, Currency::EUR);
    }

    /** @return iterable<string, array{int, string}> */
    public static function formats(): iterable
    {
        yield 'zero' => [0, '0.00'];
        yield 'one cent' => [1, '0.01'];
        yield 'ten cents' => [10, '0.10'];
        yield 'whole' => [1900, '19.00'];
        yield 'typical' => [1999, '19.99'];
        yield 'no locale separators' => [123456789, '1234567.89'];
    }

    #[DataProvider('formats')]
    public function test_formats_with_exactly_two_fraction_digits(int $minor, string $expected): void
    {
        $this->assertSame($expected, Money::ofMinor($minor, Currency::RON)->format());
    }

    /** @return iterable<string, array{int}> */
    public static function roundTripAmounts(): iterable
    {
        yield 'zero' => [0];
        yield 'one cent' => [1];
        yield 'typical' => [1999];
        yield 'max' => [PHP_INT_MAX];
    }

    #[DataProvider('roundTripAmounts')]
    public function test_format_then_parse_round_trips(int $minor): void
    {
        $original = Money::ofMinor($minor, Currency::RON);

        $parsed = Money::parse($original->format(), Currency::RON);

        $this->assertTrue($parsed->equals($original));
        $this->assertSame($minor, $parsed->minor);
    }

    public function test_rejects_negative_minor_units(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::ofMinor(-1, Currency::EUR);
    }

    public function test_accepts_zero_and_php_int_max_minor_units(): void
    {
        $this->assertSame(0, Money::ofMinor(0, Currency::EUR)->minor);
        $this->assertSame(0, Money::zero(Currency::EUR)->minor);
        $this->assertSame(PHP_INT_MAX, Money::ofMinor(PHP_INT_MAX, Currency::EUR)->minor);
    }

    public function test_equality_compares_amount_and_currency(): void
    {
        $eur = Money::ofMinor(1999, Currency::EUR);

        $this->assertTrue($eur->equals(Money::parse('19.99', Currency::EUR)));
        $this->assertFalse($eur->equals(Money::ofMinor(1998, Currency::EUR)));
        $this->assertFalse($eur->equals(Money::ofMinor(1999, Currency::RON)));
        $this->assertTrue(Money::zero(Currency::EUR)->equals(Money::zero(Currency::EUR)));
        $this->assertFalse(Money::zero(Currency::EUR)->equals(Money::zero(Currency::RON)));
    }

    public function test_equal_values_are_distinct_objects(): void
    {
        $a = Money::ofMinor(100, Currency::EUR);
        $b = Money::ofMinor(100, Currency::EUR);

        $this->assertTrue($a->equals($b));
        $this->assertNotSame($a, $b);
    }

    public function test_adds_without_floating_point_error(): void
    {
        $sum = Money::parse('0.1', Currency::EUR)->add(Money::parse('0.2', Currency::EUR));

        $this->assertSame(30, $sum->minor);
        $this->assertSame('0.30', $sum->format());
        $this->assertSame(Currency::EUR, $sum->currency);
    }

    public function test_add_returns_new_value_and_leaves_operands_unchanged(): void
    {
        $a = Money::ofMinor(100, Currency::EUR);
        $b = Money::ofMinor(250, Currency::EUR);

        $sum = $a->add($b);

        $this->assertSame(350, $sum->minor);
        $this->assertNotSame($a, $sum);
        $this->assertSame(100, $a->minor);
        $this->assertSame(250, $b->minor);
    }

    /** @return iterable<string, array{Money, Money}> */
    public static function mismatchedPairs(): iterable
    {
        yield 'eur plus ron' => [Money::ofMinor(100, Currency::EUR), Money::ofMinor(100, Currency::RON)];
        yield 'ron plus eur' => [Money::ofMinor(100, Currency::RON), Money::ofMinor(100, Currency::EUR)];
        yield 'zero eur plus ron' => [Money::zero(Currency::EUR), Money::ofMinor(100, Currency::RON)];
        yield 'eur plus zero ron' => [Money::ofMinor(100, Currency::EUR), Money::zero(Currency::RON)];
    }

    #[DataProvider('mismatchedPairs')]
    public function test_rejects_mixed_currency_addition_and_leaves_operands_unchanged(Money $left, Money $right): void
    {
        $leftBefore = [$left->minor, $left->currency];
        $rightBefore = [$right->minor, $right->currency];

        try {
            $left->add($right);
            $this->fail('Expected CurrencyMismatch.');
        } catch (CurrencyMismatch) {
        }

        $this->assertSame($leftBefore, [$left->minor, $left->currency]);
        $this->assertSame($rightBefore, [$right->minor, $right->currency]);
    }

    public function test_multiplies_by_nonnegative_integer(): void
    {
        $price = Money::ofMinor(1999, Currency::RON);

        $this->assertSame(3998, $price->multiply(2)->minor);
        $this->assertSame(Currency::RON, $price->multiply(2)->currency);
        $this->assertSame(1999, $price->multiply(1)->minor);
        $this->assertSame(0, $price->multiply(0)->minor);
        $this->assertSame(1999, $price->minor);
    }

    public function test_zero_times_anything_is_zero(): void
    {
        $this->assertSame(0, Money::zero(Currency::EUR)->multiply(PHP_INT_MAX)->minor);
    }

    public function test_rejects_negative_multiplier_and_leaves_value_unchanged(): void
    {
        $price = Money::ofMinor(1999, Currency::EUR);

        try {
            $price->multiply(-1);
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(1999, $price->minor);
    }

    public function test_adding_zero_to_max_succeeds(): void
    {
        $max = Money::ofMinor(PHP_INT_MAX, Currency::EUR);

        $this->assertSame(PHP_INT_MAX, $max->add(Money::zero(Currency::EUR))->minor);
        $this->assertSame(PHP_INT_MAX, Money::zero(Currency::EUR)->add($max)->minor);
    }

    public function test_adding_one_to_max_overflows_and_leaves_operands_unchanged(): void
    {
        $max = Money::ofMinor(PHP_INT_MAX, Currency::EUR);
        $one = Money::ofMinor(1, Currency::EUR);

        try {
            $max->add($one);
            $this->fail('Expected OverflowException.');
        } catch (OverflowException) {
        }

        $this->assertSame(PHP_INT_MAX, $max->minor);
        $this->assertSame(1, $one->minor);
    }

    public function test_multiplying_max_by_one_succeeds(): void
    {
        $this->assertSame(PHP_INT_MAX, Money::ofMinor(PHP_INT_MAX, Currency::EUR)->multiply(1)->minor);
    }

    public function test_multiplication_at_exact_boundary_succeeds_and_one_above_overflows(): void
    {
        $largestSafe = intdiv(PHP_INT_MAX, 2);

        $this->assertSame($largestSafe * 2, Money::ofMinor($largestSafe, Currency::EUR)->multiply(2)->minor);

        $this->expectException(OverflowException::class);
        Money::ofMinor($largestSafe + 1, Currency::EUR)->multiply(2);
    }

    public function test_multiplying_max_by_two_overflows_and_leaves_value_unchanged(): void
    {
        $max = Money::ofMinor(PHP_INT_MAX, Currency::EUR);

        try {
            $max->multiply(2);
            $this->fail('Expected OverflowException.');
        } catch (OverflowException) {
        }

        $this->assertSame(PHP_INT_MAX, $max->minor);
    }

    public function test_state_cannot_be_modified(): void
    {
        $money = Money::ofMinor(100, Currency::EUR);

        $this->expectException(Error::class);

        /** @phpstan-ignore-next-line deliberately writing a readonly property */
        $money->minor = 1;
    }

    /** @return array{int, string} major units and two-digit fraction of PHP_INT_MAX minor units */
    private static function maxAsMajorAndFraction(): array
    {
        return [
            intdiv(PHP_INT_MAX, 100),
            str_pad((string) (PHP_INT_MAX % 100), 2, '0', STR_PAD_LEFT),
        ];
    }
}
