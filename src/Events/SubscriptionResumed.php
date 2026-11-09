<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * A subscription on hold is active again — paid, or resumed (Stripe
 * `customer.subscription.resumed`, Google's recovery). SubscriptionRenewed fires with it, as it
 * always did.
 */
final class SubscriptionResumed
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ProviderResult $result,
    ) {}
}
