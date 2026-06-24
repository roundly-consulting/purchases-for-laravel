<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * Gives an owner model (typically the User) convenient access to its purchases
 * and subscriptions through the package's `owner` morph relationship.
 *
 * @phpstan-require-extends Model
 */
trait HasPurchases
{
    /** @return MorphMany<Purchase, $this> */
    public function purchases(): MorphMany
    {
        /** @var class-string<Purchase> $model */
        $model = config('purchases.models.purchase', Purchase::class);

        return $this->morphMany($model, 'owner');
    }

    /** @return MorphMany<Subscription, $this> */
    public function subscriptions(): MorphMany
    {
        /** @var class-string<Subscription> $model */
        $model = config('purchases.models.subscription', Subscription::class);

        return $this->morphMany($model, 'owner');
    }

    /**
     * The owner's current active subscription, optionally filtered by name.
     */
    public function activeSubscription(?string $name = null): ?Subscription
    {
        return $this->subscriptions()
            ->when($name !== null, fn ($query) => $query->where('name', $name))
            ->active()
            ->latest('id')
            ->first();
    }

    /**
     * Whether the owner has an active subscription to the given plan name.
     */
    public function subscribedTo(string $name): bool
    {
        return $this->activeSubscription($name) !== null;
    }
}
