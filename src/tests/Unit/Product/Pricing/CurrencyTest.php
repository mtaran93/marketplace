<?php

declare(strict_types=1);

namespace Tests\Unit\Product\Pricing;

use App\Product\Pricing\Currency;
use App\Product\Pricing\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    /**
     * @return iterable<string, array{Currency, int, int}>
     */
    public static function knownCurrencies(): iterable
    {
        yield 'EUR' => [Currency::EUR, 2, 100];
        yield 'RON' => [Currency::RON, 2, 100];
    }

    #[DataProvider('knownCurrencies')]
    public function test_minor_units_per_major_matches_iso_4217(Currency $currency, int $digits, int $perMajor): void
    {
        $this->assertSame($digits, $currency->minorUnitDigits());
        $this->assertSame($perMajor, $currency->minorUnitsPerMajor());
    }

    public function test_every_currency_has_a_hand_written_expectation(): void
    {
        $covered = array_map(
            static fn (array $row): Currency => $row[0],
            iterator_to_array(self::knownCurrencies()),
        );

        $this->assertEqualsCanonicalizing(Currency::cases(), array_values($covered));
    }

    /** @return iterable<string, array{Currency}> */
    public static function allCurrencies(): iterable
    {
        foreach (Currency::cases() as $currency) {
            yield $currency->value => [$currency];
        }
    }

    #[DataProvider('allCurrencies')]
    public function test_minor_units_per_major_is_ten_to_the_power_of_digits(Currency $currency): void
    {
        $this->assertSame(10 ** $currency->minorUnitDigits(), $currency->minorUnitsPerMajor());
    }

    #[DataProvider('allCurrencies')]
    public function test_largest_fraction_fits_below_one_major_unit(Currency $currency): void
    {
        $digits = $currency->minorUnitDigits();
        $largestFraction = $digits === 0 ? 0 : (int) str_repeat('9', $digits);

        $this->assertSame($currency->minorUnitsPerMajor() - 1, $largestFraction);
    }

    /** Exercises parse/format for each currency's own scale, including future 0- or 3-digit ones. */
    #[DataProvider('allCurrencies')]
    public function test_format_then_parse_round_trips_for_every_currency(Currency $currency): void
    {
        foreach ([0, 1, 123456, PHP_INT_MAX] as $minor) {
            $original = Money::ofMinor($minor, $currency);

            $this->assertTrue(Money::parse($original->format(), $currency)->equals($original));
        }
    }

    #[DataProvider('allCurrencies')]
    public function test_formats_with_exactly_the_currency_fraction_digits(Currency $currency): void
    {
        $digits = $currency->minorUnitDigits();
        // A 0-digit currency prints no dot; otherwise exactly $digits fraction digits.
        $shape = $digits === 0 ? '/\A[0-9]+\z/' : sprintf('/\A[0-9]+\.[0-9]{%d}\z/', $digits);

        $this->assertMatchesRegularExpression($shape, Money::ofMinor(5, $currency)->format());
    }
}
