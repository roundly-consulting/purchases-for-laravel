<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CheckoutSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\ItemChange;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionChange;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionPrice;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\SubscriptionCanceled;
use RoundlyConsulting\Purchases\Events\SubscriptionStarted;
use RoundlyConsulting\Purchases\Events\SubscriptionUpdated;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\CollectionMethod;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentBehavior;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\ProrationBehavior;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_subscriptions');
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

/**
 * A company with a Stripe customer and the stored subscription `sub_1` (seats ×10 + storage).
 */
function subscriptionsTestCompany(): Organization
{
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription())]);
    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([
        SubscriptionPrice::licensed('price_seats', 10),
        SubscriptionPrice::metered('price_storage'),
    ], name: 'team'));

    return $company;
}

/**
 * The query string of a GET or DELETE.
 *
 * @return array<array-key, mixed>
 */
function subscriptionsTestQuery(HttpRequest $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

it('starts a per-seat subscription with a trial and a metered price, and records it for the owner', function (): void {
    Event::fake([SubscriptionStarted::class]);
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00', 'UTC'));
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    $trialEnd = Carbon::parse('2026-11-14', 'UTC');

    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription([
        'status' => 'trialing',
        'trial_end' => $trialEnd->getTimestamp(),
    ]))]);

    $subscription = Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData(
        prices: [SubscriptionPrice::licensed('price_seats', 10), SubscriptionPrice::metered('price_storage')],
        name: 'team',
        trialDays: 7,
        automaticTax: true,
        collectionMethod: CollectionMethod::ChargeAutomatically,
        paymentBehavior: PaymentBehavior::DefaultIncomplete,
        metadata: ['company' => 'acme'],
    ), idempotencyKey: 'start-acme');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.stripe.com/v1/subscriptions'
        && $request->hasHeader('Idempotency-Key', 'start-acme')
        && stripeForm($request)['customer'] === 'cus_1'
        && stripeForm($request)['items'] === [['price' => 'price_seats', 'quantity' => '10'], ['price' => 'price_storage']]
        && stripeForm($request)['trial_period_days'] === '7'
        && stripeForm($request)['automatic_tax'] === ['enabled' => 'true']
        && stripeForm($request)['collection_method'] === 'charge_automatically'
        && stripeForm($request)['payment_behavior'] === 'default_incomplete'
        && stripeForm($request)['metadata'] === ['company' => 'acme']
        && ! isset(stripeForm($request)['days_until_due']));

    $seats = $subscription->itemFor('price_seats');
    $storage = $subscription->itemFor('price_storage');

    expect($subscription->provider)->toBe('stripe')
        ->and($subscription->provider_id)->toBe('sub_1')
        ->and($subscription->name)->toBe('team')
        ->and($subscription->status)->toBe(Status::Completed)
        ->and($subscription->owner?->is($company))->toBeTrue()
        ->and($subscription->onTrial())->toBeTrue()
        ->and($subscription->trial_ends_at?->getTimestamp())->toBe($trialEnd->getTimestamp())
        ->and($subscription->ends_at?->toDateString())->toBe('2026-12-01')
        ->and($subscription->cancel_at_period_end)->toBeFalse()
        ->and($seats?->quantity)->toBe(10)
        ->and($seats?->provider_id)->toBe('si_seats')
        ->and($seats?->price?->minor())->toBe('1200')
        ->and($storage?->quantity)->toBeNull()
        ->and(Purchases::for($company)->subscribedTo('team'))->toBeTrue();

    Event::assertDispatchedTimes(SubscriptionStarted::class, 1);
});

it('sends an invoice-collected subscription with its due days', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription())]);

    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData(
        prices: [new SubscriptionPrice('price_seats', 3, ['tier' => 'a'])],
        automaticTax: false,
        collectionMethod: CollectionMethod::SendInvoice,
        daysUntilDue: 14,
    ));

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['collection_method'] === 'send_invoice'
        && stripeForm($request)['days_until_due'] === '14'
        && stripeForm($request)['automatic_tax'] === ['enabled' => 'false']
        && stripeForm($request)['items'] === [['price' => 'price_seats', 'quantity' => '3', 'metadata' => ['tier' => 'a']]]
        && preg_match('/^[0-9a-f-]{36}$/', $request->header('Idempotency-Key')[0] ?? '') === 1);
});

