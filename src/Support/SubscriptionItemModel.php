<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;

/**
 * Resolves the Eloquent model backing a subscription line item from `purchases.models.subscription-item`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; it cannot know it is *ours*, so anything that is not a
 * SubscriptionItem (and so cannot answer the package's casts, scopes and relations)
 * falls back to the packaged model.
 */
final class SubscriptionItemModel
{
    /** @return class-string<SubscriptionItem> */
    public static function class(): string
    {
        $model = ModelResolver::for('purchases.models.subscription-item', SubscriptionItem::class);

        return is_a($model, SubscriptionItem::class, true) ? $model : SubscriptionItem::class;
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
