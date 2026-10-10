<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Purchases\Actions\RecordProviderResultAction;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Exceptions\InvalidProviderNotificationException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Testing\FakeResult;
use RoundlyConsulting\Purchases\Testing\PurchasesFake;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

it('swaps the manager for a fake that injected managers receive too', function (): void {
    $fake = Purchases::fake();

    expect($fake)->toBeInstanceOf(PurchasesFake::class)
        ->and(app(PurchasesManager::class))->toBe($fake);
});

it('records a pushed purchase result and exposes assertions', function (): void {
    $fake = Purchases::fake();
    $fake->push('stripe', FakeResult::purchase('stripe', 'pi_fake'));

    $model = Purchases::handle('stripe', Request::create('/'));

    expect($model)->toBeInstanceOf(Purchase::class);

    $fake->assertHandled('stripe');
    $fake->assertHandledCount(1);
    $fake->assertPurchaseRecorded('stripe');
});

it('records subscriptions and refunds', function (): void {
    $fake = Purchases::fake();
    $fake->push('stripe', FakeResult::subscription('stripe', 'sub_fake'));
    $fake->push('stripe', FakeResult::refund('stripe', 're_fake'));

    Purchases::handle('stripe', Request::create('/'));
    Purchases::handle('stripe', Request::create('/'));

    expect(Subscription::query()->count())->toBe(1)
        ->and(PurchaseRefund::query()->count())->toBe(1);

    $fake->assertSubscriptionStarted('stripe');
    $fake->assertRefundRecorded('stripe');
});

it('asserts nothing handled', function (): void {
    Purchases::fake()->assertNothingHandled();
});

it('fails an assertion when nothing matched', function (): void {
    $fake = Purchases::fake();

    expect(fn () => $fake->assertHandled('stripe'))->toThrow(ExpectationFailedException::class);
});

it('returns the pushed result from result()', function (): void {
    $fake = Purchases::fake();
    $fake->push('stripe', FakeResult::purchase('stripe', 'pi_result'));

    $result = $fake->result('stripe', Request::create('/'));

    expect($result->providerId())->toBe('pi_result');
});

it('falls through to the real provider when nothing is queued', function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test',
        'webhook_secret' => 'whsec',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);

    $fake = Purchases::fake();
    stripeInvoicePayments(null);

    $payload = (string) json_encode([
        'id' => 'evt_real',
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_real', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd']],
    ]);

    $timestamp = now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec');
    $request = Request::create('/', 'POST', content: $payload);
    $request->headers->set('Stripe-Signature', "t={$timestamp},v1={$signature}");

    $result = $fake->result('stripe', $request);

    expect($result->providerId())->toBe('pi_real');
});

it('returns a transient notification for a pushed informational result', function (): void {
    $fake = Purchases::fake();
    $fake->push('apple', new GenericResult(provider: 'apple', type: ResultType::Notification, providerId: 'n-1', status: Status::Processing));

    $model = Purchases::handle('apple', Request::create('/'));

    expect($model)->toBeInstanceOf(PurchaseNotification::class)
        ->and(Subscription::query()->count())->toBe(0);

    $fake->assertHandled('apple');
});

it('fails the handled asserts when they do not hold', function (): void {
    $fake = Purchases::fake();
    $fake->push('stripe', FakeResult::purchase('stripe', 'pi_count'));
    Purchases::handle('stripe', Request::create('/'));

    expect(fn () => $fake->assertHandledCount(2))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingHandled())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertHandled('apple'))->toThrow(ExpectationFailedException::class);
});

it('fails the recorded-type asserts when no such result arrived', function (): void {
    $fake = Purchases::fake();
    Purchases::sync(FakeResult::purchase('stripe', 'pi_only'));

    $fake->assertPurchaseRecorded();

    expect(fn () => $fake->assertPurchaseRecorded('apple'))->toThrow(ExpectationFailedException::class, 'for [apple]')
        ->and(fn () => $fake->assertSubscriptionStarted())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertSubscriptionRecorded())->toThrow(ExpectationFailedException::class, 'Expected a '.Subscription::class.' to be recorded.')
        ->and(fn () => $fake->assertRefundRecorded())->toThrow(ExpectationFailedException::class);
});

