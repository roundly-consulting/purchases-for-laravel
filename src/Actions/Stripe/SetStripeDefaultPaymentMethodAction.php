<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;

/**
 * Makes the payment method a setup Checkout collected the customer's default for invoices: it
 * reads the session's SetupIntent (`GET /v1/setup_intents/{id}`) and sets its payment method as
 * the customer's `invoice_settings[default_payment_method]`. Returns that payment method, or
 * null when there is nothing to set — no customer, no SetupIntent, a SetupIntent without a
 * method — or no secret key is configured, where nothing is asked of Stripe.
 *
 * Setting the same default twice changes nothing, so every attempt goes with a fresh idempotency
 * key: a key Stripe stored with a failed first answer would replay that failure to every retry
 * for a day. A Stripe failure throws a StripeException: the webhook fails and Stripe delivers it
 * again (a queued one is retried by the queue).
 *
 * @internal building block of the recording pipeline (Signal::PaymentMethodCollected).
 */
final readonly class SetStripeDefaultPaymentMethodAction
{
    public function __construct(
        private BillingApi $api,
    ) {}

    public function execute(GenericResult $session): ?string
    {
        $customer = $session->customerId();
        $setupIntent = $session->raw()['setup_intent'] ?? null;

        if ($customer === null || PurchasesConfig::blank(config('purchases.settings.stripe.secret'))) {
            return null;
        }

        $paymentMethod = is_array($setupIntent)
            ? ($setupIntent['payment_method'] ?? null)
            : (self::filled($setupIntent) ? $this->api->get(BillingApi::path('setup_intents', $setupIntent))->body['payment_method'] ?? null : null);
        $paymentMethod = is_array($paymentMethod) ? ($paymentMethod['id'] ?? null) : $paymentMethod;

        if (! self::filled($paymentMethod)) {
            return null;
        }

        $this->api->post(
            BillingApi::path('customers', $customer),
            ['invoice_settings' => ['default_payment_method' => $paymentMethod]],
            IdempotencyKey::resolve(null),
        );

        return $paymentMethod;
    }

    /**
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function filled(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
