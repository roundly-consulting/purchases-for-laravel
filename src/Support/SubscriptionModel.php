<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * Resolves the Eloquent model backing a subscription from `purchases.models.subscription`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; it cannot know it is *ours*, so anything that is not a
 * Subscription (and so cannot answer the package's casts, scopes and relations)
 * falls back to the packaged model.
 */
final class SubscriptionModel
{
    /** @return class-string<Subscription> */
    public static function class(): string
    {
        $model = ModelResolver::for('purchases.models.subscription', Subscription::class);

        return is_a($model, Subscription::class, true) ? $model : Subscription::class;
    }

    public static function new(): Subscription
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<Subscription> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
