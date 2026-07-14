<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use RoundlyConsulting\Purchases\DataTransferObjects\RecordRefundData;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Support\PurchaseRefundModel;

/**
 * Idempotently records a refund / chargeback, links it to the originating
 * purchase when one can be matched, and flips that purchase's status.
 */
final class RecordRefundAction
{
    public function execute(RecordRefundData $data): PurchaseRefund
    {
        $purchase = $this->relatedPurchase($data);

        $attributes = [
            'purchase_id' => $purchase?->getKey(),
            'transaction_id' => $data->transactionId,
            'reason' => $data->reason,
            'chargeback' => $data->chargeback,
            'refunded_at' => $data->refundedAt,
            'meta' => $data->meta,
        ];

        if ($data->price !== null) {
            $attributes['price'] = $data->price->amount;
            $attributes['price_currency'] = $data->price->currency;
        }

        /** @var PurchaseRefund $refund */
        $refund = PurchaseRefundModel::query()->updateOrCreate(
            ['provider' => $data->provider, 'provider_id' => $data->providerId],
            $attributes,
        );

        $purchase?->update(['status' => Status::Refunded]);

        return $refund->refresh();
    }

    private function relatedPurchase(RecordRefundData $data): ?Purchase
    {
        $query = PurchaseModel::query()->where('provider', $data->provider);

        if ($data->transactionId !== null) {
            $match = (clone $query)
                ->where(function ($builder) use ($data): void {
                    $builder
                        ->where('transaction_id', $data->transactionId)
                        ->orWhere('provider_id', $data->transactionId);
                })
                ->first();

            if ($match !== null) {
                return $match;
            }
        }

        return $query->where('provider_id', $data->providerId)->first();
    }
}
