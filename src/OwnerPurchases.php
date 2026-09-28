<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

/**
 * One owner's purchases and subscriptions — `Purchases::for($user)`.
 *
 * Every query is scoped to exactly this owner: its morph type AND its key, so a same-id
 * owner of another model never leaks in, and an unsaved owner sees nothing (never the
 * rows a webhook recorded before any owner was linked). The owner needs no trait.
 */
final readonly class OwnerPurchases
{
    public function __construct(
        private Model $owner,
    ) {}

    /**
     * @return Builder<Purchase>
     */
    public function purchases(): Builder
    {
        return PurchaseModel::query()->whereMorphedTo('owner', $this->owner);
    }

    /**
     * @return Builder<Subscription>
     */
    public function subscriptions(): Builder
    {
        return SubscriptionModel::query()->whereMorphedTo('owner', $this->owner);
    }

    /**
     * The owner's current active subscription (the latest one), optionally by plan name.
     */
    public function activeSubscription(?string $name = null): ?Subscription
    {
        return $this->subscriptions()
            ->when($name !== null, fn (Builder $query): Builder => $query->where('name', $name))
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
