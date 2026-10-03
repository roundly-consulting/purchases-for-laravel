<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;

/**
 * Resolves the Eloquent model backing a subscription line item from `purchases.models.subscription-item`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class SubscriptionItemModel
{
    /** @return class-string<SubscriptionItem> */
    public static function class(): string
    {
        return ModelResolver::for('purchases.models.subscription-item', SubscriptionItem::class);
    }

    public static function new(): SubscriptionItem
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<SubscriptionItem> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
