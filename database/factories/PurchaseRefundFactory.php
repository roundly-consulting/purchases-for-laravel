<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Support\PurchaseRefundModel;

/** @extends Factory<PurchaseRefund> */
final class PurchaseRefundFactory extends Factory
{
    protected $model = PurchaseRefund::class;

    /**
     * Build the model the host configured in `purchases.models.purchase-refund`, not the packaged
     * one, so a host subclass comes out of the factory with its own events, observers and table.
     *
     * @return class-string<PurchaseRefund>
     */
    public function modelName(): string
    {
        return PurchaseRefundModel::class();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'purchase_id' => null,
            'provider' => 'stripe',
            'provider_id' => (string) $this->faker->uuid(),
            'transaction_id' => (string) $this->faker->uuid(),
            'reason' => null,
            'chargeback' => false,
            'price' => Money::ofMinor($this->faker->numberBetween(99, 99999), 'USD'),
            'refunded_at' => now(),
            'meta' => [],
        ];
    }

    public function chargeback(): self
    {
        return $this->state(fn (): array => ['chargeback' => true, 'reason' => 'dispute']);
    }
}
