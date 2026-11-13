<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Purchases\Actions\Stripe\ScheduleStripeCancellationAction;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeInvalidRequestException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\CancelAt;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\ProrationBehavior;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Support\SubscriptionColumns;
use RoundlyConsulting\Purchases\Testing\FakeResult;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_cancel_at',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00', 'UTC'));
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Carbon::setTestNow();
    SubscriptionColumns::flush();
});

it('cancels at a chosen date and stores it', function (): void {
    $at = Carbon::parse('2027-03-01 00:00:00', 'UTC');
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription(['cancel_at' => $at->getTimestamp()]))]);

    $subscription = Purchases::stripe()->subscriptions()->cancelAt('sub_1', $at, idempotencyKey: 'cancel-at-1');

    expect($subscription->cancels_at?->getTimestamp())->toBe($at->getTimestamp())
        ->and($subscription->cancel_at_period_end)->toBeFalse()
        ->and(Subscription::query()->sole()->cancels_at?->getTimestamp())->toBe($at->getTimestamp());

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_1'
        && $request->hasHeader('Idempotency-Key', 'cancel-at-1')
        && stripeForm($request) === ['cancel_at' => (string) $at->getTimestamp()]);
});

it('cancels at a period helper, stored as the date stripe resolved it to', function (CancelAt $helper): void {
    $resolved = Carbon::parse('2027-11-01 00:00:00', 'UTC');
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription(['cancel_at' => $resolved->getTimestamp()]))]);

    $subscription = Purchases::stripe()->subscriptions()->cancelAt('sub_1', $helper);

    expect($subscription->cancels_at?->getTimestamp())->toBe($resolved->getTimestamp());
    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request) === ['cancel_at' => $helper->value]);
})->with([CancelAt::MaxPeriodEnd, CancelAt::MinPeriodEnd]);

it('refuses a cancellation date that is not in the future', function (Carbon $at): void {
    Http::fake();

    expect(fn () => Purchases::stripe()->subscriptions()->cancelAt('sub_1', $at))->toThrow(BillingException::class, 'must be in the future');
    Http::assertNothingSent();
})->with([
    'now' => fn (): Carbon => Carbon::now(),
    'past' => fn (): Carbon => Carbon::now()->subDay(),
]);

it('refuses a subscription of another provider, or without a stripe id', function (): void {
    Http::fake();
    $apple = Subscription::factory()->create(['provider' => 'apple']);

    expect(fn () => Purchases::stripe()->subscriptions()->cancelAt($apple, CancelAt::MaxPeriodEnd))->toThrow(BillingException::class, 'not a Stripe subscription')
        ->and(fn () => Purchases::stripe()->subscriptions()->cancelAt('', CancelAt::MaxPeriodEnd))->toThrow(BillingException::class, 'has no Stripe id');
});

it('stores the date cancel_at_period_end resolves to, and clears it on resume', function (): void {
    $periodEnd = Carbon::parse('2026-12-01 00:00:00', 'UTC');
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()
        ->push(stripeBillingSubscription(['cancel_at_period_end' => true, 'cancel_at' => $periodEnd->getTimestamp()]))
        ->push(stripeBillingSubscription(['cancel_at_period_end' => true, 'cancel_at' => $periodEnd->getTimestamp()]))
        ->push(stripeBillingSubscription(['cancel_at_period_end' => false, 'cancel_at' => null])),
    ]);

    $scheduled = Purchases::stripe()->subscriptions()->cancelAtPeriodEnd('sub_1');
    expect($scheduled->cancels_at?->getTimestamp())->toBe($periodEnd->getTimestamp());

    $resumed = Purchases::stripe()->subscriptions()->resume('sub_1');
    expect($resumed->cancels_at)->toBeNull()
        ->and($resumed->cancel_at_period_end)->toBeFalse();
});

/**
 * The POSTs a test sent, as their form bodies.
 *
 * @return list<array<array-key, mixed>>
 */
function cancelAtTestPosts(): array
{
    return Http::recorded(fn (HttpRequest $request): bool => $request->method() === 'POST')
        ->map(fn (array $pair): array => stripeForm($pair[0]))
        ->values()
        ->all();
}

it('resumes by reading the subscription first and sending only the parameter that undoes it', function (array $current, array $expected): void {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()
        ->push(stripeBillingSubscription($current))
        ->push(stripeBillingSubscription(['cancel_at_period_end' => false, 'cancel_at' => null])),
    ]);

    $resumed = Purchases::stripe()->subscriptions()->resume('sub_1', 'resume-1');

    expect($resumed->cancels_at)->toBeNull()
        ->and($resumed->cancel_at_period_end)->toBeFalse()
        ->and(cancelAtTestPosts())->toBe([$expected]);

    Http::assertSentInOrder([
        fn (HttpRequest $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_1',
        fn (HttpRequest $request): bool => $request->method() === 'POST' && $request->hasHeader('Idempotency-Key', 'resume-1'),
    ]);
    // Stripe documents each undo on its own: the two never go in one request.
    Http::assertNotSent(fn (HttpRequest $request): bool => array_key_exists('cancel_at', stripeForm($request))
        && array_key_exists('cancel_at_period_end', stripeForm($request)));
})->with([
    // The documented reactivation — Stripe also reports the period end as `cancel_at` then.
    'at the period end' => [['cancel_at_period_end' => true, 'cancel_at' => 1_798_761_600], ['cancel_at_period_end' => 'false']],
    // Stripe unsets `cancel_at` with an empty value.
    'at a chosen date' => [['cancel_at_period_end' => false, 'cancel_at' => 1_798_761_600], ['cancel_at' => '']],
    // Nothing scheduled: the request it always sent, which changes nothing.
    'nothing scheduled' => [['cancel_at_period_end' => false, 'cancel_at' => null], ['cancel_at_period_end' => 'false']],
]);

