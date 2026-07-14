<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\Purchase;

/**
 * What `purchases.models.purchase` documents a host doing: extend the packaged
 * model, keep its table. Eloquent derives a `hasMany` foreign key (and
 * `foreignIdFor()` derives a migration column) from the parent's CLASS NAME, so
 * this subclass is what proves those keys are named rather than derived.
 */
final class CustomPurchase extends Purchase
{
    protected $table = 'purchases';
}
