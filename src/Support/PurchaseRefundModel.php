<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;

/**
 * Resolves the Eloquent model backing a refund or chargeback from `purchases.models.purchase-refund`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; it cannot know it is *ours*, so anything that is not a
 * PurchaseRefund (and so cannot answer the package's casts, scopes and relations)
 * falls back to the packaged model.
 */
final class PurchaseRefundModel
{
    /** @return class-string<PurchaseRefund> */
    public static function class(): string
    {
        $model = ModelResolver::for('purchases.models.purchase-refund', PurchaseRefund::class);

        return is_a($model, PurchaseRefund::class, true) ? $model : PurchaseRefund::class;
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