it('sends nothing when the subscription cannot be read before a resume', function (): void {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'No such subscription']], 404)]);

    expect(fn () => Purchases::stripe()->subscriptions()->resume('sub_1'))->toThrow(StripeInvalidRequestException::class, 'No such subscription')
        ->and(cancelAtTestPosts())->toBe([]);
});

it('sends the proration behaviour of a cancellation date only when given', function (): void {
    $at = Carbon::parse('2026-11-20 00:00:00', 'UTC');
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription(['cancel_at' => $at->getTimestamp()]))]);

    Purchases::stripe()->subscriptions()->cancelAt('sub_1', $at, ProrationBehavior::None, 'cancel-at-2');
    Purchases::stripe()->subscriptions()->cancelAt('sub_1', CancelAt::MaxPeriodEnd, ProrationBehavior::AlwaysInvoice);
    Purchases::stripe()->subscriptions()->cancelAt('sub_1', CancelAt::MinPeriodEnd);

    expect(cancelAtTestPosts())->toBe([
        ['cancel_at' => (string) $at->getTimestamp(), 'proration_behavior' => 'none'],
        ['cancel_at' => 'max_period_end', 'proration_behavior' => 'always_invoice'],
        ['cancel_at' => 'min_period_end'],
    ]);
    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Idempotency-Key', 'cancel-at-2'));
});

it('syncs cancels_at from webhooks, and clears it when stripe drops the date', function (): void {
    $at = Carbon::parse('2027-02-01 00:00:00', 'UTC');

    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['cancel_at' => $at->getTimestamp()]), Carbon::now()->subMinutes(5));
    expect(Subscription::query()->sole()->cancels_at?->getTimestamp())->toBe($at->getTimestamp());

    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['cancel_at' => null]), Carbon::now()->subMinutes(2));
    expect(Subscription::query()->sole()->cancels_at)->toBeNull();
});

it('leaves cancels_at alone for a provider that does not report it', function (): void {
    $at = Carbon::parse('2027-02-01 00:00:00', 'UTC');
    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['cancel_at' => $at->getTimestamp()]), Carbon::now()->subMinutes(5));
    $stored = Subscription::query()->sole();
    $stored->update(['provider' => 'apple', 'last_event_at' => null]);

    Purchases::sync(FakeResult::subscription('apple', 'sub_1'));

    expect(Subscription::query()->sole()->cancels_at?->getTimestamp())->toBe($at->getTimestamp());
});

it('keeps the date in the audit snapshot, and replays a snapshot written before it was stored', function (): void {
    $at = Carbon::parse('2027-02-01 00:00:00', 'UTC');

    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['cancel_at' => $at->getTimestamp()]), Carbon::now()->subMinutes(5));
    $notification = PurchaseNotification::query()->sole();

    expect($notification->payload->get('cancels_at'))->toBe($at->toIso8601String())
        ->and(NotificationResultFactory::fromNotification($notification)?->cancelsAt()?->getTimestamp())->toBe($at->getTimestamp());

    // An older snapshot has no `cancels_at` key: the raw subscription's `cancel_at` is read instead.
    $legacy = $notification->payload->except('cancels_at');
    $notification->forceFill(['payload' => $legacy])->save();
    Subscription::query()->sole()->update(['cancels_at' => null]);

    expect(NotificationResultFactory::fromNotification($notification->refresh())?->cancelsAt()?->getTimestamp())->toBe($at->getTimestamp());

    Purchases::replay($notification);

    expect(Subscription::query()->sole()->cancels_at?->getTimestamp())->toBe($at->getTimestamp());
});

it('keeps recording subscriptions on a schema without the cancels_at column', function (): void {
    Schema::table('subscriptions', function (Blueprint $table): void {
        $table->dropColumn('cancels_at');
    });
    SubscriptionColumns::flush();

    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['cancel_at' => Carbon::parse('2027-02-01', 'UTC')->getTimestamp(), 'cancel_at_period_end' => true]), Carbon::now()->subMinutes(5));

    $subscription = Subscription::query()->sole();

    expect($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->getAttributes())->not->toHaveKey('cancels_at')
        ->and(SubscriptionColumns::has('cancels_at'))->toBeFalse();
});

it('logs the cancellation date under the fake and runs the same code through every layer', function (): void {
    $at = Carbon::parse('2027-03-01 00:00:00', 'UTC');
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription(['cancel_at' => $at->getTimestamp()]))]);
    $fake = Purchases::fake();

    $facade = Purchases::stripe()->subscriptions()->cancelAt('sub_1', $at);
    $injected = app(PurchasesManager::class)->stripe()->subscriptions()->cancelAt('sub_1', CancelAt::MaxPeriodEnd, ProrationBehavior::None);
    $action = app(ScheduleStripeCancellationAction::class)->execute('sub_1', $at);

    expect([$facade->getKey(), $injected->getKey(), $action->getKey()])->toBe(array_fill(0, 3, Subscription::query()->sole()->getKey()));
    $fake->assertBilled('subscriptions.cancelAt', fn (string $subscription, CancelAt|Carbon $when, ?ProrationBehavior $proration): bool => $when === CancelAt::MaxPeriodEnd && $proration === ProrationBehavior::None);
    expect(fn () => $fake->assertNotBilled('subscriptions.cancelAt'))->toThrow(AssertionFailedError::class);
});
