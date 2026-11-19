<?php

declare(strict_types=1);

namespace App\Product\Pricing;

use DomainException;

final class CurrencyMismatch extends DomainException
{
    public static function between(Currency $left, Currency $right): self
    {
        return new self(sprintf('Cannot combine %s and %s amounts.', $left->value, $right->value));
    }
}
