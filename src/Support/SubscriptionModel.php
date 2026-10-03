<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * Resolves the Eloquent model backing a subscription from `purchases.models.subscription`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class SubscriptionModel
{
    /** @return class-string<Subscription> */
    public static function class(): string
    {
        return ModelResolver::for('purchases.models.subscription', Subscription::class);
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
