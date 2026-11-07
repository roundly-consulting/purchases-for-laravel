<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Actions\RecordSubscriptionAction;
use RoundlyConsulting\Purchases\DataTransferObjects\RecordSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\SubscriptionRenewed;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Events\SubscriptionUpdated;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Purchases\Testing\FakeResult;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_sync',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
    // Signatures are checked against now; events carry their own `created`.
    Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00', 'UTC'));
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * The seat item at a quantity, and the metered storage item.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function syncTestSubscription(int $seats, array $overrides = [], string $seatPrice = 'price_seats', string $start = '2026-11-01', string $end = '2026-12-01'): array
{
    return stripeBillingSubscription($overrides + ['items' => ['object' => 'list', 'data' => [
        stripeBillingItem('si_seats', $seatPrice, $seats, 1200, $start, $end),
        stripeBillingItem('si_storage', 'price_storage', null, 5, $start, $end),
    ]]]);
}

it('links a webhook subscription to the owner of its stripe customer', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    Event::fake([SubscriptionStarted::class]);

    stripeWebhook('customer.subscription.created', syncTestSubscription(10), Carbon::now()->subMinute());

    $subscription = Subscription::query()->sole();

    expect($subscription->owner?->is($company))->toBeTrue()
        ->and(Purchases::for($company)->subscriptions()->count())->toBe(1)
        ->and($subscription->itemFor('price_seats')?->quantity)->toBe(10)
        ->and($subscription->itemFor('price_storage')?->quantity)->toBeNull()
        ->and($subscription->items()->count())->toBe(2);

    // The listener sees the owner already linked.
    Event::assertDispatched(SubscriptionStarted::class, fn (SubscriptionStarted $event): bool => $event->subscription->owner?->is($company) === true);
});

it('never replaces an owner already linked, and links nobody for an unknown customer', function (): void {
    $acme = Organization::query()->create(['name' => 'Acme']);
    $other = User::query()->create(['name' => 'Other']);
    BillingCustomer::factory()->ownedBy($acme)->create(['provider_id' => 'cus_1']);

    stripeWebhook('customer.subscription.created', syncTestSubscription(10, ['customer' => 'cus_unknown']), Carbon::now()->subMinutes(3));
    expect(Subscription::query()->sole()->owner)->toBeNull();

    ownedBy($other, Subscription::query()->sole());
    stripeWebhook('customer.subscription.updated', syncTestSubscription(11), Carbon::now()->subMinute());

    expect(Subscription::query()->sole()->owner?->is($other))->toBeTrue();
});

it('links a one-off payment to the owner of its stripe customer', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    stripeInvoicePayments(null);

    stripeWebhook('payment_intent.succeeded', ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 900, 'currency' => 'eur', 'customer' => 'cus_1'], Carbon::now()->subMinute());

    expect(Purchases::for($company)->purchases()->sole()->provider_id)->toBe('pi_1');
});

it('syncs the seat count from customer.subscription.updated and announces it once', function (): void {
    Event::fake([SubscriptionUpdated::class, SubscriptionRenewed::class]);

    stripeWebhook('customer.subscription.created', syncTestSubscription(10), Carbon::now()->subMinutes(5));
    stripeWebhook('customer.subscription.updated', syncTestSubscription(11), Carbon::now()->subMinutes(4));
    // A redelivery of the same event changes nothing and announces nothing.
    stripeWebhook('customer.subscription.updated', syncTestSubscription(11), Carbon::now()->subMinutes(4));

    $subscription = Subscription::query()->sole();

    expect($subscription->itemFor('price_seats')?->quantity)->toBe(11)
        ->and($subscription->itemFor('price_seats')?->getKey())->toBe(SubscriptionItem::query()->where('provider_id', 'si_seats')->sole()->getKey())
        ->and(SubscriptionItem::query()->withTrashed()->count())->toBe(2);

    Event::assertDispatchedTimes(SubscriptionUpdated::class, 1);
    Event::assertNotDispatched(SubscriptionRenewed::class);
});

it('syncs a price swap, its new period and amount', function (): void {
    Event::fake([SubscriptionUpdated::class]);

    stripeWebhook('customer.subscription.created', syncTestSubscription(10), Carbon::now()->subMinutes(5));
    stripeWebhook('customer.subscription.updated', syncTestSubscription(10, [], 'price_seats_annual', '2026-11-07', '2027-11-07'), Carbon::now()->subMinutes(4));

    $subscription = Subscription::query()->sole();

    expect($subscription->itemFor('price_seats'))->toBeNull()
        ->and($subscription->itemFor('price_seats_annual')?->quantity)->toBe(10)
        ->and($subscription->active_from?->toDateString())->toBe('2026-11-07')
        ->and($subscription->ends_at?->toDateString())->toBe('2027-11-07');

    Event::assertDispatched(SubscriptionUpdated::class, fn (SubscriptionUpdated $event): bool => $event->subscription->is($subscription)
        && $event->result->provider() === 'stripe');
});

