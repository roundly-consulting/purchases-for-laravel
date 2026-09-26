<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Testing\FakeResult;
use RoundlyConsulting\Purchases\Testing\PurchasesFake;

it('swaps the manager for a fake', function (): void {
    expect(Purchases::fake())->toBeInstanceOf(PurchasesFake::class);
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