it('refuses to start a subscription for an owner without a stripe customer', function (): void {
    Http::fake();

    expect(fn () => Purchases::stripe()->subscriptions()->create(
        Organization::query()->create(['name' => 'Acme']),
        new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')]),
    ))->toThrow(BillingException::class, 'has no Stripe customer yet');

    Http::assertNothingSent();
});

it('validates a subscription before stripe is asked', function (Closure $build, string $message): void {
    expect($build)->toThrow(BillingException::class, $message);
})->with([
    'no price' => [fn () => new CreateSubscriptionData([]), 'at least one price'],
    'zero trial' => [fn () => new CreateSubscriptionData([SubscriptionPrice::licensed('p')], trialDays: 0), 'at least one day'],
    'due days off invoice' => [fn () => new CreateSubscriptionData([SubscriptionPrice::licensed('p')], daysUntilDue: 3), 'send_invoice'],
    'negative quantity' => [fn () => SubscriptionPrice::licensed('p', -1), 'cannot be negative'],
    'blank price' => [fn () => SubscriptionPrice::metered(' '), 'price id'],
    'checkout without price' => [fn () => new CheckoutSubscriptionData([], 'https://app.test/ok'), 'at least one price'],
    'checkout zero trial' => [fn () => new CheckoutSubscriptionData([SubscriptionPrice::licensed('p')], 'https://app.test/ok', trialDays: 0), 'at least one day'],
    'empty change' => [fn () => new SubscriptionChange([]), 'at least one item change'],
    'item with no stripe id' => [fn () => ItemChange::quantity(new SubscriptionItem, 2), 'has no Stripe id'],
    'blank swap price' => [fn () => ItemChange::swap('si_1', ''), 'price id'],
]);

it('opens a hosted checkout in subscription mode', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response([
        'id' => 'cs_1', 'object' => 'checkout.session', 'mode' => 'subscription', 'customer' => 'cus_1',
        'url' => 'https://checkout.stripe.com/c/pay/cs_1', 'payment_status' => 'unpaid',
    ])]);

    $session = Purchases::stripe()->subscriptions()->checkout($company, new CheckoutSubscriptionData(
        prices: [SubscriptionPrice::licensed('price_seats', 10), SubscriptionPrice::metered('price_storage')],
        successUrl: 'https://app.test/billing?session={CHECKOUT_SESSION_ID}',
        cancelUrl: 'https://app.test/billing',
        trialDays: 7,
        automaticTax: true,
        metadata: ['company' => 'acme'],
        clientReferenceId: 'acme',
    ), idempotencyKey: 'checkout-acme');

    expect($session->id)->toBe('cs_1')
        ->and($session->url)->toBe('https://checkout.stripe.com/c/pay/cs_1')
        ->and($session->customer)->toBe('cus_1');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
        && $request->hasHeader('Idempotency-Key', 'checkout-acme')
        && stripeForm($request)['mode'] === 'subscription'
        && stripeForm($request)['customer'] === 'cus_1'
        && stripeForm($request)['line_items'] === [['price' => 'price_seats', 'quantity' => '10'], ['price' => 'price_storage']]
        && stripeForm($request)['success_url'] === 'https://app.test/billing?session={CHECKOUT_SESSION_ID}'
        && stripeForm($request)['cancel_url'] === 'https://app.test/billing'
        && stripeForm($request)['client_reference_id'] === 'acme'
        && stripeForm($request)['automatic_tax'] === ['enabled' => 'true']
        && stripeForm($request)['customer_update'] === ['address' => 'auto']
        && stripeForm($request)['subscription_data'] === ['trial_period_days' => '7', 'metadata' => ['company' => 'acme']]
        && ! isset(stripeForm($request)['payment_method_types']));
});

it('sends a bare checkout without optional parameters', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_2', 'url' => 'https://checkout.stripe.com/c/pay/cs_2'])]);

    Purchases::stripe()->subscriptions()->checkout($company, new CheckoutSubscriptionData([SubscriptionPrice::licensed('price_seats', 1)], 'https://app.test/ok'));

    Http::assertSent(fn (HttpRequest $request): bool => ! isset(stripeForm($request)['subscription_data'])
        && ! isset(stripeForm($request)['customer_update'])
        && ! isset(stripeForm($request)['automatic_tax'])
        && ! isset(stripeForm($request)['cancel_url']));
});

