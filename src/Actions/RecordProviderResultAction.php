<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Actions\Stripe\SetStripeDefaultPaymentMethodAction;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordPurchaseData;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordRefundData;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Signal;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\ChargebackReceived;
use RoundlyConsulting\Purchases\Events\DefaultPaymentMethodUpdated;
use RoundlyConsulting\Purchases\Events\PaymentActionRequired;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Events\PurchaseFailed;
use RoundlyConsulting\Purchases\Events\PurchaseRecorded;
use RoundlyConsulting\Purchases\Events\PurchaseRefunded;
use RoundlyConsulting\Purchases\Events\SubscriptionCanceled;
use RoundlyConsulting\Purchases\Events\SubscriptionExpired;
use RoundlyConsulting\Purchases\Events\SubscriptionInGracePeriod;
use RoundlyConsulting\Purchases\Events\SubscriptionOnHold;
use RoundlyConsulting\Purchases\Events\SubscriptionRenewed;
use RoundlyConsulting\Purchases\Events\SubscriptionResumed;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Events\SubscriptionTrialWillEnd;
use RoundlyConsulting\Purchases\Events\SubscriptionUpdated;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

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
 * it can never overwrite a subscription's status. A notification may still carry a signal
 * (a payment waiting for the customer), which fires its own event — or, for a setup Checkout
 * that collected a payment method, makes that method the Stripe customer's default first.
 *
 * Signals — SubscriptionTrialWillEnd, PaymentActionRequired, PaymentMethodCollected — say that
 * something happened rather than that a status moved, so a repeat cannot be told from the first
 * delivery: they act whenever `$signals` is on, which every caller but the replay of an already
 * processed notification leaves it.
 *
 * A purchase or subscription no owner is linked to yet is linked to the owner of the billing
 * customer the result names (a Stripe `customer`, see BillingCustomers), or to the owner the
 * caller hands in; an owner already linked is never replaced.
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
        private BillingCustomers $customers,
        private SetStripeDefaultPaymentMethodAction $defaultPaymentMethod,
    ) {}

    public function execute(ProviderResult $result, ?Model $owner = null, bool $signals = true): ?Model
    {
        return match ($result->type()) {
            ResultType::Refund => $this->refund($result),
            ResultType::Purchase => $this->purchase($result, $owner),
            ResultType::Subscription => $this->subscription($result, $owner, $signals),
            ResultType::Notification, ResultType::Unknown => $this->notification($result, $owner, $signals),
        };
    }

    /**
     * An informational result records nothing; one that signals a payment waiting for the
     * customer announces it, with the subscription it bills and its owner when they are known.
     * A setup Checkout that collected a payment method makes it the customer's default.
     */
    private function notification(ProviderResult $result, ?Model $owner, bool $signals): null
    {
        if (! $signals || ! $result instanceof GenericResult) {
            return null;
        }

        if ($result->signal() === Signal::PaymentMethodCollected) {
            $this->collected($result, $owner);

            return null;
        }

        if ($result->signal() !== Signal::PaymentActionRequired) {
            return null;
        }

        $subscription = $result->subscriptionId() !== null
            ? SubscriptionModel::query()->where('provider', $result->provider())->where('provider_id', $result->subscriptionId())->first()
            : null;

        $url = $result->raw()['hosted_invoice_url'] ?? null;

        PaymentActionRequired::dispatch(
            $result,
            $subscription,
            $owner ?? $subscription->owner ?? $this->owner($result, null),
            is_string($url) && $url !== '' ? $url : null,
        );

        return null;
    }

    /**
     * Make the payment method a setup Checkout collected the customer's default, and announce
     * it. Without a secret key, or with nothing to set, nothing happens.
     */
    private function collected(GenericResult $result, ?Model $owner): void
    {
        $customer = $result->customerId();
        $paymentMethod = $result->provider() === 'stripe' ? $this->defaultPaymentMethod->execute($result) : null;

        if ($customer === null || $paymentMethod === null) {
            return;
        }

        DefaultPaymentMethodUpdated::dispatch($result, $customer, $paymentMethod, $this->owner($result, $owner));
    }

    /**
     * The owner a recorded row should be linked to: the caller's, or the owner of the billing
     * customer the result names.
     */
    private function owner(ProviderResult $result, ?Model $owner): ?Model
    {
        if ($owner !== null) {
            return $owner;
        }

        $customer = $result instanceof GenericResult ? $result->customerId() : null;

        return $customer !== null ? $this->customers->owner($result->provider(), $customer) : null;
    }

    private function purchase(ProviderResult $result, ?Model $owner): Purchase
    {
        $purchase = $this->recordPurchase->execute(RecordPurchaseData::fromResult($result, $this->owner($result, $owner)));

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

    private function subscription(ProviderResult $result, ?Model $owner, bool $signals): Subscription
    {
        $recorded = $this->recordSubscription->record(RecordSubscriptionData::fromResult($result, $this->owner($result, $owner)));
        $subscription = $recorded->subscription;

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
            Status::OnHold => $changed ? SubscriptionOnHold::dispatch($subscription, $result) : null,
            Status::Canceled => $changed ? SubscriptionCanceled::dispatch($subscription, $result) : null,
            Status::Failed => $changed ? SubscriptionExpired::dispatch($subscription, $result) : null,
            default => null,
        };

        // Back from a hold — announced next to the renewal it always was.
        if ($result->status() === Status::Completed && self::previousStatus($subscription) === Status::OnHold) {
            SubscriptionResumed::dispatch($subscription, $result);
        }

        if ($signals && $result instanceof GenericResult && $result->signal() === Signal::TrialWillEnd) {
            SubscriptionTrialWillEnd::dispatch($subscription, $result);
        }

        // A seat count, a swapped price, an item added or removed.
        if ($recorded->itemsChanged && ! $subscription->wasRecentlyCreated) {
            SubscriptionUpdated::dispatch($subscription, $result);
        }

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
        $previous = self::previousStatus($subscription);

        return $previous === Status::New || $previous === Status::Pending;
    }

    /**
     * The status this recording moved the subscription away from, or null when it did not
     * move it.
     */
    private static function previousStatus(Subscription $subscription): ?Status
    {
        if (! $subscription->wasChanged('status')) {
            return null;
        }

        $previous = $subscription->getPrevious()['status'] ?? null;

        return $previous instanceof Status ? $previous : Status::tryFrom(is_string($previous) ? $previous : '');
    }

    /**
     * Stores deliver at least once and may repeat a delivery: a lifecycle event states that
     * something changed, so it fires only for a new row or a status that actually moved.
     */
    private static function statusChanged(Model $model): bool
    {
        return $model->wasRecentlyCreated || $model->wasChanged('status');
    }

    /**
     * The owner of the purchase a refund or chargeback is linked to, if any.
     */
    private static function purchaseOwner(PurchaseRefund $refund): ?Model
    {
        $purchase = $refund->purchase_id !== null ? $refund->purchase : null;
        $owner = $purchase?->owner;

        return $owner instanceof Model ? $owner : null;
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
            ChargebackReceived::dispatch($refund, $result, $this->owner($result, null) ?? self::purchaseOwner($refund));
        } else {
            PurchaseRefunded::dispatch($refund, $result);
        }

        return $refund;
    }
}
