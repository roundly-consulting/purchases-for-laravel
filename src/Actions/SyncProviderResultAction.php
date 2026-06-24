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
 */
final class SyncProviderResultAction
{
    public function __construct(
        private readonly RecordPurchaseAction $recordPurchase = new RecordPurchaseAction,
        private readonly RecordSubscriptionAction $recordSubscription = new RecordSubscriptionAction,
        private readonly RecordRefundAction $recordRefund = new RecordRefundAction,
    ) {}

    public function execute(ProviderResult $result): Model
    {
        return match ($result->type()) {
            ResultType::Refund => $this->refund($result),
            ResultType::Purchase => $this->purchase($result),
            default => $this->subscription($result),
        };
    }

    private function purchase(ProviderResult $result): Purchase
    {
        $purchase = $this->recordPurchase->execute(RecordPurchaseData::fromResult($result));

        PurchaseRecorded::dispatch($purchase, $result);

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

        match ($result->status()) {
            Status::Completed => $subscription->wasRecentlyCreated
                ? SubscriptionStarted::dispatch($subscription, $result)
                : SubscriptionRenewed::dispatch($subscription, $result),
            Status::InGracePeriod => SubscriptionInGracePeriod::dispatch($subscription, $result),
            Status::Canceled => SubscriptionCanceled::dispatch($subscription, $result),
            Status::Failed => SubscriptionExpired::dispatch($subscription, $result),
            default => null,
        };

        return $subscription;
    }

    private function refund(ProviderResult $result): PurchaseRefund
    {
        $refund = $this->recordRefund->execute(RecordRefundData::fromResult($result));

        if ($refund->chargeback) {
            ChargebackReceived::dispatch($refund, $result);
        } else {
            PurchaseRefunded::dispatch($refund, $result);
        }

        return $refund;
    }
}
