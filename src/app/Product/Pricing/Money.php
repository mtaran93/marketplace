<?php

declare(strict_types=1);

namespace App\Product\Pricing;

use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * A nonnegative amount of one currency, held as an integer count of minor units.
 *
 * Invariants (hold for every instance, whatever path created it):
 * - $minor >= 0 and is a PHP int (never float);
 * - $currency never changes; arithmetic only combines equal currencies;
 * - no operation mutates an instance; every result is a new value.
 *
 * Failure contract:
 * - InvalidArgumentException: malformed decimal string, negative amount, negative multiplier;
 * - CurrencyMismatch (DomainException): combining different currencies;
 * - OverflowException: a result would exceed PHP_INT_MAX minor units;
 * - LogicException: the parse pattern itself failed (a bug in this class, never bad input).
 */
final readonly class Money
{
    private function __construct(
        public int $minor,
        public Currency $currency,
    ) {}

    /**
     * Use for trusted integer minor units. Decimal user input must go through parse():
     * a caller without strict_types would have "19.99" silently truncated to 19 here.
     */
    public static function ofMinor(int $minor, Currency $currency): self
    {
        if ($minor < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }

        return new self($minor, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Parses a strict decimal: "0" or a digit string without leading zero, optionally
     * followed by "." and 1..N digits, where N is the currency's minor-unit digits.
     * A 0-digit currency accepts no fraction part at all. Nothing is trimmed, rounded,
     * or normalised.
     */
    public static function parse(string $decimal, Currency $currency): self
    {
        $digits = $currency->minorUnitDigits();

        // A {1,0} quantifier does not compile, so a 0-digit currency gets no fraction group.
        $fractionGroup = $digits > 0 ? sprintf('(?:\.([0-9]{1,%d}))?', $digits) : '';
        // \z (not $) so a trailing newline is rejected; [0-9] so only ASCII digits match.
        $pattern = '/\A(0|[1-9][0-9]*)'.$fractionGroup.'\z/';

        $result = preg_match($pattern, $decimal, $matches);

        if ($result === false) {
            // A bug in our pattern, not bad input. Don't blame the caller.
            throw new LogicException('Money parse pattern failed: '.preg_last_error_msg());
        }

        if ($result !== 1) {
            throw new InvalidArgumentException('Malformed money amount.');
        }

        $major = self::digitsToInt($matches[1]);
        $fraction = (int) str_pad($matches[2] ?? '', $digits, '0');

        $minor = self::checkedAdd(self::checkedMultiply($major, $currency->minorUnitsPerMajor()), $fraction);

        return new self($minor, $currency);
    }

    /**
     * Machine format: exactly the currency's fraction digits, no locale separators or symbol,
     * no dot for a 0-digit currency. parse(format(x)) equals x for every valid amount.
     */
    public function format(): string
    {
        $digits = $this->currency->minorUnitDigits();
        $perMajor = $this->currency->minorUnitsPerMajor();
        $major = (string) intdiv($this->minor, $perMajor);

        if ($digits === 0) {
            return $major;
        }

        return $major.'.'.str_pad((string) ($this->minor % $perMajor), $digits, '0', STR_PAD_LEFT);
    }

    /** Value equality: same amount and same currency. Identity (===) is irrelevant for values. */
    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }

        return new self(self::checkedAdd($this->minor, $other->minor), $this->currency);
    }

    /** Zero is allowed here; product-specific quantity rules live in ProductQuote. */
    public function multiply(int $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('Multiplier cannot be negative.');
        }

        return new self(self::checkedMultiply($this->minor, $multiplier), $this->currency);
    }

    /** Both operands are nonnegative; the check runs before the addition can promote to float. */
    private static function checkedAdd(int $a, int $b): int
    {
        if ($a > PHP_INT_MAX - $b) {
            throw new OverflowException('Money amount exceeds the supported maximum.');
        }

        return $a + $b;
    }

    /** Both operands are nonnegative; for n > 0, a * n <= MAX  <=>  a <= floor(MAX / n). */
    private static function checkedMultiply(int $a, int $n): int
    {
        if ($n !== 0 && $a > intdiv(PHP_INT_MAX, $n)) {
            throw new OverflowException('Money amount exceeds the supported maximum.');
        }

        return $a * $n;
    }

    /**
     * Converts a grammar-validated digit string (no sign, no leading zero) to int, rejecting
     * values above PHP_INT_MAX by string comparison so no float conversion ever happens.
     */
    private static function digitsToInt(string $digits): int
    {
        $max = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new OverflowException('Money amount exceeds the supported maximum.');
        }

        return (int) $digits;
    }
}
