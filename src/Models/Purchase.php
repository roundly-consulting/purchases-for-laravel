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
use RoundlyConsulting\Money\Casts\AsMoney;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Concerns\HasProviderScopes;
use RoundlyConsulting\Purchases\Database\Factories\PurchaseFactory;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Support\PurchaseItemModel;
use RoundlyConsulting\Purchases\Support\PurchaseRefundModel;

/**
 * @property int $id
 * @property string|null $provider
 * @property string|null $provider_id
 * @property string|null $transaction_id
 * @property Status $status
 * @property Money|null $price
 * @property string|null $price_currency
 * @property Collection<array-key, mixed>|null $meta
 * @property CarbonInterface|null $last_event_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Purchase extends Model
{
    /** @use HasFactory<PurchaseFactory> */
    use HasFactory;

    use HasProviderScopes;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price' => AsMoney::class,
            'status' => Status::class,
            'meta' => 'collection',
            'last_event_at' => 'datetime',
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
        // The FK is named, never derived: Eloquent would otherwise take it from
        // THIS class's name, so a host subclass configured at `purchases.models
        // .purchase` would look for `custom_purchase_id`.
        return $this->hasMany(PurchaseItemModel::class(), 'purchase_id');
    }

    /** @return HasMany<PurchaseRefund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(PurchaseRefundModel::class(), 'purchase_id');
    }

    protected static function newFactory(): PurchaseFactory
    {
        return PurchaseFactory::new();
    }
}
