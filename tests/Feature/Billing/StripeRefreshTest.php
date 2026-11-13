<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Purchases\Actions\Stripe\RefreshStripeSubscriptionAction;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\SubscriptionCanceled;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeInvalidRequestException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_refresh',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00', 'UTC'));
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Stripe's answer to `GET /v1/subscriptions/sub_1`, stamped with Stripe's clock.
 *
 * @param  array<string, mixed>  $overrides
 */
function refreshTestAnswer(array $overrides, Carbon $answeredAt): void
{
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(
        stripeBillingSubscription($overrides),
        200,
        ['Date' => $answeredAt->toRfc7231String()],
    )]);
}

it('reads a subscription from stripe again and records what changed, with its lifecycle events', function (): void {
    stripeWebhook('customer.subscription.created', stripeBillingSubscription(), Carbon::now()->subHour());
    Event::fake([SubscriptionCanceled::class]);
    refreshTestAnswer(['status' => 'canceled', 'canceled_at' => Carbon::now()->subMinutes(30)->getTimestamp()], Carbon::now());

    $refreshed = Purchases::stripe()->subscriptions()->refresh(Subscription::query()->sole());

    expect($refreshed->status)->toBe(Status::Canceled)
        ->and($refreshed->last_event_at?->getTimestamp())->toBe(Carbon::now()->getTimestamp())
        // A reconcile is no notification: nothing new in the audit log.
        ->and(PurchaseNotification::query()->count())->toBe(1);

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_1');
    Event::assertDispatched(SubscriptionCanceled::class);
});

it('never overwrites a newer webhook with an older answer', function (): void {
    // The webhook Stripe stamped at 12:00 arrived before the answer Stripe gave at 11:59.
    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['status' => 'past_due']), Carbon::now());
    refreshTestAnswer(['status' => 'active'], Carbon::now()->subMinute());

    $refreshed = Purchases::stripe()->subscriptions()->refresh('sub_1');

    expect($refreshed->status)->toBe(Status::InGracePeriod)
        ->and(Subscription::query()->sole()->status)->toBe(Status::InGracePeriod);
});

it('records a subscription it did not store yet, for the owner of its customer', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    Event::fake([SubscriptionStarted::class]);
    refreshTestAnswer([], Carbon::now());

    $refreshed = Purchases::stripe()->subscriptions()->refresh('sub_1');

    expect($refreshed->wasRecentlyCreated)->toBeTrue()
        ->and($refreshed->owner?->is($company))->toBeTrue()
        ->and($refreshed->items()->count())->toBe(2);
    Event::assertDispatched(SubscriptionStarted::class);
});

it('refuses a subscription that is not stripe\'s and throws what stripe refuses', function (): void {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_gone' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'No such subscription']], 404)]);

    expect(fn () => Purchases::stripe()->subscriptions()->refresh(Subscription::factory()->create(['provider' => 'google'])))->toThrow(BillingException::class, 'not a Stripe subscription')
        ->and(fn () => Purchases::stripe()->subscriptions()->refresh('sub_gone'))->toThrow(StripeInvalidRequestException::class, 'No such subscription');
});

it('logs the refresh under the fake and runs the same code through every layer', function (): void {
    refreshTestAnswer([], Carbon::now());
    $fake = Purchases::fake();

    $facade = Purchases::stripe()->subscriptions()->refresh('sub_1');
    $injected = app(PurchasesManager::class)->stripe()->subscriptions()->refresh('sub_1');
    $action = app(RefreshStripeSubscriptionAction::class)->execute('sub_1');

    expect([$facade->getKey(), $injected->getKey(), $action->getKey()])->toBe(array_fill(0, 3, Subscription::query()->sole()->getKey()));
    $fake->assertBilled('subscriptions.refresh', fn (string $subscription): bool => $subscription === 'sub_1');
    expect(fn () => $fake->assertNotBilled('subscriptions.refresh'))->toThrow(AssertionFailedError::class);
});
