<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Signal;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\PaymentActionRequired;
use RoundlyConsulting\Purchases\Events\SubscriptionInGracePeriod;
use RoundlyConsulting\Purchases\Events\SubscriptionOnHold;
use RoundlyConsulting\Purchases\Events\SubscriptionRenewed;
use RoundlyConsulting\Purchases\Events\SubscriptionResumed;
use RoundlyConsulting\Purchases\Events\SubscriptionTrialWillEnd;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_lifecycle',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00', 'UTC'));
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A Stripe invoice that needs the customer's action.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lifecycleInvoice(array $overrides = []): array
{
    return $overrides + [
        'id' => 'in_1',
        'object' => 'invoice',
        'customer' => 'cus_1',
        'currency' => 'eur',
        'amount_due' => 1200,
        'status' => 'open',
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/in_1',
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
    ];
}

it('announces a subscription Stripe pauses, and its resumption', function (): void {
    Event::fake([SubscriptionOnHold::class, SubscriptionResumed::class, SubscriptionRenewed::class]);
    stripeWebhook('customer.subscription.created', stripeBillingSubscription(), Carbon::now()->subMinutes(3));

    stripeWebhook('customer.subscription.paused', stripeBillingSubscription(['status' => 'paused']), Carbon::now()->subMinutes(2));

    expect(Subscription::query()->sole()->status)->toBe(Status::OnHold);
    Event::assertDispatchedTimes(SubscriptionOnHold::class, 1);
    Event::assertNotDispatched(SubscriptionResumed::class);

    stripeWebhook('customer.subscription.resumed', stripeBillingSubscription(), Carbon::now()->subMinute());

    expect(Subscription::query()->sole()->status)->toBe(Status::Completed);
    Event::assertDispatchedTimes(SubscriptionResumed::class, 1);
    // A held subscription coming back was always a renewal: that stays.
    Event::assertDispatchedTimes(SubscriptionRenewed::class, 1);
});

it('puts an unpaid subscription on hold once, however often it is delivered', function (): void {
    Event::fake([SubscriptionOnHold::class]);
    stripeWebhook('customer.subscription.created', stripeBillingSubscription(), Carbon::now()->subMinutes(3));

    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['status' => 'unpaid']), Carbon::now()->subMinutes(2));
    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['status' => 'unpaid']), Carbon::now()->subMinute());

    Event::assertDispatchedTimes(SubscriptionOnHold::class, 1);
});

it('keeps a past-due subscription in grace by default', function (): void {
    Event::fake([SubscriptionOnHold::class, SubscriptionInGracePeriod::class]);

    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['status' => 'past_due']), Carbon::now()->subMinute());

    expect(Subscription::query()->sole()->status)->toBe(Status::InGracePeriod)
        ->and(Subscription::query()->sole()->isActive())->toBeTrue();
    Event::assertDispatched(SubscriptionInGracePeriod::class);
    Event::assertNotDispatched(SubscriptionOnHold::class);
});

it('puts a past-due subscription on hold when the host says so', function (): void {
    config()->set('purchases.settings.stripe.past_due', 'on_hold');
    Event::fake([SubscriptionOnHold::class, SubscriptionInGracePeriod::class]);

    stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['status' => 'past_due']), Carbon::now()->subMinute());

    expect(Subscription::query()->sole()->status)->toBe(Status::OnHold)
        ->and(Subscription::query()->sole()->isActive())->toBeFalse();
    Event::assertDispatched(SubscriptionOnHold::class);
    Event::assertNotDispatched(SubscriptionInGracePeriod::class);
});

it('refuses a past-due setting it does not know', function (): void {
    config()->set('purchases.settings.stripe.past_due', 'locked');

    expect(fn () => stripeWebhook('customer.subscription.updated', stripeBillingSubscription(['status' => 'past_due']), Carbon::now()->subMinute()))
        ->toThrow(InvalidConfigurationException::class, 'purchases.settings.stripe.past_due');
});

it('announces a trial that ends soon and keeps the subscription up to date', function (): void {
    Event::fake([SubscriptionTrialWillEnd::class]);
    $trialEnd = Carbon::now()->addDays(3);

    stripeWebhook('customer.subscription.trial_will_end', stripeBillingSubscription([
        'status' => 'trialing',
        'trial_end' => $trialEnd->getTimestamp(),
    ]), Carbon::now()->subMinute());

    $subscription = Subscription::query()->sole();

    expect($subscription->onTrial())->toBeTrue()
        ->and($subscription->trial_ends_at?->getTimestamp())->toBe($trialEnd->getTimestamp());
    Event::assertDispatched(
        SubscriptionTrialWillEnd::class,
        fn (SubscriptionTrialWillEnd $event): bool => $event->subscription->is($subscription)
            && $event->result instanceof GenericResult
            && $event->result->signal() === Signal::TrialWillEnd
            && $event->result->eventId() === 'evt_customer.subscription.trial_will_end_'.Carbon::now()->subMinute()->getTimestamp(),
    );
});

it('announces nothing for a trial ending on a subscription the host deleted', function (): void {
    Event::fake([SubscriptionTrialWillEnd::class]);
    stripeWebhook('customer.subscription.created', stripeBillingSubscription(['status' => 'trialing']), Carbon::now()->subMinutes(2));
    Subscription::query()->sole()->delete();

    stripeWebhook('customer.subscription.trial_will_end', stripeBillingSubscription(['status' => 'trialing']), Carbon::now()->subMinute());

    Event::assertNotDispatched(SubscriptionTrialWillEnd::class);
});

