<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe;

use RoundlyConsulting\Money\Money;

/**
 * Converts a Stripe `amount` into Money.
 *
 * Stripe sends amounts in the currency's "smallest unit", which is the ISO 4217
 * minor unit for every currency except the few listed in SCALE_EXCEPTIONS. For
 * those the integer is re-scaled from Stripe's scale to the ISO exponent, never
 * rounded: an amount that does not re-scale exactly throws RoundingNecessary.
 */
final class StripeAmount
{
    /**
     * Currency code => the number of decimals Stripe's integer amount carries,
     * where that differs from the ISO 4217 exponent money uses.
     *
     * Source: https://docs.stripe.com/currencies ("Zero-decimal currencies" and
     * "Special cases"), retrieved 2026-09-26.
     *
     * - ISK, UGX: ISO zero-decimal, but Stripe keeps them two-decimal for
     *   backward compatibility (5 ISK is sent as `500`).
     * - MGA: ISO two-decimal, Stripe zero-decimal.
     *
     * HUF and TWD are only zero-decimal for Stripe payouts; charges use two
     * decimals, which matches ISO. The three-decimal currencies (BHD, JOD, KWD,
     * OMR, TND) match ISO too.
     *
     * @var array<string, int>
     */
    public const array SCALE_EXCEPTIONS = [
        'ISK' => 2,
        'MGA' => 0,
        'UGX' => 2,
    ];

    /**
     * @param  int|string  $amount  an integer, or an integer string
     * @param  string  $currency  Stripe's lowercase ISO code
     */
    public static function toMoney(int|string $amount, string $currency): Money
    {
        $currency = strtoupper($currency);

        $scale = self::SCALE_EXCEPTIONS[$currency] ?? null;

        return $scale === null
            ? Money::ofMinor($amount, $currency)
            : Money::ofScaled($amount, $scale, $currency);
    }
}
