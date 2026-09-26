<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Support\SubscriptionItemModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

/**
 * Idempotently records a provider subscription, keyed on provider + provider_id,
 * syncing its items and lifecycle dates.
 */
final class RecordSubscriptionAction
{
    public function execute(RecordSubscriptionData $data): Subscription
    {
        // A notification that does not know a value (a Google RTDN carries no order id or
        // expiry) leaves the stored one alone instead of wiping it.
        $attributes = array_filter([
            'transaction_id' => $data->transactionId,
            'active_from' => $data->activeFrom,
            'trial_ends_at' => $data->trialEndsAt,
            'ends_at' => $data->endsAt,
        ], static fn (mixed $value): bool => $value !== null) + [
            'name' => $data->name,
            'status' => $data->status,
            'meta' => $data->meta,
        ];

        if ($data->price !== null) {
            $attributes['price'] = $data->price;
        }

        /** @var Subscription $subscription */
        $subscription = SubscriptionModel::query()->updateOrCreate(
            ['provider' => $data->provider, 'provider_id' => $data->providerId],
            $attributes,
        );

        $this->syncItems($subscription, $data);

        return $subscription->refresh();
    }

    private function syncItems(Subscription $subscription, RecordSubscriptionData $data): void
    {
        if ($data->items === []) {
            return;
        }

        $itemModel = SubscriptionItemModel::class();

        $subscription->items()->delete();

        foreach ($data->items as $item) {
            $attributes = [
                'provider_id' => $item->providerId,
                'name' => $item->name,
            ];

            if ($item->price !== null) {
                $attributes['price'] = $item->price;
            }

            $subscription->items()->save(new $itemModel($attributes));
        }
    }
}
