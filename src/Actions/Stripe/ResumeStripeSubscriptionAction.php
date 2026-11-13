<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\SubscriptionLocator;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Takes back a scheduled cancellation, so the subscription renews again, and records the result
 * locally. A subscription already canceled cannot be resumed — Stripe refuses it.
 *
 * Stripe documents undoing each kind of schedule with its own parameter, never both together, so
 * the subscription is read first (`GET /v1/subscriptions/{id}`) and exactly one is sent:
 * `cancel_at_period_end=false` for a cancellation at the period end (Stripe's documented
 * reactivation), an empty `cancel_at` for one at a chosen date (how Stripe unsets it), and
 * `cancel_at_period_end=false` — which changes nothing — when nothing is scheduled.
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
        $key = IdempotencyKey::resolve($idempotencyKey);
        $path = BillingApi::path('subscriptions', SubscriptionLocator::id($subscription));
        $current = $this->api->get($path)->body;

        $params = ($current['cancel_at_period_end'] ?? null) !== true && is_int($current['cancel_at'] ?? null)
            ? ['cancel_at' => '']
            : ['cancel_at_period_end' => false];

        return $this->record->execute($this->api->post($path, $params, $key));
    }
}
