<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;

/**
 * Resolves the Eloquent model backing an audited provider notification from `purchases.models.purchase-notification`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; it cannot know it is *ours*, so anything that is not a
 * PurchaseNotification (and so cannot answer the package's casts, scopes and relations)
 * falls back to the packaged model.
 */
final class PurchaseNotificationModel
{
    /** @return class-string<PurchaseNotification> */
    public static function class(): string
    {
        $model = ModelResolver::for('purchases.models.purchase-notification', PurchaseNotification::class);

        return is_a($model, PurchaseNotification::class, true) ? $model : PurchaseNotification::class;
    }

    public static function new(): PurchaseNotification
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<PurchaseNotification> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
