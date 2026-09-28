<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

/**
 * Gives an owner model (typically the User) convenient access to its purchases
 * and subscriptions through the package's `owner` morph relationship.
 *
 * The helpers delegate to the manager (`Purchases::for($this)`), so they run the same
 * code as the facade and an injected manager.
 *
 * @phpstan-require-extends Model
 */
trait HasPurchases
{
    /** @return MorphMany<Purchase, $this> */
    public function purchases(): MorphMany
    {
        return $this->morphMany(PurchaseModel::class(), 'owner');
    }

    /** @return MorphMany<Subscription, $this> */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(SubscriptionModel::class(), 'owner');
    }

    /**
     * The owner's current active subscription, optionally filtered by name.
     */
    public function activeSubscription(?string $name = null): ?Subscription
    {
        return app(PurchasesManager::class)->for($this)->activeSubscription($name);
    }

    /**
     * Whether the owner has an active subscription to the given plan name.
     */
    public function subscribedTo(string $name): bool
    {
        return app(PurchasesManager::class)->for($this)->subscribedTo($name);
    }
}
