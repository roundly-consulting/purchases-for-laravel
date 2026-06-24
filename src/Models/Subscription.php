<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RoundlyConsulting\Purchases\Concerns\HasPrice;
use RoundlyConsulting\Purchases\Concerns\HasProviderScopes;
use RoundlyConsulting\Purchases\Database\Factories\SubscriptionFactory;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * @property int $id
 * @property string|null $provider
 * @property string|null $provider_id
 * @property string|null $transaction_id
 * @property Status $status
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
    use HasProviderScopes;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'meta' => 'collection',
            'active_from' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Active subscriptions: still entitled and not yet past their end date.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query
            ->whereIn('status', [Status::Completed->value, Status::InGracePeriod->value])
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', Carbon::now());
            });
    }

    /**
     * Subscriptions currently within a (non-expired) trial window.
     *
     * Named `trialing` to avoid clashing with the instance helper onTrial().
     *
     * @param  Builder<self>  $query
     */
    public function scopeTrialing(Builder $query): void
    {
        $query->whereNotNull('trial_ends_at')->where('trial_ends_at', '>', Carbon::now());
    }

    /**
     * Subscriptions that end within the next $days days.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExpiring(Builder $query, int $days = 7): void
    {
        $query
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [Carbon::now(), Carbon::now()->addDays($days)]);
    }

    /**
     * Subscriptions the customer has canceled.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCanceled(Builder $query): void
    {
        $query->where('status', Status::Canceled->value);
    }

    public function isActive(): bool
    {
        if (! $this->status->isActive()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function daysUntilRenewal(): ?int
    {
        if ($this->ends_at === null) {
            return null;
        }

        return max(0, (int) Carbon::now()->diffInDays($this->ends_at, absolute: false));
    }

    public function isExpiring(int $days = 7): bool
    {
        if ($this->ends_at === null) {
            return false;
        }

        return $this->ends_at->isBetween(Carbon::now(), Carbon::now()->addDays($days));
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
