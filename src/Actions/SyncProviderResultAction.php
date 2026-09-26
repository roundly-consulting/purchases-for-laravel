<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordPurchaseData;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordRefundData;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\ChargebackReceived;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Events\PurchaseFailed;
use RoundlyConsulting\Purchases\Events\PurchaseRecorded;
use RoundlyConsulting\Purchases\Events\PurchaseRefunded;
use RoundlyConsulting\Purchases\Events\SubscriptionCanceled;
use RoundlyConsulting\Purchases\Events\SubscriptionExpired;
use RoundlyConsulting\Purchases\Events\SubscriptionInGracePeriod;
use RoundlyConsulting\Purchases\Events\SubscriptionRenewed;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * Turns a unified provider result into a persisted model and dispatches the
 * matching lifecycle events.
 *
 * A Notification or Unknown result is informational — a store event that changes no
 * entitlement (a renewal preference, a price increase, a declined refund, a test) or one
 * this package does not map. It is recorded nowhere, fires nothing, and yields null, so
 * it can never overwrite a subscription's status.
 */
final class SyncProviderResultAction
{
    public function __construct(
        private readonly RecordPurchaseAction $recordPurchase = new RecordPurchaseAction,
        private readonly RecordSubscriptionAction $recordSubscription = new RecordSubscriptionAction,
        private readonly RecordRefundAction $recordRefund = new RecordRefundAction,
    ) {}

    public function execute(ProviderResult $result): ?Model
    {
        return match ($result->type()) {
            ResultType::Refund => $this->refund($result),
            ResultType::Purchase => $this->purchase($result),
            ResultType::Subscription => $this->subscription($result),
            ResultType::Notification, ResultType::Unknown => null,
        };
    }

    private function purchase(ProviderResult $result): Purchase
    {
        $purchase = $this->recordPurchase->execute(RecordPurchaseData::fromResult($result));

        PurchaseRecorded::dispatch($purchase, $result);

        if (! self::statusChanged($purchase)) {
            return $purchase;
        }

        match ($result->status()) {
            Status::Completed => PurchaseCompleted::dispatch($purchase, $result),
            Status::Failed, Status::Canceled => PurchaseFailed::dispatch($purchase, $result),
            default => null,
        };

        return $purchase;
    }

    private function subscription(ProviderResult $result): Subscription
    {
        $subscription = $this->recordSubscription->execute(RecordSubscriptionData::fromResult($result));

        $changed = self::statusChanged($subscription);

        match ($result->status()) {
            Status::Completed => match (true) {
                $subscription->wasRecentlyCreated => SubscriptionStarted::dispatch($subscription, $result),
                // A renewal moves the paid period forward — or brings a held subscription back.
                $changed || $subscription->wasChanged('ends_at') => SubscriptionRenewed::dispatch($subscription, $result),
                default => null,
            },
            Status::InGracePeriod => $changed ? SubscriptionInGracePeriod::dispatch($subscription, $result) : null,
            Status::Canceled => $changed ? SubscriptionCanceled::dispatch($subscription, $result) : null,
            Status::Failed => $changed ? SubscriptionExpired::dispatch($subscription, $result) : null,
            default => null,
        };

        return $subscription;
    }

    /**
     * Stores deliver at least once and may repeat a delivery: a lifecycle event states that
     * something changed, so it fires only for a new row or a status that actually moved.
     */
    private static function statusChanged(Model $model): bool
    {
        return $model->wasRecentlyCreated || $model->wasChanged('status');
    }

    private function refund(ProviderResult $result): PurchaseRefund
    {
        $refund = $this->recordRefund->execute(RecordRefundData::fromResult($result));

        // A repeated delivery of the same refund is not a new refund; a larger cumulative
        // amount (another partial refund of the same charge) is.
        if (! $refund->wasRecentlyCreated && ! $refund->wasChanged(['price', 'chargeback'])) {
            return $refund;
        }

        if ($refund->chargeback) {
            ChargebackReceived::dispatch($refund, $result);
        } else {
            PurchaseRefunded::dispatch($refund, $result);
        }

        return $refund;
    }
}
