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
 * Stores deliver at least once and in no particular order, and the audit log replays: a
 * result older than what its row last applied changes nothing and fires nothing (see
 * EventOrder), and a soft-deleted row is kept up to date but fires no lifecycle event.
 *
 * A Notification or Unknown result is informational — a store event that changes no
 * entitlement (a renewal preference, a price increase, a declined refund, a test) or one
 * this package does not map. It is recorded nowhere, fires nothing, and yields null, so
 * it can never overwrite a subscription's status.
 *
 * The shared core of `Purchases::handle()`, `sync()`, `replay()` and the queued job. It
 * touches no audit row — each caller owns that step — so reach it through the facade.
 *
 * @internal
 */
final readonly class RecordProviderResultAction
{
    public function __construct(
        private RecordPurchaseAction $recordPurchase,
        private RecordSubscriptionAction $recordSubscription,
        private RecordRefundAction $recordRefund,
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

        if ($purchase->trashed() || ! self::statusChanged($purchase)) {
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

        // A row the host soft-deleted is kept up to date, but announces nothing.
        if ($subscription->trashed()) {
            return $subscription;
        }

        $changed = self::statusChanged($subscription);

        match ($result->status()) {
            Status::Completed => match (true) {
                $subscription->wasRecentlyCreated || self::firstActivated($subscription) => SubscriptionStarted::dispatch($subscription, $result),
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
     * Whether a subscription that was never active — created awaiting its first payment
     * (Stripe `incomplete`, Google `SUBSCRIPTION_STATE_PENDING`) — has just activated: that
     * is its start, not a renewal. A paused Google subscription (Processing) was active
     * before the pause, so its resumption stays a renewal.
     */
    private static function firstActivated(Subscription $subscription): bool
    {
        if (! $subscription->wasChanged('status')) {
            return false;
        }

        $previous = $subscription->getPrevious()['status'] ?? null;
        $previous = $previous instanceof Status ? $previous : Status::tryFrom(is_string($previous) ? $previous : '');

        return $previous === Status::New || $previous === Status::Pending;
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

        if ($refund->trashed()) {
            return $refund;
        }

        // A reversed chargeback (a dispute won) is no new chargeback: it announces only the
        // purchase it reinstated, if it reinstated one.
        if ($result->isChargeback() && $result->status() === Status::Completed) {
            $purchase = $refund->relationLoaded('purchase') ? $refund->getRelation('purchase') : null;

            if ($purchase instanceof Purchase && ! $purchase->trashed() && $purchase->wasChanged('status')) {
                PurchaseCompleted::dispatch($purchase, $result);
            }

            return $refund;
        }

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
