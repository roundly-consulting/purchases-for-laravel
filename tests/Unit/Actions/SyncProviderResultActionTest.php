<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Events\PurchaseFailed;
use RoundlyConsulting\Purchases\Events\PurchaseRecorded;
use RoundlyConsulting\Purchases\Events\SubscriptionCanceled;
use RoundlyConsulting\Purchases\Events\SubscriptionExpired;
use RoundlyConsulting\Purchases\Events\SubscriptionRenewed;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;

function purchaseResult(Status $status = Status::Completed): GenericResult
{
    return new GenericResult('stripe', ResultType::Purchase, 'pi_1', $status);
}

function subscriptionResult(Status $status = Status::Completed, string $id = 'GPA.1'): GenericResult
{
    return new GenericResult('google', ResultType::Subscription, $id, $status, name: 'pro.monthly');
}

it('persists a purchase and fires recorded and completed events', function (): void {
    Event::fake();

    $model = (new SyncProviderResultAction)->execute(purchaseResult());

    expect($model)->toBeInstanceOf(Purchase::class);
    Event::assertDispatched(PurchaseRecorded::class);
    Event::assertDispatched(PurchaseCompleted::class);
});

it('fires a purchase failed event on a failed status', function (): void {
    Event::fake();

    (new SyncProviderResultAction)->execute(purchaseResult(Status::Failed));

    Event::assertDispatched(PurchaseFailed::class);
});

it('fires subscription started for a new subscription', function (): void {
    Event::fake();

    $model = (new SyncProviderResultAction)->execute(subscriptionResult());

    expect($model)->toBeInstanceOf(Subscription::class);
    Event::assertDispatched(SubscriptionStarted::class);
});

it('fires subscription renewed for an existing subscription', function (): void {
    (new SyncProviderResultAction)->execute(subscriptionResult());

    Event::fake();
    (new SyncProviderResultAction)->execute(subscriptionResult());

    Event::assertDispatched(SubscriptionRenewed::class);
    Event::assertNotDispatched(SubscriptionStarted::class);
});

it('fires subscription canceled and expired', function (): void {
    Event::fake();

    (new SyncProviderResultAction)->execute(subscriptionResult(Status::Canceled, 'GPA.2'));
    (new SyncProviderResultAction)->execute(subscriptionResult(Status::Failed, 'GPA.3'));

    Event::assertDispatched(SubscriptionCanceled::class);
    Event::assertDispatched(SubscriptionExpired::class);
});
