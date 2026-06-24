<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\Subscription;

final class SubscriptionInGracePeriod
{
    use Dispatchable;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly ProviderResult $result,
    ) {}
}
