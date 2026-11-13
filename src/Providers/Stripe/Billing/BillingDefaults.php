<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Billing;

use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\BillingMode;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\MissingPaymentMethod;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;

/**
 * The host's defaults for a new subscription — `purchases.settings.stripe.billing_mode` and
 * `missing_payment_method` — the setup Checkout's `currency`, and the check that a billing mode is only ever sent to an API
 * version that knows it.
 *
 * @internal building block of the subscription billing actions.
 */
final class BillingDefaults
{
    public static function billingMode(): ?BillingMode
    {
        $key = 'purchases.settings.stripe.billing_mode';

        return PurchasesConfig::optionalEnum(config($key), $key, BillingMode::class);
    }

    public static function trialEndBehavior(): ?MissingPaymentMethod
    {
        $key = 'purchases.settings.stripe.missing_payment_method';

        return PurchasesConfig::optionalEnum(config($key), $key, MissingPaymentMethod::class);
    }

    /**
     * The currency a setup Checkout uses when the call passes none, lower-cased, or null when
     * none is configured.
     */
    public static function currency(): ?string
    {
        $key = 'purchases.settings.stripe.currency';
        $currency = PurchasesConfig::optionalString(config($key), $key);

        if ($currency === null) {
            return null;
        }

        if (! self::isCurrency($currency)) {
            throw new InvalidConfigurationException("[{$key}] must be a three-letter ISO currency code.");
        }

        return strtolower($currency);
    }

    public static function isCurrency(string $currency): bool
    {
        return preg_match('/^[a-zA-Z]{3}$/', $currency) === 1;
    }

    /**
     * Refuse a billing mode before Stripe is asked when the pinned API version predates it —
     * Stripe would reject the parameter.
     *
     * @throws BillingException
     */
    public static function assertSupports(?BillingMode $mode): void
    {
        if ($mode === null) {
            return;
        }

        self::assertApiVersion(BillingMode::MINIMUM_API_VERSION, 'A billing mode');
    }

    /**
     * Refuse a feature before Stripe is asked when the pinned API version predates it.
     *
     * @throws BillingException
     */
    public static function assertApiVersion(string $minimum, string $feature): void
    {
        $key = 'purchases.settings.stripe.api_version';
        $version = PurchasesConfig::string(config($key), $key, Stripe::API_VERSION);

        // API versions are dated: their first ten characters order them.
        if (strcmp(substr($version, 0, 10), substr($minimum, 0, 10)) < 0) {
            throw BillingException::invalid("{$feature} needs Stripe API version {$minimum} or later; [{$key}] is {$version}.");
        }
    }
}
