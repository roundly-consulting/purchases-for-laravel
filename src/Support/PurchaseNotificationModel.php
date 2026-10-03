<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;

/**
 * Resolves the Eloquent model backing an audited provider notification from `purchases.models.purchase-notification`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class PurchaseNotificationModel
{
    /** @return class-string<PurchaseNotification> */
    public static function class(): string
    {
        return ModelResolver::for('purchases.models.purchase-notification', PurchaseNotification::class);
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