/*
 * assertSubscriptionStarted() promises the lifecycle event, so it holds only when the real
 * pipeline fired SubscriptionStarted. assertSubscriptionRecorded() is its "any subscription
 * result arrived" sibling.
 */

it('asserts a subscription started only when the pipeline started one', function (): void {
    $fake = Purchases::fake();
    $fake->push('stripe', FakeResult::subscription('stripe', 'sub_canceled', Status::Canceled));
    Purchases::handle('stripe', Request::create('/'));

    $fake->assertSubscriptionRecorded();
    $fake->assertSubscriptionRecorded('stripe');

    expect(fn () => $fake->assertSubscriptionStarted())->toThrow(ExpectationFailedException::class, 'Expected SubscriptionStarted to fire.')
        ->and(fn () => $fake->assertSubscriptionRecorded('apple'))->toThrow(ExpectationFailedException::class, 'for [apple]');
});

it('does not count a renewal as a start', function (): void {
    app(RecordProviderResultAction::class)->execute(FakeResult::subscription('google', 'GPA.renew'));

    $fake = Purchases::fake();
    Purchases::sync(new GenericResult('google', ResultType::Subscription, 'GPA.renew', Status::Completed, endsAt: Carbon::now()->addMonths(2)));

    $fake->assertSubscriptionRecorded('google');
    expect(fn () => $fake->assertSubscriptionStarted('google'))->toThrow(ExpectationFailedException::class);
});

it('asserts a subscription started once a pending one activates', function (): void {
    $fake = Purchases::fake();
    $fake->push('stripe', FakeResult::subscription('stripe', 'sub_pending', Status::Pending));
    $fake->push('stripe', FakeResult::subscription('stripe', 'sub_pending'));

    Purchases::handle('stripe', Request::create('/'));
    expect(fn () => $fake->assertSubscriptionStarted())->toThrow(ExpectationFailedException::class);

    Purchases::handle('stripe', Request::create('/'));
    $fake->assertSubscriptionStarted();
    $fake->assertSubscriptionStarted('stripe');

    expect(fn () => $fake->assertSubscriptionStarted('apple'))->toThrow(ExpectationFailedException::class, 'Expected SubscriptionStarted to fire for [apple].');
});

it('sees a started subscription while events are faked', function (bool $eventsFakedFirst): void {
    if ($eventsFakedFirst) {
        Event::fake();
    }

    $fake = Purchases::fake();

    if (! $eventsFakedFirst) {
        Event::fake([SubscriptionStarted::class]);
    }

    Purchases::sync(FakeResult::subscription('google', 'GPA.faked'));

    $fake->assertSubscriptionStarted('google');
    Event::assertDispatched(SubscriptionStarted::class);
})->with(['events faked before' => [true], 'events faked after' => [false]]);

it('records sync() and asserts on it', function (): void {
    $fake = Purchases::fake();

    $fake->assertNothingSynced();
    expect(fn () => $fake->assertSynced())->toThrow(ExpectationFailedException::class, 'Expected a result to be synced.');

    $model = Purchases::sync(FakeResult::subscription('google', 'GPA.fake'));

    expect($model)->toBeInstanceOf(Subscription::class)
        ->and($fake->syncedResults())->toHaveCount(1)
        ->and(PurchaseNotification::query()->count())->toBe(1);

    $fake->assertSynced();
    $fake->assertSynced('google');
    $fake->assertSubscriptionStarted('google');
    $fake->assertNothingHandled();

    expect(fn () => $fake->assertSynced('apple'))->toThrow(ExpectationFailedException::class, 'for [apple]')
        ->and(fn () => $fake->assertNothingSynced())->toThrow(ExpectationFailedException::class);
});