it('raises the seat count with the proration behaviour asked for', function (ProrationBehavior $proration): void {
    Event::fake([SubscriptionUpdated::class]);
    $company = subscriptionsTestCompany();
    $seats = Subscription::query()->sole()->itemFor('price_seats');

    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription(['items' => ['data' => [
        stripeBillingItem('si_seats', 'price_seats', 11),
        stripeBillingItem('si_storage', 'price_storage', null),
    ]]]))]);

    $subscription = Purchases::stripe()->subscriptions()->updateQuantity($seats ?? 'si_seats', 11, $proration, 'seats-11');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_1'
        && $request->hasHeader('Idempotency-Key', 'seats-11')
        && stripeForm($request)['items'] === [['id' => 'si_seats', 'quantity' => '11']]
        && stripeForm($request)['proration_behavior'] === $proration->value);

    expect($subscription->itemFor('price_seats')?->quantity)->toBe(11)
        ->and($subscription->items()->count())->toBe(2)
        ->and($subscription->owner?->is($company))->toBeTrue();
    Event::assertDispatchedTimes(SubscriptionUpdated::class, 1);
})->with(ProrationBehavior::cases());

it('finds the subscription of an item stripe knows but this app does not store', function (): void {
    Http::fake([
        'api.stripe.com/v1/subscription_items/si_remote' => Http::response(['id' => 'si_remote', 'object' => 'subscription_item', 'subscription' => 'sub_remote']),
        'api.stripe.com/v1/subscriptions/sub_remote' => Http::response(stripeBillingSubscription(['id' => 'sub_remote', 'items' => ['data' => [stripeBillingItem('si_remote', 'price_seats', 4)]]])),
    ]);

    $subscription = Purchases::stripe()->subscriptions()->updateQuantity('si_remote', 4);

    expect($subscription->provider_id)->toBe('sub_remote')
        ->and($subscription->items()->sole()->quantity)->toBe(4);
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/subscription_items/si_remote');
});

it('swaps a price, adds a metered item and removes one', function (): void {
    subscriptionsTestCompany();
    $subscription = Subscription::query()->sole();

    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()
        ->push(stripeBillingSubscription(['items' => ['data' => [stripeBillingItem('si_seats', 'price_seats_annual', 10, 12000), stripeBillingItem('si_storage', 'price_storage', null)]]]))
        ->push(stripeBillingSubscription(['items' => ['data' => [stripeBillingItem('si_seats', 'price_seats_annual', 10, 12000)]]]))
        ->push(stripeBillingSubscription(['items' => ['data' => [stripeBillingItem('si_seats', 'price_seats_annual', 10, 12000), stripeBillingItem('si_api', 'price_api', null)]]])),
    ]);

    $swapped = Purchases::stripe()->subscriptions()->swap($subscription->itemFor('price_seats') ?? 'si_seats', 'price_seats_annual', 10, ProrationBehavior::AlwaysInvoice);
    expect($swapped->itemFor('price_seats_annual')?->price?->minor())->toBe('12000')
        ->and($swapped->itemFor('price_seats'))->toBeNull();

    $removed = Purchases::stripe()->subscriptions()->removeItem('si_storage', ProrationBehavior::None, clearUsage: true);
    expect($removed->items()->pluck('provider_id')->all())->toBe(['si_seats']);

    $added = Purchases::stripe()->subscriptions()->addItem($subscription, 'price_api');
    expect($added->items()->pluck('provider_id')->sort()->values()->all())->toBe(['si_api', 'si_seats']);

    $sent = Http::recorded()->map(fn (array $pair): HttpRequest => $pair[0])
        ->filter(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_1')
        ->values();

    expect($sent)->toHaveCount(3)
        ->and(stripeForm($sent[0])['items'])->toBe([['id' => 'si_seats', 'price' => 'price_seats_annual', 'quantity' => '10']])
        ->and(stripeForm($sent[0])['proration_behavior'])->toBe('always_invoice')
        ->and(stripeForm($sent[1])['items'])->toBe([['id' => 'si_storage', 'deleted' => 'true', 'clear_usage' => 'true']])
        ->and(stripeForm($sent[1])['proration_behavior'])->toBe('none')
        ->and(stripeForm($sent[2])['items'])->toBe([['price' => 'price_api']])
        ->and(stripeForm($sent[2])['proration_behavior'])->toBe('create_prorations');
});

