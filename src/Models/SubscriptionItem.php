<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Purchases\Concerns\HasPrice;
use RoundlyConsulting\Purchases\Database\Factories\SubscriptionItemFactory;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;
use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * @property int $id
 * @property int $subscription_id
 * @property string|null $provider_id
 * @property string $name
 * @property Money|null $price
 * @property string|null $price_currency
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class SubscriptionItem extends Model
{
    /** @use HasFactory<SubscriptionItemFactory> */
    use HasFactory;

    use HasPrice;
    use SoftDeletes;

    protected $guarded = [];

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(SubscriptionModel::class(), 'subscription_id');
    }

    protected static function newFactory(): SubscriptionItemFactory
    {
        return SubscriptionItemFactory::new();
    }
}
