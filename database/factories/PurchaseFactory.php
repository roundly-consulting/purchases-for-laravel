<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Support\PurchaseModel;

/** @extends Factory<Purchase> */
final class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /**
     * Build the model the host configured in `purchases.models.purchase`, not the packaged one, so
     * a host subclass comes out of the factory with its own events, observers and table.
     *
     * @return class-string<Purchase>
     */
    public function modelName(): string
    {
        return PurchaseModel::class();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'apple',
            'provider_id' => (string) $this->faker->uuid(),
            'status' => Status::Completed->value,
            'price' => Money::ofMinor($this->faker->numberBetween(99, 99999), 'USD'),
            'meta' => [],
        ];
    }
}
