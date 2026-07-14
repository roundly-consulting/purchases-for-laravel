<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\PurchaseItem;

/**
 * A host subclass of the packaged PurchaseItem, as `purchases.models` invites.
 */
final class CustomPurchaseItem extends PurchaseItem
{
    protected $table = 'purchase_items';
}
