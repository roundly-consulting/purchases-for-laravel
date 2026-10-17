<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

/** @extends Factory<Subscription> */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * Build the model the host configured in `purchases.models.subscription`, not the packaged
     * one, so a host subclass comes out of the factory with its own events, observers and table.
     *
     * @return class-string<Subscription>
     */
    public function modelName(): string
    {
        return SubscriptionModel::class();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'apple',
            'provider_id' => (string) $this->faker->uuid(),
            'name' => $this->faker->words(2, true),
            'status' => Status::Completed->value,
            'price' => Money::ofMinor($this->faker->numberBetween(99, 99999), 'USD'),
            'active_from' => now(),
            'trial_ends_at' => null,
            'ends_at' => null,
            'meta' => [],
        ];
    }
}
