<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\Purchase;

/**
 * Resolves the Eloquent model backing a purchase from `purchases.models.purchase`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class PurchaseModel
{
    /** @return class-string<Purchase> */
    public static function class(): string
    {
        return ModelResolver::for('purchases.models.purchase', Purchase::class);
    }

    public static function new(): Purchase
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<Purchase> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