it('cancels at the period end, resumes, and cancels immediately', function (): void {
    Event::fake([SubscriptionCanceled::class]);
    subscriptionsTestCompany();

    Http::fake([
        'api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()
            ->push(stripeBillingSubscription(['cancel_at_period_end' => true]))
            // resume() reads the subscription first …
            ->push(stripeBillingSubscription(['cancel_at_period_end' => true]))
            ->push(stripeBillingSubscription(['cancel_at_period_end' => false])),
        'api.stripe.com/v1/subscriptions/sub_1?*' => Http::response(stripeBillingSubscription(['status' => 'canceled'])),
    ]);

    $scheduled = Purchases::stripe()->subscriptions()->cancelAtPeriodEnd('sub_1', 'cancel-1');
    expect($scheduled->cancel_at_period_end)->toBeTrue()
        ->and($scheduled->status)->toBe(Status::Completed)
        ->and($scheduled->isActive())->toBeTrue();

    $resumed = Purchases::stripe()->subscriptions()->resume($scheduled, 'resume-1');
    expect($resumed->cancel_at_period_end)->toBeFalse();

    $canceled = Purchases::stripe()->subscriptions()->cancel($resumed, invoiceNow: true, prorate: true, idempotencyKey: 'cancel-now');
    expect($canceled->status)->toBe(Status::Canceled);
    Event::assertDispatchedTimes(SubscriptionCanceled::class, 1);

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Idempotency-Key', 'cancel-1')
        && stripeForm($request)['cancel_at_period_end'] === 'true');
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Idempotency-Key', 'resume-1')
        && stripeForm($request)['cancel_at_period_end'] === 'false');
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
        && str_starts_with($request->url(), 'https://api.stripe.com/v1/subscriptions/sub_1?')
        && subscriptionsTestQuery($request) === ['invoice_now' => 'true', 'prorate' => 'true']);
});

it('cancels immediately without the optional flags', function (): void {
    subscriptionsTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1*' => Http::response(stripeBillingSubscription(['status' => 'canceled']))]);

    Purchases::stripe()->subscriptions()->cancel('sub_1');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_1');
});

it('refuses a subscription that is not stripe\'s, and a malformed id, before stripe is called', function (): void {
    Http::fake();
    $apple = Subscription::factory()->create(['provider' => 'apple', 'provider_id' => 'orig_1']);

    expect(fn () => Purchases::stripe()->subscriptions()->cancel($apple))->toThrow(BillingException::class, 'not a Stripe subscription')
        ->and(fn () => Purchases::stripe()->subscriptions()->resume('..'))->toThrow(BillingException::class, 'Malformed Stripe id')
        ->and(fn () => Purchases::stripe()->subscriptions()->cancelAtPeriodEnd(''))->toThrow(BillingException::class, 'has no Stripe id')
        ->and(fn () => Purchases::stripe()->subscriptions()->change(null, new SubscriptionChange([ItemChange::add('price_x')])))->toThrow(BillingException::class, 'Name the subscription');

    Http::assertNothingSent();
});

