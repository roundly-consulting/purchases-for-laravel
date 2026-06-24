<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Purchases\Support\DataSet;
use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * Builds the package Money value object from Stripe's integer minor-unit amount
 * and (lowercase) ISO currency fields.
 */
final class StripeMoney
{
    public static function fromDataSet(DataSet $dataset, string $amountKey, string $currencyKey): ?Money
    {
        $amount = $dataset->value($amountKey);
        $currency = $dataset->value($currencyKey);

        if (! is_numeric($amount) || ! is_string($currency) || $currency === '') {
            return null;
        }

        return new Money((int) $amount, strtoupper($currency));
    }
}
