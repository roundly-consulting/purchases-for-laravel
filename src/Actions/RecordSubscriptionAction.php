<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordedSubscription;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
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
    /**
     * On MySQL the lock-read of a key that does not exist yet takes a gap lock, so two first
     * deliveries of one key can deadlock on their inserts; the loser is retried, and then
     * finds the winner's row.
     */
    private const int ATTEMPTS = 3;

    public function execute(RecordSubscriptionData $data): Subscription
    {
        return $this->record($data)->subscription;
    }

    /**
     * Record the subscription and report whether its items changed what it bills.
     */
    public function record(RecordSubscriptionData $data): RecordedSubscription
    {
        $keys = ['provider' => $data->provider, 'provider_id' => $data->providerId];

        /** @var RecordedSubscription */
        return SubscriptionModel::new()->getConnection()->transaction(function () use ($data, $keys): RecordedSubscription {
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
                    return new RecordedSubscription($subscription);
                }

                $subscription->update($this->attributes($data, $subscription));
            }

            $itemsChanged = $this->syncItems($subscription, $data);

            return new RecordedSubscription($subscription->refresh(), $itemsChanged);
        }, attempts: self::ATTEMPTS);
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
            'cancel_at_period_end' => $data->cancelAtPeriodEnd,
        ], static fn (mixed $value): bool => $value !== null) + [
            'status' => $data->status,
            'meta' => $data->meta,
            'last_event_at' => EventOrder::latest($subscription?->last_event_at, $data->occurredAt),
        ];

        if ($data->price !== null) {
            $attributes['price'] = $data->price;
        }

        // The billing customer's owner, for a subscription no owner is linked to yet.
        if ($data->owner !== null && $subscription?->getAttribute('owner_type') === null) {
            $attributes['owner_type'] = $data->owner->getMorphClass();
            $attributes['owner_id'] = $data->owner->getKey();
        }

        return $attributes;
    }

    /**
     * Bring the stored items in line with the result's: an item the provider identifies (a
     * Stripe `si_…`) is updated in place, a new one is added, and one the result no longer
     * lists is removed. An item-less result leaves the items alone.
     *
     * Returns whether items that were already stored changed what the subscription bills —
     * a quantity, a price or the set of items. Filling an item-less row is not a change.
     */
    private function syncItems(Subscription $subscription, RecordSubscriptionData $data): bool
    {
        if ($data->items === []) {
            return false;
        }

        $existing = $subscription->items()->get();
        $before = self::billing($existing);

        /** @var array<string, SubscriptionItem> $byProviderId */
        $byProviderId = [];

        foreach ($existing as $item) {
            if ($item->provider_id !== null) {
                $byProviderId[$item->provider_id] ??= $item;
            }
        }

        $itemModel = SubscriptionItemModel::class();
        $kept = [];

        foreach ($data->items as $item) {
            $stored = $item->providerId !== null ? ($byProviderId[$item->providerId] ?? null) : null;

            if ($stored !== null && ! isset($kept[$stored->getKey()])) {
                $stored->update(self::itemAttributes($item));
            } else {
                $stored = new $itemModel(self::itemAttributes($item));
                $subscription->items()->save($stored);
            }

            $kept[$stored->getKey()] = true;
        }

        foreach ($existing as $item) {
            if (! isset($kept[$item->getKey()])) {
                $item->delete();
            }
        }

        return $existing->isNotEmpty() && $before !== self::billing($subscription->items()->get());
    }

    /**
     * @return array<string, mixed>
     */
    private static function itemAttributes(ResultItem $item): array
    {
        $attributes = [
            'provider_id' => $item->providerId,
            'name' => $item->name,
            'price_id' => $item->priceId,
            'quantity' => $item->metered ? null : $item->quantity,
        ];

        if ($item->price !== null) {
            $attributes['price'] = $item->price;
        }

        return $attributes;
    }

    /**
     * What a set of items bills, order-independent: each item's price id, quantity and price.
     *
     * @param  Collection<int, SubscriptionItem>  $items
     * @return list<string>
     */
    private static function billing(Collection $items): array
    {
        $lines = $items->map(static fn (SubscriptionItem $item): string => implode('|', [
            $item->price_id ?? '',
            $item->quantity ?? '',
            $item->price?->minor() ?? '',
            $item->price?->currency()->code ?? '',
        ]))->all();

        sort($lines);

        return $lines;
    }
}