it('announces a subscription payment that waits for the customer, without recording anything', function (): void {
    Event::fake([PaymentActionRequired::class]);
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    stripeWebhook('customer.subscription.created', stripeBillingSubscription(), Carbon::now()->subMinutes(2));
    $subscription = Subscription::query()->sole();

    $returned = stripeWebhook('invoice.payment_action_required', lifecycleInvoice(), Carbon::now()->subMinute());

    expect($returned)->toBeInstanceOf(PurchaseNotification::class)
        ->and($returned->type)->toBe(ResultType::Notification->value)
        ->and(Subscription::query()->sole()->status)->toBe(Status::Completed);
    Event::assertDispatched(PaymentActionRequired::class, fn (PaymentActionRequired $event): bool => $event->subscription?->is($subscription) === true
        && $event->owner?->is($company) === true
        && $event->actionUrl === 'https://invoice.stripe.com/i/in_1'
        && $event->result->providerId() === 'in_1'
        && $event->result->status() === Status::Pending
        && $event->result->price()?->minor() === '1200');
});

it('announces a one-off invoice payment that waits, owned through its billing customer', function (): void {
    Event::fake([PaymentActionRequired::class]);
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    stripeWebhook('invoice.payment_action_required', lifecycleInvoice(['parent' => null, 'hosted_invoice_url' => null]), Carbon::now()->subMinute());

    Event::assertDispatched(PaymentActionRequired::class, fn (PaymentActionRequired $event): bool => $event->subscription === null
        && $event->owner?->is($company) === true
        && $event->actionUrl === null
        && $event->result instanceof GenericResult
        && $event->result->subscriptionId() === null);
});

it('reads the subscription of an invoice in the pre-2025-03-31 shape', function (): void {
    Event::fake([PaymentActionRequired::class]);
    stripeWebhook('customer.subscription.created', stripeBillingSubscription(), Carbon::now()->subMinutes(2));

    stripeWebhook('invoice.payment_action_required', lifecycleInvoice(['parent' => null, 'subscription' => ['id' => 'sub_1']]), Carbon::now()->subMinute());

    Event::assertDispatched(PaymentActionRequired::class, fn (PaymentActionRequired $event): bool => $event->subscription?->provider_id === 'sub_1'
        && $event->owner === null);
});

it('announces a signal of a stored notification again only when it was never processed', function (): void {
    stripeWebhook('invoice.payment_action_required', lifecycleInvoice(), Carbon::now()->subMinute());
    $notification = PurchaseNotification::query()->sole();
    Event::fake([PaymentActionRequired::class]);

    Purchases::replay($notification);
    Event::assertNotDispatched(PaymentActionRequired::class);

    $notification->update(['processed_at' => null]);
    Purchases::replay($notification->refresh());
    Event::assertDispatchedTimes(PaymentActionRequired::class, 1);
});

it('keeps the event id, signal and subscription of a result in its audit snapshot', function (): void {
    stripeWebhook('invoice.payment_action_required', lifecycleInvoice(), Carbon::now()->subMinute());

    $notification = PurchaseNotification::query()->sole();
    $rebuilt = NotificationResultFactory::fromNotification($notification);

    expect($notification->eventId())->toBe('evt_invoice.payment_action_required_'.Carbon::now()->subMinute()->getTimestamp())
        ->and($rebuilt?->eventId())->toBe($notification->eventId())
        ->and($rebuilt?->signal())->toBe(Signal::PaymentActionRequired)
        ->and($rebuilt?->subscriptionId())->toBe('sub_1');
});

it('names the stripe event on every result it maps', function (string $type, array $object): void {
    $created = Carbon::now()->subMinute()->getTimestamp();
    $result = Purchases::result('stripe', stripeSignedRequest([
        'id' => 'evt_named',
        'type' => $type,
        'created' => $created,
        'data' => ['object' => $object],
    ]));

    expect($result)->toBeInstanceOf(GenericResult::class)
        ->and($result instanceof GenericResult ? $result->eventId() : null)->toBe('evt_named');
})->with([
    'unknown' => ['customer.created', ['id' => 'cus_1', 'object' => 'customer']],
    'subscription' => ['customer.subscription.updated', stripeBillingSubscription()],
    'purchase' => ['payment_intent.succeeded', ['id' => 'pi_1', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'eur', 'invoice' => null]],
    'refund' => ['charge.refunded', ['id' => 'ch_1', 'payment_intent' => 'pi_1', 'amount_refunded' => 100, 'currency' => 'eur', 'refunded' => true]],
    'dispute inquiry' => ['charge.dispute.created', ['id' => 'dp_1', 'payment_intent' => 'pi_1', 'amount' => 100, 'currency' => 'eur', 'status' => 'warning_needs_response']],
    'dispute won' => ['charge.dispute.closed', ['id' => 'dp_1', 'payment_intent' => 'pi_1', 'amount' => 100, 'currency' => 'eur', 'status' => 'won']],
    'subscription checkout' => ['checkout.session.completed', ['id' => 'cs_1', 'mode' => 'subscription', 'payment_status' => 'paid']],
]);

it('names the event on a subscription read for its invoice', function (): void {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription())]);

    $result = Purchases::result('stripe', stripeSignedRequest([
        'id' => 'evt_paid',
        'type' => 'invoice.paid',
        'created' => Carbon::now()->subMinute()->getTimestamp(),
        'data' => ['object' => lifecycleInvoice(['status' => 'paid'])],
    ]));

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result instanceof GenericResult ? $result->eventId() : null)->toBe('evt_paid');
});
