<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CheckoutSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionPrice;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\BillingMode;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\MissingPaymentMethod;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentMethodCollection;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_flexible');
    Http::preventStrayRequests();
});

function flexibleTestCompany(): Organization
{
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    return $company;
}

/**
 * Stripe's answer for an annual licensed price and a monthly metered one on one subscription:
 * each item keeps its own period.
 *
 * @return array<string, mixed>
 */
function flexibleMixedSubscription(): array
{
    $annual = stripeBillingItem('si_licence', 'price_licence_yearly', 1, 120_000, '2026-11-01', '2027-11-01');
    $annual['price']['recurring'] = ['interval' => 'year', 'interval_count' => 1, 'usage_type' => 'licensed'];
    $metered = stripeBillingItem('si_usage', 'price_usage_monthly', null, 5, '2026-11-01', '2026-12-01');

    return stripeBillingSubscription([
        'billing_mode' => ['type' => 'flexible', 'flexible' => ['proration_discounts' => 'included'], 'updated_at' => 1_793_000_000],
        'items' => ['object' => 'list', 'data' => [$annual, $metered], 'has_more' => false],
    ]);
}

it('starts an annual licence and monthly usage on one flexible subscription', function (): void {
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(flexibleMixedSubscription())]);

    $subscription = Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData(
        prices: [SubscriptionPrice::licensed('price_licence_yearly'), SubscriptionPrice::metered('price_usage_monthly')],
        name: 'platform',
        billingMode: BillingMode::Flexible,
    ));

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['billing_mode'] === ['type' => 'flexible']
        && stripeForm($request)['items'] === [['price' => 'price_licence_yearly', 'quantity' => '1'], ['price' => 'price_usage_monthly']]
        && ! isset(stripeForm($request)['trial_settings']));

    expect($subscription->items()->count())->toBe(2)
        ->and($subscription->itemFor('price_licence_yearly')?->quantity)->toBe(1)
        ->and($subscription->itemFor('price_usage_monthly')?->quantity)->toBeNull()
        // The paid-through date is the latest item period end: the annual licence's.
        ->and($subscription->ends_at?->toDateString())->toBe('2027-11-01')
        ->and($subscription->meta?->get('billing_mode'))->toMatchArray(['type' => 'flexible']);
});

it('sends the host\'s default billing mode, and lets a subscription override it', function (): void {
    config()->set('purchases.settings.stripe.billing_mode', 'flexible');
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::sequence()
        ->push(stripeBillingSubscription(['id' => 'sub_1']))
        ->push(stripeBillingSubscription(['id' => 'sub_2']))]);

    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')]));
    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')], billingMode: BillingMode::Classic));

    $modes = Http::recorded()->map(fn (array $pair): mixed => stripeForm($pair[0])['billing_mode'] ?? null)->all();

    expect($modes)->toBe([['type' => 'flexible'], ['type' => 'classic']]);
});

it('sends no billing mode when neither the subscription nor the host sets one', function (): void {
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription())]);

    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')]));

    Http::assertSent(fn (HttpRequest $request): bool => ! isset(stripeForm($request)['billing_mode']));
});

it('refuses a billing mode on an API version that predates it, before Stripe is asked', function (string $version): void {
    config()->set('purchases.settings.stripe.api_version', $version);
    $company = flexibleTestCompany();

    expect(fn () => Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')], billingMode: BillingMode::Flexible)))
        ->toThrow(BillingException::class, '2025-06-30.basil or later');

    Http::assertNothingSent();
})->with(['2025-03-31.basil', '2024-06-20']);

it('sends a billing mode on the first API version that knows it', function (): void {
    config()->set('purchases.settings.stripe.api_version', '2025-06-30.basil');
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription())]);

    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')], billingMode: BillingMode::Flexible));

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Stripe-Version', '2025-06-30.basil')
        && stripeForm($request)['billing_mode'] === ['type' => 'flexible']);
});

