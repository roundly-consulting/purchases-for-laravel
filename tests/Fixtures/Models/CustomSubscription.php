<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * A host subclass of the packaged Subscription, as `purchases.models` invites.
 */
final class CustomSubscription extends Subscription
{
    protected $table = 'subscriptions';
}
