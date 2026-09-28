<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\ChargebackReceived;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Events\PurchaseRefunded;
use RoundlyConsulting\Purchases\Events\SubscriptionRenewed;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Testing\FakeResult;

/*
 | The unique (provider, provider_id) index covers soft-deleted rows too. A notification for
 | a row the host soft-deleted must not crash on it (a 500 the store retries forever): the
 | row is kept up to date, stays deleted, and announces nothing.
 */

beforeEach(fn () => Event::fake([PurchaseCompleted::class, PurchaseRefunded::class, ChargebackReceived::class, SubscriptionStarted::class, SubscriptionRenewed::class]));

it('updates a soft-deleted purchase in place instead of crashing', function (): void {
    Purchases::sync(FakeResult::purchase('stripe', 'pi_trashed', Status::Pending));
    Purchase::query()->sole()->delete();

    Purchases::sync(FakeResult::purchase('stripe', 'pi_trashed'));

    $purchase = Purchase::withTrashed()->sole();

    expect($purchase->trashed())->toBeTrue()
        ->and($purchase->status)->toBe(Status::Completed);
    Event::assertNotDispatched(PurchaseCompleted::class);
});

it('updates a soft-deleted subscription in place instead of crashing', function (): void {
    Purchases::sync(FakeResult::subscription('apple', 'orig-trashed'));
    Subscription::query()->sole()->delete();

    Purchases::sync(FakeResult::subscription('apple', 'orig-trashed', Status::InGracePeriod));

    $subscription = Subscription::withTrashed()->sole();

    expect($subscription->trashed())->toBeTrue()
        ->and($subscription->status)->toBe(Status::InGracePeriod);
    Event::assertDispatchedTimes(SubscriptionStarted::class, 1);
});

it('updates a soft-deleted refund in place instead of crashing', function (): void {
    Purchases::sync(FakeResult::refund('stripe', 're_trashed'));
    PurchaseRefund::query()->sole()->delete();

    Purchases::sync(FakeResult::refund('stripe', 're_trashed', chargeback: true));

    $refund = PurchaseRefund::withTrashed()->sole();

    expect($refund->trashed())->toBeTrue()
        ->and($refund->chargeback)->toBeTrue();
    Event::assertDispatchedTimes(PurchaseRefunded::class, 1);
    Event::assertNotDispatched(ChargebackReceived::class);
});

it('still refunds a soft-deleted purchase', function (): void {
    Purchases::sync(FakeResult::purchase('stripe', 'pi_gone'));
    Purchase::query()->sole()->delete();

    Purchases::sync(FakeResult::refund('stripe', 'pi_gone'));

    expect(Purchase::withTrashed()->sole()->status)->toBe(Status::Refunded)
        ->and(PurchaseRefund::query()->sole()->purchase_id)->toBe(Purchase::withTrashed()->sole()->id);
});
