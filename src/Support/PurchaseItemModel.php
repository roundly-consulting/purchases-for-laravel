<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\PurchaseItem;

/**
 * Resolves the Eloquent model backing a purchase line item from `purchases.models.purchase-item`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class PurchaseItemModel
{
    /** @return class-string<PurchaseItem> */
    public static function class(): string
    {
        return ModelResolver::for('purchases.models.purchase-item', PurchaseItem::class);
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
