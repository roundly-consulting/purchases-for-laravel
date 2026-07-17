<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * What `purchases.models.purchase` documents a host doing: extend the packaged
 * model, keep its table. Eloquent derives a `hasMany` foreign key (and
 * `foreignIdFor()` derives a migration column) from the parent's CLASS NAME, so
 * this subclass is what proves those keys are named rather than derived.
 */
final class CustomPurchase extends Purchase
{
    // Required by `toHonourModelSwap`, not detected: it counts rows created as THIS
    // exact class, the only independent proof the swap took effect. `instanceof` is not
    // enough — a row created as the packaged class never fires the host's model events
    // yet can still satisfy an instanceof check.
    use CountsCreations;

    protected $table = 'purchases';
}
