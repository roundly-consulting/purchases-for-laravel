<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;

/** @extends Factory<PurchaseNotification> */
final class PurchaseNotificationFactory extends Factory
{
    protected $model = PurchaseNotification::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => 'stripe',
            'type' => 'payment_intent.succeeded',
            'signature_verified' => true,
            'payload' => ['id' => (string) $this->faker->uuid()],
            'processed_at' => null,
        ];
    }
}
