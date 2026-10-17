<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;
use RoundlyConsulting\Purchases\Support\PurchaseItemModel;

/** @extends Factory<PurchaseItem> */
final class PurchaseItemFactory extends Factory
{
    protected $model = PurchaseItem::class;

    /**
     * Build the model the host configured in `purchases.models.purchase-item`, not the packaged
     * one, so a host subclass comes out of the factory with its own events, observers and table.
     *
     * @return class-string<PurchaseItem>
     */
    public function modelName(): string
    {
        return PurchaseItemModel::class();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'purchase_id' => PurchaseFactory::new(),
            'provider_id' => (string) $this->faker->uuid(),
            'name' => $this->faker->words(2, true),
            'price' => Money::ofMinor($this->faker->numberBetween(99, 99999), 'USD'),
            'quantity' => $this->faker->numberBetween(1, 5),
        ];
    }

    public function forPurchase(Purchase $purchase): self
    {
        return $this->state(['purchase_id' => $purchase->id]);
    }
}
