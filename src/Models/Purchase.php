<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Concerns\HasPrice;
use RoundlyConsulting\Purchases\Concerns\HasProviderScopes;
use RoundlyConsulting\Purchases\Database\Factories\PurchaseFactory;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * @property int $id
 * @property string|null $provider
 * @property string|null $provider_id
 * @property string|null $transaction_id
 * @property Status $status
 * @property Money|null $price
 * @property string|null $price_currency
 * @property Collection<array-key, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    use HasPrice;
    use HasProviderScopes;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'meta' => 'collection',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        /** @var class-string<PurchaseItem> $item */
        $item = config('purchases.models.purchase-item', PurchaseItem::class);

        return $this->hasMany($item);
    }

    /** @return HasMany<PurchaseRefund, $this> */
    public function refunds(): HasMany
    {
        /** @var class-string<PurchaseRefund> $refund */
        $refund = config('purchases.models.purchase-refund', PurchaseRefund::class);

        return $this->hasMany($refund);
    }

    protected static function newFactory(): PurchaseFactory
    {
        return PurchaseFactory::new();
    }
}
