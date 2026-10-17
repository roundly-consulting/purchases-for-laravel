<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;

/** @extends Factory<PurchaseNotification> */
final class PurchaseNotificationFactory extends Factory
{
    protected $model = PurchaseNotification::class;

    /**
     * Build the model the host configured in `purchases.models.purchase-notification`, not the
     * packaged one, so a host subclass comes out of the factory with its own events, observers and
     * table.
     *
     * @return class-string<PurchaseNotification>
     */
    public function modelName(): string
    {
        return PurchaseNotificationModel::class();
    }

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
