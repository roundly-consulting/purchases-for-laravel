<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Concerns\HasPrice;
use RoundlyConsulting\Purchases\Database\Factories\SubscriptionFactory;
use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * @property int $id
 * @property string|null $provider
 * @property string|null $provider_id
 * @property string $name
 * @property Money|null $price
 * @property string|null $price_currency
 * @property CarbonInterface|null $active_from
 * @property CarbonInterface|null $trial_ends_at
 * @property CarbonInterface|null $ends_at
 * @property Collection<array-key, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use HasPrice;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'meta' => 'collection',
            'active_from' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<SubscriptionItem, $this> */
    public function items(): HasMany
    {
        /** @var class-string<SubscriptionItem> $item */
        $item = config('purchases.models.subscription-item', SubscriptionItem::class);

        return $this->hasMany($item);
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }
}
