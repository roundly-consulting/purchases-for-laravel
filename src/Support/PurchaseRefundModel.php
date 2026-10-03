<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;

/**
 * Resolves the Eloquent model backing a refund or chargeback from `purchases.models.purchase-refund`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class PurchaseRefundModel
{
    /** @return class-string<PurchaseRefund> */
    public static function class(): string
    {
        return ModelResolver::for('purchases.models.purchase-refund', PurchaseRefund::class);
    }

    public static function new(): PurchaseRefund
    {
        $model = self::class();

        return new $model;
    }

    /** @return Builder<PurchaseRefund> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
