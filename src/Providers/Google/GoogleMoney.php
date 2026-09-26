<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google;

use RoundingMode;
use RoundlyConsulting\Money\Exceptions\MoneyException;
use RoundlyConsulting\Money\Money;

/**
 * Converts a Play Developer API `Money` object into Money.
 *
 * `units` is an int64 serialized as a JSON string and `nanos` billionths of a unit
 * carrying the same sign. The two parts are added as Money — no int arithmetic —
 * so the only rounding is nanos down to the currency's minor unit, once, half
 * away from zero. proto3 JSON omits zero fields, so a missing `units` or `nanos`
 * is zero.
 *
 * @link https://developers.google.com/android-publisher/api-ref/rest/v3/Money
 */
final class GoogleMoney
{
    private const int MAX_NANOS = 999_999_999;

    public static function fromMoney(mixed $value): ?Money
    {
        if (! is_array($value)) {
            return null;
        }

        $currency = $value['currencyCode'] ?? null;
        $units = $value['units'] ?? '0';
        $nanos = $value['nanos'] ?? 0;

        if (! is_string($currency) || $currency === '') {
            return null;
        }

        if (! is_int($units) && ! (is_string($units) && preg_match('/^-?\d+$/', $units) === 1)) {
            return null;
        }

        if (! is_int($nanos) || abs($nanos) > self::MAX_NANOS) {
            return null;
        }

        try {
            return Money::ofScaled($units, 0, $currency)
                ->add(Money::ofScaled($nanos, 9, $currency, RoundingMode::HalfAwayFromZero));
        } catch (MoneyException) {
            return null;
        }
    }
}
