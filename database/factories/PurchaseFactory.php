<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;

/** @extends Factory<Purchase> */
final class PurchaseFactory extends Factory
{
    protected $model = Purchase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'apple',
            'provider_id' => (string) $this->faker->uuid(),
            'status' => Status::Completed->value,
            'price' => $this->faker->numberBetween(99, 99999),
            'price_currency' => 'USD',
            'meta' => [],
        ];
    }
}
