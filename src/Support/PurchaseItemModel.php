<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\PurchaseItem;

/**
 * Resolves the Eloquent model backing a purchase line item from `purchases.models.purchase-item`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; it cannot know it is *ours*, so anything that is not a
 * PurchaseItem (and so cannot answer the package's casts, scopes and relations)
 * falls back to the packaged model.
 */
final class PurchaseItemModel
{
    /** @return class-string<PurchaseItem> */
    public static function class(): string
    {
        $model = ModelResolver::for('purchases.models.purchase-item', PurchaseItem::class);

        return is_a($model, PurchaseItem::class, true) ? $model : PurchaseItem::class;
    }

    public static function new(): PurchaseItem
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<PurchaseItem> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
