<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\SubscriptionItem;

/**
 * A host subclass of the packaged SubscriptionItem, as `purchases.models` invites.
 */
final class CustomSubscriptionItem extends SubscriptionItem
{
    protected $table = 'subscription_items';
}
