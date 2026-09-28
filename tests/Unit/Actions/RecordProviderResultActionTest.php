<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Actions\RecordProviderResultAction;
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
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;

function purchaseResult(Status $status = Status::Completed): GenericResult
{
    return new GenericResult('stripe', ResultType::Purchase, 'pi_1', $status);
}

function subscriptionResult(Status $status = Status::Completed, string $id = 'GPA.1', ?string $endsAt = null): GenericResult
{
    return new GenericResult('google', ResultType::Subscription, $id, $status, name: 'pro.monthly', endsAt: $endsAt !== null ? Carbon::parse($endsAt) : null);
}

it('persists a purchase and fires recorded and completed events', function (): void {
    Event::fake();

    $model = app(RecordProviderResultAction::class)->execute(purchaseResult());

    expect($model)->toBeInstanceOf(Purchase::class);
    Event::assertDispatched(PurchaseRecorded::class);
    Event::assertDispatched(PurchaseCompleted::class);
});

it('fires a purchase failed event on a failed status', function (): void {
    Event::fake();

    app(RecordProviderResultAction::class)->execute(purchaseResult(Status::Failed));

    Event::assertDispatched(PurchaseFailed::class);
});

it('fires subscription started for a new subscription', function (): void {
    Event::fake();

    $model = app(RecordProviderResultAction::class)->execute(subscriptionResult());

    expect($model)->toBeInstanceOf(Subscription::class);
    Event::assertDispatched(SubscriptionStarted::class);
});

it('fires subscription renewed for an existing subscription', function (): void {
    app(RecordProviderResultAction::class)->execute(subscriptionResult(endsAt: '2026-02-01'));

    Event::fake();
    app(RecordProviderResultAction::class)->execute(subscriptionResult(endsAt: '2026-03-01'));

    Event::assertDispatched(SubscriptionRenewed::class);
    Event::assertNotDispatched(SubscriptionStarted::class);
});

it('fires subscription canceled and expired', function (): void {
    Event::fake();

    app(RecordProviderResultAction::class)->execute(subscriptionResult(Status::Canceled, 'GPA.2'));
    app(RecordProviderResultAction::class)->execute(subscriptionResult(Status::Failed, 'GPA.3'));

    Event::assertDispatched(SubscriptionCanceled::class);
    Event::assertDispatched(SubscriptionExpired::class);
});

/*
 * Every store delivers at least once — Stripe, Apple and Pub/Sub all retry and may repeat
 * a delivery. A lifecycle event is a statement that something CHANGED, so a repeat of the
 * same state must not fire it again (a second PurchaseCompleted fulfils an order twice).
 */

it('fires purchase completed once for a repeated delivery', function (): void {
    Event::fake();

    app(RecordProviderResultAction::class)->execute(purchaseResult());
    app(RecordProviderResultAction::class)->execute(purchaseResult());

    Event::assertDispatchedTimes(PurchaseCompleted::class, 1);
    Event::assertDispatchedTimes(PurchaseRecorded::class, 2);
});

it('fires a subscription lifecycle event once for a repeated delivery', function (Status $status, string $event): void {
    Event::fake();

    app(RecordProviderResultAction::class)->execute(subscriptionResult($status, 'GPA.9', '2026-02-01'));
    app(RecordProviderResultAction::class)->execute(subscriptionResult($status, 'GPA.9', '2026-02-01'));

    Event::assertDispatchedTimes($event, 1);
})->with([
    'started' => [Status::Completed, SubscriptionStarted::class],
    'grace period' => [Status::InGracePeriod, SubscriptionInGracePeriod::class],
    'canceled' => [Status::Canceled, SubscriptionCanceled::class],
    'expired' => [Status::Failed, SubscriptionExpired::class],
]);

it('does not call an update that moves nothing a renewal', function (): void {
    app(RecordProviderResultAction::class)->execute(subscriptionResult(endsAt: '2026-02-01'));

    Event::fake();
    app(RecordProviderResultAction::class)->execute(subscriptionResult(endsAt: '2026-02-01'));

    Event::assertNotDispatched(SubscriptionRenewed::class);
});

it('fires renewed when a held subscription recovers', function (): void {
    app(RecordProviderResultAction::class)->execute(subscriptionResult(Status::OnHold, endsAt: '2026-02-01'));

    Event::fake();
    app(RecordProviderResultAction::class)->execute(subscriptionResult(Status::Completed, endsAt: '2026-02-01'));

    Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
});

it('fires a refund event once per refunded amount', function (): void {
    Event::fake();
    $refund = fn (int $minor): GenericResult => new GenericResult('stripe', ResultType::Refund, 'pi_r', Status::Completed, transactionId: 'pi_r', price: Money::ofMinor($minor, 'EUR'));

    app(RecordProviderResultAction::class)->execute($refund(1000));
    app(RecordProviderResultAction::class)->execute($refund(1000));
    app(RecordProviderResultAction::class)->execute($refund(2500));

    Event::assertDispatchedTimes(PurchaseRefunded::class, 2);
});

it('fires chargeback received once for one dispute', function (): void {
    Event::fake();
    $chargeback = new GenericResult('stripe', ResultType::Refund, 'dp_1', Status::Refunded, transactionId: 'pi_c', price: Money::ofMinor(5000, 'EUR'), chargeback: true);

    app(RecordProviderResultAction::class)->execute($chargeback);
    app(RecordProviderResultAction::class)->execute($chargeback);

    Event::assertDispatchedTimes(ChargebackReceived::class, 1);
});