it('syncs status, period and cancel_at_period_end without announcing a billing change', function (): void {
    Event::fake([SubscriptionUpdated::class]);

    stripeWebhook('customer.subscription.created', syncTestSubscription(10, ['status' => 'trialing']), Carbon::now()->subMinutes(5));
    stripeWebhook('customer.subscription.updated', syncTestSubscription(10, ['status' => 'active', 'cancel_at_period_end' => true], start: '2026-12-01', end: '2027-01-01'), Carbon::now()->subMinutes(4));

    $subscription = Subscription::query()->sole();

    expect($subscription->status)->toBe(Status::Completed)
        ->and($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->active_from?->toDateString())->toBe('2026-12-01')
        ->and($subscription->ends_at?->toDateString())->toBe('2027-01-01');

    stripeWebhook('customer.subscription.updated', syncTestSubscription(10, ['cancel_at_period_end' => false]), Carbon::now()->subMinutes(3));

    expect(Subscription::query()->sole()->cancel_at_period_end)->toBeFalse();
    Event::assertNotDispatched(SubscriptionUpdated::class);
});

it('syncs the subscription a paid renewal invoice belongs to', function (): void {
    Event::fake([SubscriptionRenewed::class]);
    stripeWebhook('customer.subscription.created', syncTestSubscription(10), Carbon::now()->subMinutes(5));

    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(
        syncTestSubscription(10, [], start: '2026-12-01', end: '2027-01-01'),
        200,
        ['Date' => Carbon::now()->toRfc7231String()],
    )]);

    stripeWebhook('invoice.paid', [
        'id' => 'in_renewal', 'object' => 'invoice', 'status' => 'paid', 'amount_paid' => 12000, 'currency' => 'eur',
        'billing_reason' => 'subscription_cycle', 'customer' => 'cus_1',
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
    ], Carbon::now()->subMinutes(2));

    $subscription = Subscription::query()->sole();

    expect($subscription->ends_at?->toDateString())->toBe('2027-01-01')
        // Ordered at Stripe's answer time, not the (older) event time.
        ->and($subscription->last_event_at?->getTimestamp())->toBe(Carbon::now()->getTimestamp());
    Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
});

it('moves a subscription into its grace period when its invoice payment fails', function (): void {
    stripeWebhook('customer.subscription.created', syncTestSubscription(10), Carbon::now()->subMinutes(5));
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(syncTestSubscription(10, ['status' => 'past_due']))]);

    stripeWebhook('invoice.payment_failed', [
        'id' => 'in_failed', 'object' => 'invoice', 'status' => 'open', 'amount_due' => 12000, 'currency' => 'eur',
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
    ], Carbon::now()->subMinutes(2));

    expect(Subscription::query()->sole()->status)->toBe(Status::InGracePeriod);
});

it('ignores a webhook older than the state a billing call already recorded', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    stripeWebhook('customer.subscription.created', syncTestSubscription(10), Carbon::now()->subMinutes(10));

    // The billing call answers at Stripe's clock: now.
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(syncTestSubscription(11), 200, ['Date' => Carbon::now()->toRfc7231String()])]);
    Purchases::stripe()->subscriptions()->updateQuantity('si_seats', 11);

    // An update Stripe stamped before that answer arrives late, carrying the old count.
    stripeWebhook('customer.subscription.updated', syncTestSubscription(10), Carbon::now()->subMinutes(5));
    expect(Subscription::query()->sole()->itemFor('price_seats')?->quantity)->toBe(11);

    // A newer one applies.
    stripeWebhook('customer.subscription.updated', syncTestSubscription(12), Carbon::now()->addSecond());
    expect(Subscription::query()->sole()->itemFor('price_seats')?->quantity)->toBe(12);
});

it('replays an audited webhook with its items and cancellation intact', function (): void {
    stripeWebhook('customer.subscription.created', syncTestSubscription(10, ['cancel_at_period_end' => true]), Carbon::now()->subMinutes(5));

    $audit = PurchaseNotification::query()->sole();
    Subscription::query()->sole()->items()->forceDelete();
    Subscription::query()->sole()->update(['cancel_at_period_end' => false]);

    Purchases::replay($audit);

    $subscription = Subscription::query()->sole();

    expect($audit->payload->get('customer_id'))->toBe('cus_1')
        ->and($audit->payload->get('items'))->toHaveCount(2)
        ->and($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->itemFor('price_seats')?->quantity)->toBe(10)
        ->and($subscription->itemFor('price_storage')?->quantity)->toBeNull()
        ->and($subscription->itemFor('price_storage')?->price?->minor())->toBe('5');
});

