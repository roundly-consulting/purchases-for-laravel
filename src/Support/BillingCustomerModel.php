<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\BillingCustomer;

/**
 * Resolves the Eloquent model backing a billing customer from `purchases.models.billing-customer`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class BillingCustomerModel
{
    /** @return class-string<BillingCustomer> */
    public static function class(): string
    {
        return ModelResolver::for('purchases.models.billing-customer', BillingCustomer::class);
    }

    public static function new(): BillingCustomer
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<BillingCustomer> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
