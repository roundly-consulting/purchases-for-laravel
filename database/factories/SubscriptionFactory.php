<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Purchases\Subscription;

/** @extends Factory<Subscription> */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'apple',
            'provider_id' => (string) $this->faker->uuid(),
            'name' => $this->faker->words(2, true),
            'price' => $this->faker->numberBetween(99, 99999),
            'price_currency' => 'USD',
            'active_from' => now(),
            'trial_ends_at' => null,
            'ends_at' => null,
            'meta' => [],
        ];
    }
}
