<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\SubscriptionLocator;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Takes back a cancellation scheduled for the end of the period (`cancel_at_period_end`), so
 * the subscription renews again, and records the result locally. A subscription already
 * canceled cannot be resumed — Stripe refuses it.
 *
 * Not Stripe's `/resume` endpoint, which un-pauses a paused subscription.
 */
final readonly class ResumeStripeSubscriptionAction
{
    public function __construct(
        private BillingApi $api,
        private RecordStripeSubscriptionAction $record,
    ) {}

    public function execute(Subscription|string $subscription, ?string $idempotencyKey = null): Subscription
    {
        return $this->record->execute($this->api->post(
            BillingApi::path('subscriptions', SubscriptionLocator::id($subscription)),
            ['cancel_at_period_end' => false],
            IdempotencyKey::resolve($idempotencyKey),
        ));
    }
}
