<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\SubscriptionLocator;

/**
 * Reads a Stripe subscription again (`GET /v1/subscriptions/{id}`) and records what Stripe
 * answers through the same pipeline as a webhook — status, period, items, cancellation, owner
 * linked through its customer, lifecycle events — ordered at Stripe's answer time. A webhook
 * Stripe stamped later than the answer wins: its state is kept, and the stored row is returned.
 *
 * For a reconcile run (nightly, or after a webhook outage); a subscription that is not stored
 * yet is recorded.
 */
final readonly class RefreshStripeSubscriptionAction
{
    public function __construct(
        private BillingApi $api,
        private RecordStripeSubscriptionAction $record,
    ) {}

    public function execute(Subscription|string $subscription): Subscription
    {
        return $this->record->execute($this->api->get(
            BillingApi::path('subscriptions', SubscriptionLocator::id($subscription)),
        ));
    }
}
