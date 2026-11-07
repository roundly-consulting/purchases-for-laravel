<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\AddressData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CustomerData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\ItemChange;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionChange;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionPrice;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\TaxIdData;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Events\SubscriptionUpdated;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\ProrationBehavior;
use RoundlyConsulting\Purchases\Tests\Fixtures\Company;

/**
 * The B2B journey end to end, for a ULID-keyed company: a Stripe customer with a VAT id, a
 * 7-day-trial per-seat subscription (10 seats) with metered storage, 11 seats with
 * prorations, an invoice preview, a usage report and a portal session — and the webhooks
 * that follow, reflected locally.
 */
it('bills a ulid company from customer to portal, in step with the webhooks', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00', 'UTC'));
    Event::fake([SubscriptionStarted::class, SubscriptionUpdated::class]);
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_journey', 'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia', 'base_url' => 'https://api.stripe.com/v1', 'tolerance' => 300,
    ]);
    Http::preventStrayRequests();

    $trialEnd = Carbon::parse('2026-11-14 09:00:00', 'UTC')->getTimestamp();
    $trialing = fn (int $seats): array => stripeBillingSubscription([
        'status' => 'trialing',
        'trial_end' => $trialEnd,
        'items' => ['object' => 'list', 'data' => [
            stripeBillingItem('si_seats', 'price_seats', $seats, 1200, '2026-11-07 09:00', '2026-11-14 09:00'),
            stripeBillingItem('si_storage', 'price_storage', null, 5, '2026-11-07 09:00', '2026-11-14 09:00'),
        ]],
    ]);

    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1', 'object' => 'customer', 'name' => 'Acme s.r.o.',
            'tax_ids' => ['data' => [['id' => 'txi_1', 'type' => 'eu_vat', 'value' => 'SK2020123456']]]]),
        'api.stripe.com/v1/subscriptions' => Http::response($trialing(10), 200, ['Date' => 'Sat, 07 Nov 2026 09:00:00 GMT']),
        'api.stripe.com/v1/subscriptions/sub_1' => Http::response($trialing(11), 200, ['Date' => 'Sat, 07 Nov 2026 09:05:00 GMT']),
        'api.stripe.com/v1/invoices/create_preview' => Http::response(['currency' => 'eur', 'amount_due' => 13200, 'total' => 13200, 'subtotal' => 13200,
            'lines' => ['data' => [['amount' => 13200, 'currency' => 'eur', 'quantity' => 11, 'pricing' => ['price_details' => ['price' => 'price_seats']]]]]]),
        'api.stripe.com/v1/billing/meter_events' => Http::response(['object' => 'billing.meter_event', 'event_name' => 'storage_gb_days', 'identifier' => 'storage-1']),
        'api.stripe.com/v1/billing_portal/sessions' => Http::response(['id' => 'bps_1', 'url' => 'https://billing.stripe.com/p/session/test_1']),
    ]);

    $company = Company::query()->create(['name' => 'Acme']);

    // 1. One Stripe customer per company.
    $customer = Purchases::stripe()->customers()->sync($company, new CustomerData(
        name: 'Acme s.r.o.',
        email: 'billing@acme.test',
        address: new AddressData(country: 'SK', postalCode: '81101'),
        taxIds: [new TaxIdData('eu_vat', 'SK2020123456')],
    ));

    expect($customer->id)->toBe('cus_1')
        ->and(BillingCustomer::query()->sole()->owner_id)->toBe($company->getKey())
        ->and(Schema::getColumnType('billing_customers', 'owner_id'))->not->toBe('integer');

    // 2. A 7-day-trial subscription: 10 seats plus metered storage.
    $subscription = Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData(
        prices: [SubscriptionPrice::licensed('price_seats', 10), SubscriptionPrice::metered('price_storage')],
        name: 'team',
        trialDays: 7,
        automaticTax: true,
    ));

    expect($subscription->owner?->is($company))->toBeTrue()
        ->and($subscription->onTrial())->toBeTrue()
        ->and($company->subscribedTo('team'))->toBeTrue()
        ->and($subscription->itemFor('price_seats')?->quantity)->toBe(10);
    Event::assertDispatchedTimes(SubscriptionStarted::class, 1);

    // 3. Preview the 11th seat, then add it with prorations.
    $change = new SubscriptionChange([ItemChange::quantity($subscription->itemFor('price_seats') ?? 'si_seats', 11)], ProrationBehavior::CreateProrations);
    $preview = Purchases::stripe()->subscriptions()->preview($subscription, $change);

    expect($preview->amountDue?->toDecimal())->toBe('132.00');

    $subscription = Purchases::stripe()->subscriptions()->updateQuantity($subscription->itemFor('price_seats') ?? 'si_seats', 11, ProrationBehavior::CreateProrations);

    expect($subscription->itemFor('price_seats')?->quantity)->toBe(11);
    Event::assertDispatchedTimes(SubscriptionUpdated::class, 1);

    // 4. Report a day of storage.
    Purchases::stripe()->meters()->report($company, 'storage_gb_days', 12.5, identifier: 'storage-1');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/billing/meter_events'
        && stripeForm($request)['payload'] === ['stripe_customer_id' => 'cus_1', 'value' => '12.5']
        && $request->hasHeader('Idempotency-Key', 'storage-1'));

    // 5. A portal session.
    expect(Purchases::stripe()->portal($company, 'https://app.test/billing')->url)->toBe('https://billing.stripe.com/p/session/test_1');

    // 6. The webhooks that follow: the late `created` and the quantity update are older than
    // what the calls recorded and change nothing; the trial ending and its first invoice do.
    stripeWebhook('customer.subscription.created', $trialing(10), Carbon::parse('2026-11-07 09:00:00', 'UTC'));
    stripeWebhook('customer.subscription.updated', $trialing(11), Carbon::parse('2026-11-07 09:04:59', 'UTC'));

    expect(Subscription::query()->sole()->itemFor('price_seats')?->quantity)->toBe(11);

    Carbon::setTestNow(Carbon::parse('2026-11-14 09:00:10', 'UTC'));
    $active = stripeBillingSubscription(['items' => ['object' => 'list', 'data' => [
        stripeBillingItem('si_seats', 'price_seats', 11, 1200, '2026-11-14 09:00', '2026-12-14 09:00'),
        stripeBillingItem('si_storage', 'price_storage', null, 5, '2026-11-14 09:00', '2026-12-14 09:00'),
    ]], 'trial_end' => $trialEnd]);

    stripeWebhook('customer.subscription.updated', $active, Carbon::now());

    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response($active, 200, ['Date' => 'Sat, 14 Nov 2026 09:00:12 GMT'])]);
    stripeWebhook('invoice.paid', [
        'id' => 'in_1', 'object' => 'invoice', 'status' => 'paid', 'amount_paid' => 13200, 'currency' => 'eur', 'customer' => 'cus_1',
        'billing_reason' => 'subscription_cycle', 'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
    ], Carbon::now());

    $local = Subscription::query()->sole();

    expect($local->status)->toBe(Status::Completed)
        ->and($local->onTrial())->toBeFalse()
        ->and($local->active_from?->toDateString())->toBe('2026-11-14')
        ->and($local->ends_at?->toDateString())->toBe('2026-12-14')
        ->and($local->cancel_at_period_end)->toBeFalse()
        ->and($local->itemFor('price_seats')?->quantity)->toBe(11)
        ->and($local->itemFor('price_storage')?->quantity)->toBeNull()
        ->and($local->owner?->is($company))->toBeTrue()
        ->and($local->owner_id)->toBe($company->getKey())
        ->and($company->subscribedTo('team'))->toBeTrue();

});
