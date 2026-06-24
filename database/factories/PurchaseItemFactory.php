<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;

/** @extends Factory<PurchaseItem> */
final class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'purchase_id' => PurchaseFactory::new(),
            'provider_id' => (string) $this->faker->uuid(),
            'name' => $this->faker->words(2, true),
            'price' => $this->faker->numberBetween(99, 99999),
            'price_currency' => 'USD',
            'quantity' => $this->faker->numberBetween(1, 5),
        ];
    }

    public function forPurchase(Purchase $purchase): self
    {
        return $this->state(['purchase_id' => $purchase->id]);
    }
}