it('refuses a default billing mode or trial end behaviour it does not know', function (string $key, string $value): void {
    config()->set("purchases.settings.stripe.{$key}", $value);
    $company = flexibleTestCompany();

    expect(fn () => Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')], trialDays: 7)))
        ->toThrow(InvalidConfigurationException::class, "purchases.settings.stripe.{$key}");
})->with([
    ['billing_mode', 'hybrid'],
    ['missing_payment_method', 'ignore'],
]);

it('starts a trial without a payment method that pauses when it ends without one', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00', 'UTC'));
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription([
        'status' => 'trialing',
        'trial_end' => Carbon::now()->addDays(14)->getTimestamp(),
        'trial_settings' => ['end_behavior' => ['missing_payment_method' => 'pause']],
    ]))]);

    $subscription = Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData(
        [SubscriptionPrice::licensed('price_seats')],
        trialDays: 14,
        trialEndBehavior: MissingPaymentMethod::Pause,
    ));

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['trial_period_days'] === '14'
        && stripeForm($request)['trial_settings'] === ['end_behavior' => ['missing_payment_method' => 'pause']]);

    expect($subscription->onTrial())->toBeTrue();

    Carbon::setTestNow();
});

it('applies the host\'s trial end behaviour to a trial only', function (): void {
    config()->set('purchases.settings.stripe.missing_payment_method', 'cancel');
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::sequence()
        ->push(stripeBillingSubscription(['id' => 'sub_1']))
        ->push(stripeBillingSubscription(['id' => 'sub_2']))
        ->push(stripeBillingSubscription(['id' => 'sub_3']))]);

    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')], trialDays: 7));
    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')]));
    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')], trialDays: 7, trialEndBehavior: MissingPaymentMethod::CreateInvoice));

    $behaviours = Http::recorded()->map(fn (array $pair): mixed => stripeForm($pair[0])['trial_settings']['end_behavior']['missing_payment_method'] ?? null)->all();

    expect($behaviours)->toBe(['cancel', null, 'create_invoice']);
});

it('refuses a trial end behaviour without a trial', function (Closure $data): void {
    expect($data)->toThrow(BillingException::class, 'needs a trial');
})->with([
    'create' => [fn () => new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')], trialEndBehavior: MissingPaymentMethod::Cancel)],
    'checkout' => [fn () => new CheckoutSubscriptionData([SubscriptionPrice::licensed('price_seats')], 'https://app.test/ok', trialEndBehavior: MissingPaymentMethod::Cancel)],
]);

it('opens a checkout for a free trial that collects no payment method', function (): void {
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);

    Purchases::stripe()->subscriptions()->checkout($company, new CheckoutSubscriptionData(
        prices: [SubscriptionPrice::licensed('price_seats')],
        successUrl: 'https://app.test/ok',
        trialDays: 30,
        billingMode: BillingMode::Flexible,
        trialEndBehavior: MissingPaymentMethod::Cancel,
        paymentMethodCollection: PaymentMethodCollection::IfRequired,
    ));

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['payment_method_collection'] === 'if_required'
        && stripeForm($request)['subscription_data'] === [
            'trial_period_days' => '30',
            'billing_mode' => ['type' => 'flexible'],
            'trial_settings' => ['end_behavior' => ['missing_payment_method' => 'cancel']],
        ]
        && ! isset(stripeForm($request)['payment_method_types']));
});

it('gives a checkout the host\'s defaults', function (): void {
    config()->set('purchases.settings.stripe.billing_mode', 'flexible');
    config()->set('purchases.settings.stripe.missing_payment_method', 'pause');
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);

    Purchases::stripe()->subscriptions()->checkout($company, new CheckoutSubscriptionData([SubscriptionPrice::licensed('price_seats')], 'https://app.test/ok', trialDays: 7));

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['subscription_data'] === [
        'trial_period_days' => '7',
        'billing_mode' => ['type' => 'flexible'],
        'trial_settings' => ['end_behavior' => ['missing_payment_method' => 'pause']],
    ] && ! isset(stripeForm($request)['payment_method_collection']));
});

it('refuses a checkout billing mode the pinned API version predates', function (): void {
    config()->set('purchases.settings.stripe.api_version', '2025-03-31.basil');
    $company = flexibleTestCompany();

    expect(fn () => Purchases::stripe()->subscriptions()->checkout($company, new CheckoutSubscriptionData([SubscriptionPrice::licensed('price_seats')], 'https://app.test/ok', billingMode: BillingMode::Flexible)))
        ->toThrow(BillingException::class, 'billing mode');

    Http::assertNothingSent();
});

it('takes a default given as an enum case', function (): void {
    config()->set('purchases.settings.stripe.billing_mode', BillingMode::Flexible);
    $company = flexibleTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription())]);

    Purchases::stripe()->subscriptions()->create($company, new CreateSubscriptionData([SubscriptionPrice::licensed('price_seats')]));

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['billing_mode'] === ['type' => 'flexible']);
});
