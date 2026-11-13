<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingDefaults;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\CheckoutSession;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Opens a Stripe Checkout Session in setup mode for an owner's customer: redirect to its `url`
 * and Stripe collects a payment method without charging or subscribing anything — e.g. before
 * `subscriptions()->create()` starts a flexible-billing subscription Checkout cannot create.
 *
 * The session is marked (metadata `purchases_default_payment_method`), and when its
 * `checkout.session.completed` webhook arrives the collected method becomes the customer's
 * default for invoices (DefaultPaymentMethodUpdated). A setup Checkout opened any other way is
 * left alone. Stripe needs a currency to pick the payment methods a setup Checkout offers: the
 * one passed, else `purchases.settings.stripe.currency`; with neither the call is refused.
 */
final readonly class CreateStripeSetupSessionAction
{
    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
    ) {}

    public function execute(Model $owner, string $successUrl, string $cancelUrl, ?string $currency = null, ?string $idempotencyKey = null): CheckoutSession
    {
        $customer = $this->customers->customerId($owner, 'stripe') ?? throw BillingException::noCustomer($owner);

        if ($currency !== null && ! BillingDefaults::isCurrency($currency)) {
            throw BillingException::invalid('A currency is a three-letter ISO code.');
        }

        $currency ??= BillingDefaults::currency()
            ?? throw BillingException::invalid('A setup Checkout needs a currency: pass one, or set [purchases.settings.stripe.currency].');

        return CheckoutSession::fromRaw($this->api->post('/checkout/sessions', [
            'mode' => 'setup',
            'customer' => $customer,
            'currency' => strtolower($currency),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [Stripe::SETUP_DEFAULT_PAYMENT_METHOD => 'true'],
        ], IdempotencyKey::resolve($idempotencyKey))->body);
    }
}
