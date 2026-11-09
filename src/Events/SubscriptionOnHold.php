<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * A subscription moved to on hold: it still exists, but grants no access until it is paid or
 * resumed. Stripe `unpaid` or `paused` (a trial that ended without a payment method, under
 * `MissingPaymentMethod::Pause`) — and `past_due` when `purchases.settings.stripe.past_due` is
 * `on_hold` — Apple's billing retry after its grace period, Google's account hold.
 * `$subscription->meta` keeps the store's own status.
 */
final class SubscriptionOnHold
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ProviderResult $result,
    ) {}
}
