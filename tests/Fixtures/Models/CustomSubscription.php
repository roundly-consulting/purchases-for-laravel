<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass of the packaged Subscription, as `purchases.models` invites.
 */
final class CustomSubscription extends Subscription
{
    // Required by `toHonourModelSwap`, not detected: it counts rows created as THIS
    // exact class, the only independent proof the swap took effect. `instanceof` is not
    // enough — a row created as the packaged class never fires the host's model events
    // yet can still satisfy an instanceof check.
    use CountsCreations;

    protected $table = 'subscriptions';
}
