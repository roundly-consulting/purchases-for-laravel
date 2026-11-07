<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Support\DatabaseBillingCustomers;

/**
 * Where an owner's customer id at a billing provider is kept.
 *
 * The package binds {@see DatabaseBillingCustomers},
 * which keeps it in the `billing_customers` table. A host that already stores it (a
 * `stripe_id` column on its companies) binds its own implementation instead:
 *
 *     $this->app->singleton(BillingCustomers::class, CompanyStripeIds::class);
 */
interface BillingCustomers
{
    /**
     * The owner's customer id at the provider, or null when it has none.
     */
    public function customerId(Model $owner, string $provider): ?string;

    /**
     * The owner a provider customer id belongs to, or null when none is linked.
     */
    public function owner(string $provider, string $customerId): ?Model;

    /**
     * Link the owner to its customer id at the provider.
     *
     * @throws BillingException when the customer id
     *                          belongs to another owner
     */
    public function remember(Model $owner, string $provider, string $customerId): void;
}
