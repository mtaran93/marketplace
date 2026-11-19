<?php

declare(strict_types=1);

namespace App\Product\Pricing;

use InvalidArgumentException;

final class ProductQuote
{
    public const int MIN_QUANTITY = 1;

    public const int MAX_QUANTITY = 100;

    /**
     * @throws InvalidArgumentException quantity outside 1..100
     * @throws \OverflowException unit price times quantity exceeds PHP_INT_MAX minor units
     */
    public static function total(Money $unitPrice, int $quantity): Money
    {
        if ($quantity < self::MIN_QUANTITY || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf(
                'Quantity must be between %d and %d.',
                self::MIN_QUANTITY,
                self::MAX_QUANTITY,
            ));
        }

        return $unitPrice->multiply($quantity);
    }
}
