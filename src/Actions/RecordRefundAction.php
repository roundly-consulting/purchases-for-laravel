<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use RoundlyConsulting\Purchases\DataTransferObjects\RecordRefundData;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Support\PurchaseRefundModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

/**
 * Idempotently records a refund / chargeback, links it to the originating
 * purchase when one can be matched, and flips that purchase's status.
 *
 * A refund keyed to a subscription (Apple's original transaction, Google's purchase
 * token) also revokes that subscription — unless it refunds an earlier period than the
 * one the subscription is in (a transaction id other than its latest).
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
            $attributes['price'] = $data->price;
        }

        /** @var PurchaseRefund $refund */
        $refund = PurchaseRefundModel::query()->updateOrCreate(
            ['provider' => $data->provider, 'provider_id' => $data->providerId],
            $attributes,
        );

        $purchase?->update(['status' => Status::Refunded]);
        $this->relatedSubscription($data)?->update(['status' => Status::Refunded]);

        return $refund->refresh();
    }

    private function relatedSubscription(RecordRefundData $data): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = SubscriptionModel::query()
            ->where('provider', $data->provider)
            ->where('provider_id', $data->providerId)
            ->first();

        if ($subscription === null) {
            return null;
        }

        $current = $subscription->transaction_id;

        return $data->transactionId === null || $current === null || $current === $data->transactionId
            ? $subscription
            : null;
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
