<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * What a subscription bills changed: an item's quantity (a seat added), its price (monthly
 * swapped for annual), or an item was added or removed. Fires when a webhook, a sync or a
 * billing call changes items that were already stored — never for a new subscription, and
 * never for a row the host soft-deleted. `$subscription->items` holds the new state.
 */
final class SubscriptionUpdated
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ProviderResult $result,
    ) {}
}
