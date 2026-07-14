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
        $attributes = [
            'name' => $data->name,
            'status' => $data->status,
            'transaction_id' => $data->transactionId,
            'active_from' => $data->activeFrom,
            'trial_ends_at' => $data->trialEndsAt,
            'ends_at' => $data->endsAt,
            'meta' => $data->meta,
        ];

        if ($data->price !== null) {
            $attributes['price'] = $data->price->amount;
            $attributes['price_currency'] = $data->price->currency;
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
                $attributes['price'] = $item->price->amount;
                $attributes['price_currency'] = $item->price->currency;
            }

            $subscription->items()->save(new $itemModel($attributes));
        }
    }
}
