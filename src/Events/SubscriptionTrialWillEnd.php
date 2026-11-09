<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * A trial ends soon — Stripe's `customer.subscription.trial_will_end`, sent three days before
 * the trial ends, or at once when it is ended early or has fewer days left. The moment to ask
 * for a payment method; check `$subscription->status` first, as payment may already be in.
 *
 * Fires for each delivery of the event: turn on `purchases.audit.deduplicate` to skip
 * redeliveries.
 */
final class SubscriptionTrialWillEnd
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ProviderResult $result,
    ) {}
}
