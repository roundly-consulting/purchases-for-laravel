<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use RoundlyConsulting\Purchases\DataTransferObjects\RecordPurchaseData;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Support\EventOrder;
use RoundlyConsulting\Purchases\Support\PurchaseItemModel;
use RoundlyConsulting\Purchases\Support\PurchaseModel;

/**
 * Idempotently records a provider purchase into the Purchase model, keyed on
 * provider + provider_id, syncing its line items.
 *
 * An event older than the last one applied to the row changes nothing, and a refunded
 * purchase is only reinstated by an event provably newer than its refund (see EventOrder).
 * The row is locked while that is decided, so two deliveries racing each other cannot
 * interleave. A soft-deleted row is updated in place and stays deleted.
 *
 * @internal building block of `Purchases::handle()` / `sync()` — reach it through the facade.
 */
final readonly class RecordPurchaseAction
{
    /**
     * On MySQL the lock-read of a key that does not exist yet takes a gap lock, so two first
     * deliveries of one key can deadlock on their inserts; the loser is retried, and then
     * finds the winner's row.
     */
    private const int ATTEMPTS = 3;

    public function execute(RecordPurchaseData $data): Purchase
    {
        $keys = ['provider' => $data->provider, 'provider_id' => $data->providerId];

        /** @var Purchase */
        return PurchaseModel::new()->getConnection()->transaction(function () use ($data, $keys): Purchase {
            /** @var Purchase|null $purchase */
            $purchase = PurchaseModel::query()->withTrashed()->where($keys)->lockForUpdate()->first();

            if ($purchase === null) {
                /** @var Purchase $purchase */
                $purchase = PurchaseModel::query()->withTrashed()->createOrFirst($keys, $this->attributes($data, null));

                if (! $purchase->wasRecentlyCreated) {
                    // Another delivery created it first: apply this one as an update.
                    /** @var Purchase $purchase */
                    $purchase = PurchaseModel::query()->withTrashed()->lockForUpdate()->findOrFail($purchase->getKey());
                }
            }

            if (! $purchase->wasRecentlyCreated) {
                if (! EventOrder::permits($purchase->last_event_at, $purchase->status, $data->occurredAt, $data->status)) {
                    return $purchase;
                }

                $purchase->update($this->attributes($data, $purchase));
            }

            $this->syncItems($purchase, $data);

            return $purchase->refresh();
        }, attempts: self::ATTEMPTS);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(RecordPurchaseData $data, ?Purchase $purchase): array
    {
        $attributes = [
            'status' => $data->status,
            'transaction_id' => $data->transactionId,
            'meta' => $data->meta,
            'last_event_at' => EventOrder::latest($purchase?->last_event_at, $data->occurredAt),
        ];

        if ($data->price !== null) {
            $attributes['price'] = $data->price;
        }

        return $attributes;
    }

    private function syncItems(Purchase $purchase, RecordPurchaseData $data): void
    {
        if ($data->items === []) {
            return;
        }

        $itemModel = PurchaseItemModel::class();

        $purchase->items()->delete();

        foreach ($data->items as $item) {
            $attributes = [
                'provider_id' => $item->providerId,
                'name' => $item->name,
                'quantity' => $item->quantity,
            ];

            if ($item->price !== null) {
                $attributes['price'] = $item->price;
            }

            $purchase->items()->save(new $itemModel($attributes));
        }
    }
}
