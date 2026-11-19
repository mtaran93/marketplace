<?php

declare(strict_types=1);

namespace App\Product\Pricing;

/**
 * Currencies the marketplace accepts. Deliberately closed: adding one is a code change
 * that forces a decision about its minor-unit digits, not a configuration value.
 */
enum Currency: string
{
    case EUR = 'EUR';
    case RON = 'RON';

    /**
     * Number of digits after the decimal point (ISO 4217 exponent).
     * This is the single source of truth for the currency's scale.
     */
    public function minorUnitDigits(): int
    {
        return match ($this) {
            self::EUR, self::RON => 2,
        };
    }

    /**
     * Minor units in one major unit, e.g. 100 cents per euro, 1 for a 0-digit currency.
     * Derived from minorUnitDigits() so the two can never disagree; int ** int with a
     * small nonnegative exponent stays an int.
     */
    public function minorUnitsPerMajor(): int
    {
        return 10 ** $this->minorUnitDigits();
    }
}
