<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\PurchaseRefund;

/**
 * A host subclass of the packaged PurchaseRefund, as `purchases.models` invites.
 */
final class CustomPurchaseRefund extends PurchaseRefund
{
    protected $table = 'purchase_refunds';
}
