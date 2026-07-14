<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\PurchaseNotification;

/**
 * A host subclass of the packaged PurchaseNotification, as `purchases.models` invites.
 */
final class CustomPurchaseNotification extends PurchaseNotification
{
    protected $table = 'purchase_notifications';
}
