<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Support\EventOrder;
use RoundlyConsulting\Purchases\Support\SubscriptionItemModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

/**
 * Idempotently records a provider subscription, keyed on provider + provider_id,
 * syncing its items and lifecycle dates.
 *
 * An event older than the last one applied to the row changes nothing, and a refunded
 * subscription is only reinstated by an event provably newer than its refund (see
 * EventOrder). The row is locked while that is decided. A soft-deleted row is updated in
 * place and stays deleted.
 *
 * @internal building block of `Purchases::handle()` / `sync()` — reach it through the facade.
 */
final readonly class RecordSubscriptionAction
{
    public function execute(RecordSubscriptionData $data): Subscription
    {
        $keys = ['provider' => $data->provider, 'provider_id' => $data->providerId];

        /** @var Subscription */
        return SubscriptionModel::new()->getConnection()->transaction(function () use ($data, $keys): Subscription {
            /** @var Subscription|null $subscription */
            $subscription = SubscriptionModel::query()->withTrashed()->where($keys)->lockForUpdate()->first();

            if ($subscription === null) {
                /** @var Subscription $subscription */
                $subscription = SubscriptionModel::query()->withTrashed()->createOrFirst($keys, $this->attributes($data, null));

                if (! $subscription->wasRecentlyCreated) {
                    // Another delivery created it first: apply this one as an update.
                    /** @var Subscription $subscription */
                    $subscription = SubscriptionModel::query()->withTrashed()->lockForUpdate()->findOrFail($subscription->getKey());
                }
            }

            if (! $subscription->wasRecentlyCreated) {
                if (! EventOrder::permits($subscription->last_event_at, $subscription->status, $data->occurredAt, $data->status)) {
                    return $subscription;
                }

                $subscription->update($this->attributes($data, $subscription));
            }

            $this->syncItems($subscription, $data);

            return $subscription->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(RecordSubscriptionData $data, ?Subscription $subscription): array
    {
        // A notification that does not know a value (a Google RTDN carries no order id or
        // expiry, Stripe names no plan) leaves the stored one alone instead of wiping it —
        // so a name the host gave a Stripe subscription sticks.
        $attributes = array_filter([
            'transaction_id' => $data->transactionId,
            'name' => $subscription === null ? $data->name ?? $data->productId ?? $data->providerId : $data->name,
            'active_from' => $data->activeFrom,
            'trial_ends_at' => $data->trialEndsAt,
            'ends_at' => $data->endsAt,
        ], static fn (mixed $value): bool => $value !== null) + [
            'status' => $data->status,
            'meta' => $data->meta,
            'last_event_at' => EventOrder::latest($subscription?->last_event_at, $data->occurredAt),
        ];

        if ($data->price !== null) {
            $attributes['price'] = $data->price;
        }

        return $attributes;
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
