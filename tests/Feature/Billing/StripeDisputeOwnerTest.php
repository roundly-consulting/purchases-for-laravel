<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Events\ChargebackReceived;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_disputes',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00', 'UTC'));
    Http::preventStrayRequests();
    Event::fake([ChargebackReceived::class]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A dispute of a subscription invoice's charge: it names the charge and the PaymentIntent,
 * never a customer.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function disputeOwnerTestDispute(array $overrides = []): array
{
    return $overrides + ['id' => 'dp_1', 'object' => 'dispute', 'charge' => 'ch_1', 'payment_intent' => 'pi_invoice', 'amount' => 3600, 'currency' => 'eur', 'reason' => 'fraudulent', 'status' => 'needs_response'];
}

function disputeOwnerTestCompany(): Organization
{
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    return $company;
}

function disputeOwnerTestCharge(): void
{
    Http::fake(['api.stripe.com/v1/charges/ch_1' => Http::response(['id' => 'ch_1', 'object' => 'charge', 'customer' => 'cus_1'])]);
}

it('names the owner of a subscription invoice dispute, read from its charge', function (): void {
    $company = disputeOwnerTestCompany();
    disputeOwnerTestCharge();

    $refund = stripeWebhook('charge.dispute.created', disputeOwnerTestDispute(), Carbon::now()->subMinute());

    expect($refund)->toBeInstanceOf(PurchaseRefund::class)
        ->and(PurchaseNotification::query()->sole()->payload->get('customer_id'))->toBe('cus_1');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/charges/ch_1');
    Event::assertDispatched(ChargebackReceived::class, fn (ChargebackReceived $event): bool => $event->owner?->is($company) === true
        && $event->result instanceof GenericResult
        && $event->result->customerId() === 'cus_1');
});

it('exposes the customer on every dispute result, informational ones included', function (string $type, string $status): void {
    disputeOwnerTestCharge();

    $result = Purchases::result('stripe', stripeSignedRequest([
        'id' => 'evt_'.$status, 'type' => $type, 'created' => Carbon::now()->getTimestamp(),
        'data' => ['object' => disputeOwnerTestDispute(['status' => $status])],
    ]));

    expect($result instanceof GenericResult ? $result->customerId() : null)->toBe('cus_1');
})->with([
    'opened' => ['charge.dispute.created', 'needs_response'],
    'inquiry' => ['charge.dispute.created', 'warning_needs_response'],
    'updated' => ['charge.dispute.updated', 'under_review'],
    'won' => ['charge.dispute.closed', 'won'],
    'lost' => ['charge.dispute.closed', 'lost'],
]);

it('reads the customer from the payload when it is there, without asking stripe', function (array $dispute): void {
    $company = disputeOwnerTestCompany();
    Http::fake();

    stripeWebhook('charge.dispute.created', disputeOwnerTestDispute($dispute), Carbon::now()->subMinute());

    Http::assertNothingSent();
    Event::assertDispatched(ChargebackReceived::class, fn (ChargebackReceived $event): bool => $event->owner?->is($company) === true);
})->with([
    'customer' => [['customer' => 'cus_1']],
    'expanded customer' => [['customer' => ['id' => 'cus_1']]],
    'expanded charge' => [['charge' => ['id' => 'ch_1', 'customer' => 'cus_1']]],
]);

it('names no owner when no customer can be found, and asks nothing without a secret key', function (?string $secret, array $dispute): void {
    disputeOwnerTestCompany();
    config()->set('purchases.settings.stripe.secret', $secret);
    Http::fake();

    stripeWebhook('charge.dispute.created', disputeOwnerTestDispute($dispute), Carbon::now()->subMinute());

    Http::assertNothingSent();
    Event::assertDispatched(ChargebackReceived::class, fn (ChargebackReceived $event): bool => $event->owner === null
        && $event->result instanceof GenericResult
        && $event->result->customerId() === null);
})->with([
    'no secret' => [null, []],
    'no charge' => ['sk_test_disputes', ['charge' => null]],
    'expanded charge without a customer' => ['sk_test_disputes', ['charge' => ['id' => 'ch_1', 'customer' => null]]],
]);

it('names no owner for a charge of an unknown or absent customer', function (?string $customer): void {
    disputeOwnerTestCompany();
    Http::fake(['api.stripe.com/v1/charges/ch_1' => Http::response(['id' => 'ch_1', 'customer' => $customer])]);

    stripeWebhook('charge.dispute.created', disputeOwnerTestDispute(), Carbon::now()->subMinute());

    Event::assertDispatched(ChargebackReceived::class, fn (ChargebackReceived $event): bool => $event->owner === null);
})->with(['unknown customer' => 'cus_unknown', 'guest charge' => null]);

it('falls back to the owner of the disputed purchase', function (): void {
    $user = User::query()->create(['name' => 'Buyer']);
    Purchase::factory()->create(['provider' => 'stripe', 'provider_id' => 'pi_once', 'transaction_id' => 'pi_once', 'owner_type' => $user->getMorphClass(), 'owner_id' => $user->getKey()]);
    Http::fake(['api.stripe.com/v1/charges/ch_1' => Http::response(['id' => 'ch_1', 'customer' => null])]);

    stripeWebhook('charge.dispute.created', disputeOwnerTestDispute(['payment_intent' => 'pi_once']), Carbon::now()->subMinute());

    Event::assertDispatched(ChargebackReceived::class, fn (ChargebackReceived $event): bool => $event->owner?->is($user) === true);
});

it('fails the webhook when the charge lookup fails, so stripe delivers it again', function (): void {
    config()->set('purchases.audit.deduplicate', true);
    $company = disputeOwnerTestCompany();
    Http::fake(['api.stripe.com/v1/charges/ch_1' => Http::sequence()
        ->push(['error' => ['type' => 'api_error', 'message' => 'Something went wrong.']], 500)
        ->pushFailedConnection()
        ->push(['id' => 'ch_1', 'customer' => 'cus_1']),
    ]);

    $deliver = fn () => Purchases::handle('stripe', stripeSignedRequest([
        'id' => 'evt_dispute_retry', 'type' => 'charge.dispute.created', 'created' => Carbon::now()->getTimestamp(),
        'data' => ['object' => disputeOwnerTestDispute()],
    ]));

    expect($deliver)->toThrow(RequestException::class)
        ->and($deliver)->toThrow(ConnectionException::class)
        // Nothing was recorded or claimed, so the redelivery goes through.
        ->and(PurchaseRefund::query()->count())->toBe(0)
        ->and($deliver())->toBeInstanceOf(PurchaseRefund::class);

    Event::assertDispatchedTimes(ChargebackReceived::class, 1);
    Event::assertDispatched(ChargebackReceived::class, fn (ChargebackReceived $event): bool => $event->owner?->is($company) === true);
});

it('keeps the customer of a refunded charge on its result', function (): void {
    Http::fake();

    $result = Purchases::result('stripe', stripeSignedRequest([
        'id' => 'evt_refund', 'type' => 'charge.refunded', 'created' => Carbon::now()->getTimestamp(),
        'data' => ['object' => ['id' => 'ch_1', 'object' => 'charge', 'customer' => 'cus_1', 'payment_intent' => 'pi_1', 'amount_refunded' => 100, 'currency' => 'eur', 'refunded' => false]],
    ]));

    expect($result instanceof GenericResult ? $result->customerId() : null)->toBe('cus_1');
    Http::assertNothingSent();
});

it('keeps the dispute\'s customer in its audit snapshot', function (): void {
    $company = disputeOwnerTestCompany();
    disputeOwnerTestCharge();

    stripeWebhook('charge.dispute.created', disputeOwnerTestDispute(), Carbon::now()->subMinute());
    $notification = PurchaseNotification::query()->sole();

    expect(NotificationResultFactory::fromNotification($notification)?->customerId())->toBe('cus_1');
});
