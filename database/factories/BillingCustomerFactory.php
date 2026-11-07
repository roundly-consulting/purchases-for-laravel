<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Support\BillingCustomerModel;

/** @extends Factory<BillingCustomer> */
final class BillingCustomerFactory extends Factory
{
    protected $model = BillingCustomer::class;

    /**
     * Build the model the host configured in `purchases.models.billing-customer`, not the
     * packaged one, so a host subclass comes out of the factory with its own events, observers
     * and table.
     *
     * @return class-string<BillingCustomer>
     */
    public function modelName(): string
    {
        return BillingCustomerModel::class();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // An owner of no model in particular, keyed the way `purchases.key_type` keys owners;
        // link a real one with ownedBy().
        $ownerId = match (KeyType::fromConfig('purchases.key_type')) {
            KeyType::Uuid => (string) Str::uuid(),
            KeyType::Ulid => (string) Str::ulid(),
            KeyType::BigInt => $this->faker->unique()->numberBetween(1, PHP_INT_MAX),
        };

        return [
            'owner_type' => 'owner',
            'owner_id' => $ownerId,
            'provider' => 'stripe',
            'provider_id' => 'cus_'.$this->faker->regexify('[A-Za-z0-9]{14}'),
        ];
    }

    /**
     * Link the customer to an owner model.
     */
    public function ownedBy(Model $owner): self
    {
        return $this->state([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }
}
