<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Models\BillingCustomer;

/**
 * Keeps each owner's provider customer id in the `billing_customers` table (the model at
 * `purchases.models.billing-customer`). A link the host soft-deleted is not used; linking the
 * owner again restores it.
 */
final class DatabaseBillingCustomers implements BillingCustomers
{
    public function customerId(Model $owner, string $provider): ?string
    {
        if (! $owner->exists) {
            return null;
        }

        $id = BillingCustomerModel::query()
            ->whereMorphedTo('owner', $owner)
            ->where('provider', $provider)
            ->value('provider_id');

        return is_string($id) ? $id : null;
    }

    public function owner(string $provider, string $customerId): ?Model
    {
        $customer = BillingCustomerModel::query()
            ->where('provider', $provider)
            ->where('provider_id', $customerId)
            ->first();

        return $customer?->owner;
    }

    public function remember(Model $owner, string $provider, string $customerId): void
    {
        if (! $owner->exists) {
            throw BillingException::unsavedOwner($owner);
        }

        $taken = BillingCustomerModel::query()
            ->withTrashed()
            ->where('provider', $provider)
            ->where('provider_id', $customerId)
            ->first();

        if ($taken !== null && ! $this->ownedBy($taken, $owner)) {
            throw BillingException::customerTaken($customerId);
        }

        $link = BillingCustomerModel::query()
            ->withTrashed()
            ->whereMorphedTo('owner', $owner)
            ->where('provider', $provider)
            ->first() ?? BillingCustomerModel::new();

        $link->fill([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'provider' => $provider,
            'provider_id' => $customerId,
            'deleted_at' => null,
        ])->save();
    }

    private function ownedBy(BillingCustomer $customer, Model $owner): bool
    {
        return $customer->owner_type === $owner->getMorphClass()
            && (string) $customer->owner_id === (string) $owner->getKey();
    }
}
