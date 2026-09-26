<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Money\Exceptions\MoneyException;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Providers\Stripe\StripeAmount;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * Builds Money from Stripe's integer smallest-unit amount and (lowercase) ISO
 * currency fields.
 *
 * Only an int or an integer string is an amount: a float or `"12.5"` is refused
 * rather than truncated, and so is a currency money does not know.
 */
final class StripeMoney
{
    public static function fromDataSet(DataSet $dataset, string $amountKey, string $currencyKey): ?Money
    {
        $amount = $dataset->value($amountKey);
        $currency = $dataset->value($currencyKey);

        if (! is_int($amount) && ! (is_string($amount) && preg_match('/^-?\d+$/', $amount) === 1)) {
            return null;
        }

        if (! is_string($currency) || $currency === '') {
            return null;
        }

        try {
            return StripeAmount::toMoney($amount, $currency);
        } catch (MoneyException) {
            return null;
        }
    }
}