it('records replay() by model or id and asserts on it', function (): void {
    $refund = auditedNotification(FakeResult::refund('apple', 'r-1'));
    $purchase = auditedNotification(FakeResult::purchase('stripe', 'pi_r'));
    $untouched = auditedNotification(FakeResult::purchase('stripe', 'pi_untouched'));

    $fake = Purchases::fake();

    $fake->assertNothingReplayed();
    expect(fn () => $fake->assertReplayed())->toThrow(ExpectationFailedException::class, 'Expected a notification to be replayed.');

    Purchases::replay($refund);
    Purchases::replay((int) $purchase->getKey());

    $fake->assertReplayed();
    $fake->assertReplayed($refund);
    $fake->assertReplayed((int) $purchase->getKey());
    $fake->assertRefundRecorded('apple');
    $fake->assertPurchaseRecorded('stripe');

    expect(PurchaseRefund::query()->count())->toBe(1)
        ->and(fn () => $fake->assertReplayed($untouched))->toThrow(ExpectationFailedException::class, "notification #{$untouched->getKey()}")
        ->and(fn () => $fake->assertNothingReplayed())->toThrow(ExpectationFailedException::class);
});

it('records nothing for a replay that is refused', function (): void {
    $notification = auditedNotification(FakeResult::purchase('stripe', 'pi_refused'));
    $notification->update(['signature_verified' => false]);

    $fake = Purchases::fake();

    expect(fn () => Purchases::replay($notification))->toThrow(InvalidProviderNotificationException::class);

    $fake->assertNothingReplayed();
});

it('sees replays made by purchases:replay', function (): void {
    $notification = auditedNotification(FakeResult::purchase('stripe', 'pi_cli'));

    $fake = Purchases::fake();

    $this->artisan('purchases:replay')->assertSuccessful();

    $fake->assertReplayed($notification);
});

it('sees what the model trait reads through the same manager', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $fake = Purchases::fake();

    $subscription = Purchases::sync(FakeResult::subscription('apple', 'sub_trait'));
    ownedBy($user, $subscription instanceof Subscription ? $subscription : throw new LogicException);

    // HasPurchases is read-only: it has nothing to record, but it must resolve the fake.
    expect($user->subscribedTo('pro'))->toBeTrue()
        ->and($user->activeSubscription()?->is($subscription))->toBeTrue();

    $fake->assertSynced('apple');
});

/*
 * A fake result looks like a real one: one id for the provider and the transaction (as
 * Stripe's results have), and a time it happened, so event ordering applies in tests too.
 */

it('builds fake results with one id and the time they happened', function (): void {
    Carbon::setTestNow('2026-03-01 10:00:00');

    try {
        foreach ([FakeResult::purchase(), FakeResult::subscription(), FakeResult::refund()] as $result) {
            expect($result->transactionId())->toBe($result->providerId())
                ->and($result->occurredAt()?->equalTo(Carbon::now()))->toBeTrue();
        }
    } finally {
        Carbon::setTestNow();
    }
});

it('orders fake results by when they happened', function (): void {
    $at = Carbon::parse('2026-03-01 10:00:00');
    Purchases::fake();

    Purchases::sync(FakeResult::purchase('stripe', 'pi_ordered', occurredAt: $at));
    // An older delivery arriving late changes nothing.
    Purchases::sync(FakeResult::purchase('stripe', 'pi_ordered', Status::Failed, occurredAt: $at->copy()->subMinute()));

    expect(Purchase::query()->sole()->status)->toBe(Status::Completed)
        ->and(FakeResult::subscription(occurredAt: $at)->occurredAt()?->equalTo($at))->toBeTrue()
        ->and(FakeResult::refund(occurredAt: $at)->occurredAt()?->equalTo($at))->toBeTrue();
});
