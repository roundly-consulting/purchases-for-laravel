<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Carbon\CarbonInterface;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordRefundData;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Support\EventOrder;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Support\PurchaseRefundModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

/**
 * Idempotently records a refund / chargeback, links it to the originating
 * purchase when one can be matched, and flips that purchase's status.
 *
 * A refund of a subscription's current period (its latest transaction — an Apple
 * transaction, a Google order) or keyed to the subscription itself (Google's purchase
 * token) also revokes that subscription — never one that refunds an earlier period. Only
 * a full refund (a Refunded result) flips anything; a partial one is recorded alone. A
 * reversed chargeback (a Completed chargeback — a dispute won) reinstates the refunded
 * purchase it was linked to, and never creates one.
 *
 * Events are ordered (see EventOrder): a refund event older than the last one applied to
 * its row changes nothing, and a purchase or subscription is only flipped when the refund
 * is not older than what that row last applied — so a replayed chargeback cannot undo a
 * dispute that was since won. The rows involved are locked while that is decided.
 *
 * The purchase the refund was linked to is handed back as the refund's `purchase`
 * relation, so the caller can see whether this call changed its status.
 *
 * @internal building block of `Purchases::handle()` / `sync()` — reach it through the facade.
 */
final readonly class RecordRefundAction
{
    /**
     * On MySQL the lock-read of a key that does not exist yet takes a gap lock, so two first
     * deliveries of one key can deadlock on their inserts; the loser is retried, and then
     * finds the winner's row.
     */
    private const int ATTEMPTS = 3;

    public function execute(RecordRefundData $data): PurchaseRefund
    {
        /** @var PurchaseRefund */
        return PurchaseRefundModel::new()->getConnection()->transaction(function () use ($data): PurchaseRefund {
            $purchase = $this->relatedPurchase($data);
            $keys = ['provider' => $data->provider, 'provider_id' => $data->providerId];

            /** @var PurchaseRefund|null $refund */
            $refund = PurchaseRefundModel::query()->withTrashed()->where($keys)->lockForUpdate()->first();

            if ($refund === null) {
                /** @var PurchaseRefund $refund */
                $refund = PurchaseRefundModel::query()->withTrashed()->createOrFirst($keys, $this->attributes($data, $purchase, null));

                if (! $refund->wasRecentlyCreated) {
                    // Another delivery created it first: apply this one as an update.
                    /** @var PurchaseRefund $refund */
                    $refund = PurchaseRefundModel::query()->withTrashed()->lockForUpdate()->findOrFail($refund->getKey());
                }
            }

            if (! $refund->wasRecentlyCreated) {
                if (EventOrder::isStale($refund->last_event_at, $data->occurredAt)) {
                    return $refund;
                }

                $refund->update($this->attributes($data, $purchase, $refund));
            }

            // A partial refund (status other than Refunded) is recorded, but the purchase or
            // subscription it came from stays as it is.
            if ($data->status === Status::Refunded) {
                $this->move($purchase, Status::Refunded, $data->occurredAt);
                $this->move($this->relatedSubscription($data), Status::Refunded, $data->occurredAt);
            } elseif ($data->chargeback && $data->status === Status::Completed && $purchase?->status === Status::Refunded) {
                // A chargeback reversed (a dispute won) returns the funds: the purchase they
                // were taken from is reinstated — only one that exists, never a new one.
                $this->move($purchase, Status::Completed, $data->occurredAt);
            }

            $refund->refresh();

            if ($purchase !== null) {
                $refund->setRelation('purchase', $purchase);
            }

            return $refund;
        }, attempts: self::ATTEMPTS);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(RecordRefundData $data, ?Purchase $purchase, ?PurchaseRefund $refund): array
    {
        $attributes = [
            'transaction_id' => $data->transactionId,
            'reason' => $data->reason,
            'chargeback' => $data->chargeback,
            'refunded_at' => $data->refundedAt,
            'meta' => $data->meta,
            'last_event_at' => EventOrder::latest($refund?->last_event_at, $data->occurredAt),
        ];

        // A later event that cannot find the purchase never unlinks one already found.
        if ($purchase !== null || $refund === null) {
            $attributes['purchase_id'] = $purchase?->getKey();
        }

        if ($data->price !== null) {
            $attributes['price'] = $data->price;
        }

        return $attributes;
    }

    /**
     * Move a purchase or subscription to a status, unless the event is older than what the
     * row already applied (or would un-refund it without being provably newer).
     */
    private function move(Purchase|Subscription|null $model, Status $status, ?CarbonInterface $occurredAt): void
    {
        if ($model === null || ! EventOrder::permits($model->last_event_at, $model->status, $occurredAt, $status)) {
            return;
        }

        $model->update([
            'status' => $status,
            'last_event_at' => EventOrder::latest($model->last_event_at, $occurredAt),
        ]);
    }

    private function relatedSubscription(RecordRefundData $data): ?Subscription
    {
        $query = SubscriptionModel::query()->withTrashed()->where('provider', $data->provider)->lockForUpdate();

        // The refunded transaction is the subscription's current period.
        if ($data->transactionId !== null) {
            /** @var Subscription|null $current */
            $current = (clone $query)->where('transaction_id', $data->transactionId)->first();

            if ($current !== null) {
                return $current;
            }
        }

        /** @var Subscription|null $subscription */
        $subscription = $query->where('provider_id', $data->providerId)->first();

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
        $query = PurchaseModel::query()->withTrashed()->where('provider', $data->provider)->lockForUpdate();

        if ($data->transactionId !== null) {
            /** @var Purchase|null $match */
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

        /** @var Purchase|null */
        return $query->where('provider_id', $data->providerId)->first();
    }
}
