<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Purchases\Subscription;
use RoundlyConsulting\Purchases\SubscriptionItem;

/** @extends Factory<SubscriptionItem> */
final class SubscriptionItemFactory extends Factory
{
    protected $model = SubscriptionItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'subscription_id' => SubscriptionFactory::new(),
            'provider_id' => (string) $this->faker->uuid(),
            'name' => $this->faker->words(2, true),
            'price' => $this->faker->numberBetween(99, 99999),
            'price_currency' => 'USD',
        ];
    }

    public function forSubscription(Subscription $subscription): self
    {
        return $this->state(['subscription_id' => $subscription->id]);
    }
}