it('fills the items of a subscription stored without any, without announcing a change', function (): void {
    Event::fake([SubscriptionUpdated::class]);
    Subscription::factory()->create(['provider' => 'stripe', 'provider_id' => 'sub_1', 'last_event_at' => null]);

    stripeWebhook('customer.subscription.updated', syncTestSubscription(10), Carbon::now()->subMinute());

    expect(Subscription::query()->sole()->items()->count())->toBe(2);
    Event::assertNotDispatched(SubscriptionUpdated::class);
});

it('keeps the items of a host-built result in step, keyed by provider id', function (): void {
    Event::fake([SubscriptionUpdated::class]);
    $action = app(RecordSubscriptionAction::class);
    $data = fn (array $items, int $minute): RecordSubscriptionData => new RecordSubscriptionData(
        provider: 'google', providerId: 'GPA.1', status: Status::Completed, name: 'pro', items: $items,
        occurredAt: Carbon::now()->addMinutes($minute),
    );

    $action->execute($data([new ResultItem('Pro', 'pro.monthly', Money::ofMinor(999, 'USD')), new ResultItem('Extra', null)], 1));
    $first = SubscriptionItem::query()->where('provider_id', 'pro.monthly')->sole()->getKey();

    $recorded = $action->record($data([new ResultItem('Pro', 'pro.monthly', Money::ofMinor(1299, 'USD'))], 2));

    expect($recorded->itemsChanged)->toBeTrue()
        ->and($recorded->subscription->items()->pluck('id')->all())->toBe([$first])
        ->and($recorded->subscription->items()->sole()->price?->minor())->toBe('1299')
        ->and($recorded->subscription->items()->sole()->quantity)->toBe(1);

    // The same items again: nothing changed.
    expect($action->record($data([new ResultItem('Pro', 'pro.monthly', Money::ofMinor(1299, 'USD'))], 3))->itemsChanged)->toBeFalse();
});

it('leaves apple and google subscriptions as they were', function (): void {
    Event::fake([SubscriptionUpdated::class]);

    $subscription = Purchases::sync(FakeResult::subscription('apple', 'orig_1'));
    Purchases::sync(FakeResult::subscription('apple', 'orig_1', occurredAt: Carbon::now()->addMinute()));

    expect($subscription)->toBeInstanceOf(Subscription::class)
        ->and(Subscription::query()->sole()->cancel_at_period_end)->toBeFalse()
        ->and(Subscription::query()->sole()->items()->count())->toBe(0)
        ->and(Subscription::query()->sole()->owner)->toBeNull();
    Event::assertNotDispatched(SubscriptionUpdated::class);
});

it('orders a billing answer without a readable date at the time it is recorded', function (string $date): void {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(syncTestSubscription(11), 200, ['Date' => $date])]);
    stripeWebhook('customer.subscription.created', syncTestSubscription(10), Carbon::now()->subMinutes(5));

    Purchases::stripe()->subscriptions()->updateQuantity('si_seats', 11);

    expect(Subscription::query()->sole()->itemFor('price_seats')?->quantity)->toBe(11)
        // No answer time: the row keeps the last event time it had.
        ->and(Subscription::query()->sole()->last_event_at?->getTimestamp())->toBe(Carbon::now()->subMinutes(5)->getTimestamp());
})->with(['not a date' => ['yesterday-ish?'], 'empty' => ['']]);

it('falls back to the event time when the fetched subscription has no readable date', function (): void {
    stripeWebhook('customer.subscription.created', syncTestSubscription(10), Carbon::now()->subMinutes(5));
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(syncTestSubscription(10, ['status' => 'past_due']), 200, ['Date' => 'garbage'])]);

    stripeWebhook('invoice.payment_failed', [
        'id' => 'in_failed', 'object' => 'invoice', 'status' => 'open', 'amount_due' => 12000, 'currency' => 'eur',
        'subscription' => 'sub_1',
    ], Carbon::now()->subMinutes(2));

    expect(Subscription::query()->sole()->status)->toBe(Status::InGracePeriod)
        ->and(Subscription::query()->sole()->last_event_at?->getTimestamp())->toBe(Carbon::now()->subMinutes(2)->getTimestamp());
});
