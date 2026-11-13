<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Models\Subscription;
use SensitiveParameter;

/**
 * A subscription `subscriptions()->start()` started: the stored Subscription, and — while Stripe
 * still waits for its first payment (status `incomplete`, e.g. `paymentBehavior:
 * DefaultIncomplete` or a payment that needs 3-D Secure) — how the customer pays it: the first
 * invoice's hosted page (`hostedInvoiceUrl`, redirect there), or the payment's client secret
 * (`clientSecret`, confirm it with Stripe.js / the Payment Element).
 *
 * The client secret is handed to the customer's browser and to nobody else; it is never stored.
 */
final readonly class StartedSubscription
{
    public function __construct(
        public Subscription $subscription,
        public ?string $invoiceId = null,
        public ?string $hostedInvoiceUrl = null,
        #[SensitiveParameter] public ?string $clientSecret = null,
    ) {}

    /**
     * Whether the customer still has to pay the first invoice before the subscription starts.
     */
    public function requiresPayment(): bool
    {
        return $this->hostedInvoiceUrl !== null || $this->clientSecret !== null;
    }
}
