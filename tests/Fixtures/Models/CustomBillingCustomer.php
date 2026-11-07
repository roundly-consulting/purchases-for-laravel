<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures\Models;

use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass of the packaged BillingCustomer, as `purchases.models` invites.
 */
final class CustomBillingCustomer extends BillingCustomer
{
    use CountsCreations;

    protected $table = 'billing_customers';
}
