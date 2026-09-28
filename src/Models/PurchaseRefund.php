<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RoundlyConsulting\Money\Casts\AsMoney;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Concerns\HasProviderScopes;
use RoundlyConsulting\Purchases\Database\Factories\PurchaseRefundFactory;
use RoundlyConsulting\Purchases\Support\PurchaseModel;

/**
 * @property int $id
 * @property int|null $purchase_id
 * @property string|null $provider
 * @property string|null $provider_id
 * @property string|null $transaction_id
 * @property string|null $reason
 * @property bool $chargeback
 * @property Money|null $price
 * @property string|null $price_currency
 * @property CarbonInterface|null $refunded_at
 * @property Collection<array-key, mixed>|null $meta
 * @property CarbonInterface|null $last_event_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class PurchaseRefund extends Model
{
    /** @use HasFactory<PurchaseRefundFactory> */
    use HasFactory;

    use HasProviderScopes;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price' => AsMoney::class,
            'chargeback' => 'bool',
            'refunded_at' => 'datetime',
            'meta' => 'collection',
            'last_event_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(PurchaseModel::class(), 'purchase_id');
    }

    /**
     * Limit to chargebacks (disputes), as opposed to merchant/store refunds.
     *
     * @param  Builder<self>  $query
     */
    public function scopeChargebacks(Builder $query): void
    {
        $query->where('chargeback', true);
    }

    protected static function newFactory(): PurchaseRefundFactory
    {
        return PurchaseRefundFactory::new();
    }
}