it('previews the next invoice for a planned seat change, then applies the same change', function (): void {
    subscriptionsTestCompany();
    $seats = Subscription::query()->sole()->itemFor('price_seats');
    $prorationDate = Carbon::parse('2026-11-10 12:00:00', 'UTC');

    Http::fake([
        'api.stripe.com/v1/invoices/create_preview' => Http::response([
            'id' => 'upcoming_in_1', 'object' => 'invoice', 'currency' => 'eur',
            'amount_due' => 13550, 'total' => 13550, 'subtotal' => 13200,
            'period_start' => Carbon::parse('2026-11-01', 'UTC')->getTimestamp(),
            'period_end' => Carbon::parse('2026-12-01', 'UTC')->getTimestamp(),
            'next_payment_attempt' => Carbon::parse('2026-12-01 01:00', 'UTC')->getTimestamp(),
            'lines' => ['object' => 'list', 'data' => [
                ['id' => 'il_1', 'amount' => 830, 'currency' => 'eur', 'description' => 'Remaining time on 1 × Seat', 'quantity' => 1,
                    'period' => ['start' => $prorationDate->getTimestamp(), 'end' => Carbon::parse('2026-12-01', 'UTC')->getTimestamp()],
                    'pricing' => ['type' => 'price_details', 'price_details' => ['price' => 'price_seats', 'product' => 'prod_seats']],
                    'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => ['subscription_item' => 'si_seats', 'proration' => true]]],
                ['id' => 'il_2', 'amount' => 13200, 'currency' => 'eur', 'description' => '11 × Seat', 'quantity' => 11,
                    'price' => ['id' => 'price_seats'], 'proration' => false, 'subscription_item' => 'si_seats'],
            ]],
        ]),
        'api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription(['items' => ['data' => [stripeBillingItem('si_seats', 'price_seats', 11)]]])),
    ]);

    $change = new SubscriptionChange([ItemChange::quantity($seats ?? 'si_seats', 11)], ProrationBehavior::CreateProrations, $prorationDate);

    $preview = Purchases::stripe()->subscriptions()->preview('sub_1', $change);

    expect($preview->amountDue?->minor())->toBe('13550')
        ->and($preview->amountDue?->currency()->code)->toBe('EUR')
        ->and($preview->total?->minor())->toBe('13550')
        ->and($preview->subtotal?->minor())->toBe('13200')
        ->and($preview->currency)->toBe('EUR')
        ->and($preview->periodEnd?->toDateString())->toBe('2026-12-01')
        ->and($preview->nextPaymentAttempt)->not->toBeNull()
        ->and($preview->lines)->toHaveCount(2)
        ->and($preview->prorations())->toHaveCount(1)
        ->and($preview->prorations()[0]->amount?->minor())->toBe('830')
        ->and($preview->prorations()[0]->priceId)->toBe('price_seats')
        ->and($preview->prorations()[0]->subscriptionItem)->toBe('si_seats')
        ->and($preview->lines[1]->quantity)->toBe(11)
        ->and($preview->lines[1]->priceId)->toBe('price_seats')
        ->and($preview->lines[1]->proration)->toBeFalse();

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/invoices/create_preview'
        && ! $request->hasHeader('Idempotency-Key')
        && stripeForm($request)['subscription'] === 'sub_1'
        && stripeForm($request)['subscription_details'] === [
            'items' => [['id' => 'si_seats', 'quantity' => '11']],
            'proration_behavior' => 'create_prorations',
            'proration_date' => (string) $prorationDate->getTimestamp(),
        ]);

    $applied = Purchases::stripe()->subscriptions()->change('sub_1', $change, 'apply-seats-11');

    expect($applied->itemFor('price_seats')?->quantity)->toBe(11);
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/subscriptions/sub_1'
        && stripeForm($request)['proration_date'] === (string) $prorationDate->getTimestamp()
        && stripeForm($request)['items'] === [['id' => 'si_seats', 'quantity' => '11']]);
});

it('previews the next invoice with no change', function (): void {
    Http::fake(['api.stripe.com/v1/invoices/create_preview' => Http::response(['currency' => 'usd', 'amount_due' => 0, 'lines' => ['data' => []]])]);

    $preview = Purchases::stripe()->subscriptions()->preview('sub_9');

    expect($preview->amountDue?->isZero())->toBeTrue()
        ->and($preview->lines)->toBe([]);
    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request) === ['subscription' => 'sub_9']);
});

it('sends a payment behaviour with a change', function (): void {
    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription())]);

    Purchases::stripe()->subscriptions()->change('sub_1', new SubscriptionChange(
        [ItemChange::quantity('si_seats', 12), ItemChange::add('price_api')],
        ProrationBehavior::AlwaysInvoice,
        paymentBehavior: PaymentBehavior::PendingIfIncomplete,
    ));

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['payment_behavior'] === 'pending_if_incomplete'
        && stripeForm($request)['items'] === [['id' => 'si_seats', 'quantity' => '12'], ['price' => 'price_api']]);
});
