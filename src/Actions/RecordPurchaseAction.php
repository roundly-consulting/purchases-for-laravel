<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use RoundlyConsulting\Purchases\DataTransferObjects\RecordPurchaseData;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;

/**
 * Idempotently records a provider purchase into the Purchase model, keyed on
 * provider + provider_id, syncing its line items.
 */
final class RecordPurchaseAction
{
    public function execute(RecordPurchaseData $data): Purchase
    {
        /** @var class-string<Purchase> $model */
        $model = config('purchases.models.purchase', Purchase::class);

        $attributes = [
            'status' => $data->status,
            'meta' => $data->meta,
        ];

        if ($data->price !== null) {
            $attributes['price'] = $data->price->amount;
            $attributes['price_currency'] = $data->price->currency;
        }

        /** @var Purchase $purchase */
        $purchase = $model::query()->updateOrCreate(
            ['provider' => $data->provider, 'provider_id' => $data->providerId],
            $attributes,
        );

        $this->syncItems($purchase, $data);

        return $purchase->refresh();
    }

    private function syncItems(Purchase $purchase, RecordPurchaseData $data): void
    {
        if ($data->items === []) {
            return;
        }

        /** @var class-string<PurchaseItem> $itemModel */
        $itemModel = config('purchases.models.purchase-item', PurchaseItem::class);

        $purchase->items()->delete();

        foreach ($data->items as $item) {
            $attributes = [
                'provider_id' => $item->providerId,
                'name' => $item->name,
                'quantity' => $item->quantity,
            ];

            if ($item->price !== null) {
                $attributes['price'] = $item->price->amount;
                $attributes['price_currency'] = $item->price->currency;
            }

            $purchase->items()->save(new $itemModel($attributes));
        }
    }
}
