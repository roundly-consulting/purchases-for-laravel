<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Billing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Actions\Stripe\CreateStripeSetupSessionAction;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\CheckoutSession;

/**
 * Hosted Stripe Checkout pages that are not a subscription — `Purchases::stripe()->checkout()`.
 * (A subscription Checkout is `subscriptions()->checkout()`.)
 */
final readonly class StripeCheckout
{
    /**
     * @param  (Closure(string, list<mixed>): void)|null  $recorder
     */
    public function __construct(
        private Container $container,
        private ?Closure $recorder = null,
    ) {}

    /**
     * A setup Checkout for the owner's Stripe customer: it collects a payment method without
     * charging, and once the customer completes it (webhook `checkout.session.completed`) the
     * method becomes the customer's default for invoices. Redirect to the session's `url`.
     *
     * @param  string|null  $currency  three-letter ISO code (Stripe offers the payment methods that can pay
     *                                 in it); null uses `purchases.settings.stripe.currency`
     */
    public function setup(Model $owner, string $successUrl, string $cancelUrl, ?string $currency = null, ?string $idempotencyKey = null): CheckoutSession
    {
        if ($this->recorder !== null) {
            ($this->recorder)('checkout.setup', [$owner, $successUrl, $cancelUrl, $currency, $idempotencyKey]);
        }

        return $this->container->make(CreateStripeSetupSessionAction::class)->execute($owner, $successUrl, $cancelUrl, $currency, $idempotencyKey);
    }
}
