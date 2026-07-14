<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\Purchase;

/**
 * Resolves the Eloquent model backing a purchase from `purchases.models.purchase`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; it cannot know it is *ours*, so anything that is not a
 * Purchase (and so cannot answer the package's casts, scopes and relations)
 * falls back to the packaged model.
 */
final class PurchaseModel
{
    /** @return class-string<Purchase> */
    public static function class(): string
    {
        $model = ModelResolver::for('purchases.models.purchase', Purchase::class);

        return is_a($model, Purchase::class, true) ? $model : Purchase::class;
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
