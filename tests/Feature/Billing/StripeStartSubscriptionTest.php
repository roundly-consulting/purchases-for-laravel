<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Purchases\Actions\Stripe\StartStripeSubscriptionAction;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\StartedSubscription;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionPrice;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\BillingMode;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentBehavior;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\SaveDefaultPaymentMethod;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_start');
    Http::preventStrayRequests();
});

function startTestCompany(): Organization
{
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    return $company;
}

function startTestData(?SaveDefaultPaymentMethod $save = SaveDefaultPaymentMethod::OnSubscription): CreateSubscriptionData
{
    return new CreateSubscriptionData(
        prices: [SubscriptionPrice::licensed('price_seats', 3), SubscriptionPrice::metered('price_storage')],
        name: 'team',
        paymentBehavior: PaymentBehavior::DefaultIncomplete,
        billingMode: BillingMode::Flexible,
        saveDefaultPaymentMethod: $save,
    );
}

/**
 * The first invoice of a subscription, expanded with its confirmation secret.
 *
 * @return array<string, mixed>
 */
function startTestInvoice(): array
{
    return [
        'id' => 'in_first',
        'object' => 'invoice',
        'status' => 'open',
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/first',
        'confirmation_secret' => ['type' => 'payment_intent', 'client_secret' => 'pi_1_secret_abc'],
    ];
}

it('sends the payment settings that keep the first payment method as the subscription default', function (): void {
    $company = startTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription())]);

    Purchases::stripe()->subscriptions()->create($company, startTestData());

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['payment_settings'] === ['save_default_payment_method' => 'on_subscription']
        && ! isset(stripeForm($request)['expand']));
});

it('sends no payment settings when none are given', function (): void {
    $company = startTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription())]);

    Purchases::stripe()->subscriptions()->create($company, startTestData(null));
    Purchases::stripe()->subscriptions()->create($company, startTestData(SaveDefaultPaymentMethod::Off));

    $forms = Http::recorded()->map(fn (array $pair): array => stripeForm($pair[0]));

    expect(isset($forms[0]['payment_settings']))->toBeFalse()
        ->and($forms[1]['payment_settings'])->toBe(['save_default_payment_method' => 'off']);
});

it('starts an incomplete subscription and hands back how the customer pays its first invoice', function (): void {
    $company = startTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription([
        'status' => 'incomplete',
        'latest_invoice' => startTestInvoice(),
    ]))]);

    $started = Purchases::stripe()->subscriptions()->start($company, startTestData(), 'start-acme');

    expect($started)->toBeInstanceOf(StartedSubscription::class)
        ->and($started->requiresPayment())->toBeTrue()
        ->and($started->invoiceId)->toBe('in_first')
        ->and($started->hostedInvoiceUrl)->toBe('https://invoice.stripe.com/i/first')
        ->and($started->clientSecret)->toBe('pi_1_secret_abc')
        ->and($started->subscription->provider_id)->toBe('sub_1')
        ->and($started->subscription->name)->toBe('team')
        ->and($started->subscription->status)->toBe(Status::Pending)
        ->and($started->subscription->owner?->is($company))->toBeTrue();

    // The invoice is stored as its id, never with the client secret.
    $stored = Subscription::query()->sole();
    expect($stored->meta?->get('latest_invoice'))->toBe('in_first')
        ->and((string) json_encode($stored->meta))->not->toContain('secret');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/subscriptions'
        && $request->hasHeader('Idempotency-Key', 'start-acme')
        && stripeForm($request)['expand'] === ['latest_invoice.confirmation_secret']
        && stripeForm($request)['payment_behavior'] === 'default_incomplete'
        && stripeForm($request)['payment_settings'] === ['save_default_payment_method' => 'on_subscription']);
});

it('hands back no payment details for a subscription that started', function (array $overrides): void {
    $company = startTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription($overrides))]);

    $started = Purchases::stripe()->subscriptions()->start($company, startTestData());

    expect($started->requiresPayment())->toBeFalse()
        ->and($started->invoiceId)->toBeNull()
        ->and($started->hostedInvoiceUrl)->toBeNull()
        ->and($started->clientSecret)->toBeNull()
        ->and($started->subscription->status)->toBe(Status::Completed);
})->with([
    'active, invoice expanded' => [['status' => 'active', 'latest_invoice' => startTestInvoice()]],
    'active, invoice id' => [['status' => 'active', 'latest_invoice' => 'in_first']],
]);

it('reads what an incomplete subscription\'s invoice has, and nothing it lacks', function (): void {
    $company = startTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription([
        'status' => 'incomplete',
        'latest_invoice' => ['id' => 'in_first', 'hosted_invoice_url' => null, 'confirmation_secret' => null],
    ]))]);

    $started = Purchases::stripe()->subscriptions()->start($company, startTestData());

    expect($started->invoiceId)->toBe('in_first')
        ->and($started->hostedInvoiceUrl)->toBeNull()
        ->and($started->clientSecret)->toBeNull()
        ->and($started->requiresPayment())->toBeFalse();
});

it('refuses to start with payment details on an api version without confirmation secrets', function (): void {
    $company = startTestCompany();
    config()->set('purchases.settings.stripe.api_version', '2025-02-24.acacia');
    Http::fake();

    expect(fn () => Purchases::stripe()->subscriptions()->start($company, startTestData()))
        ->toThrow(BillingException::class, 'needs Stripe API version 2025-03-31.basil or later');

    Http::assertNothingSent();
});

it('refuses to start for an owner without a stripe customer', function (): void {
    Http::fake();

    expect(fn () => Purchases::stripe()->subscriptions()->start(Organization::query()->create(['name' => 'Stranger']), startTestData()))
        ->toThrow(BillingException::class, 'has no Stripe customer yet');
});

it('honours the one-subscription-per-owner limit when starting', function (): void {
    config()->set('purchases.subscriptions.one_per_owner', true);
    $company = startTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription())]);

    Purchases::stripe()->subscriptions()->start($company, startTestData());

    expect(fn () => Purchases::stripe()->subscriptions()->start($company, startTestData()))
        ->toThrow(BillingException::class, 'already has a subscription');
    Http::assertSentCount(1);
});

it('logs the start under the fake and runs the same code through every layer', function (): void {
    $company = startTestCompany();
    Http::fake(['api.stripe.com/v1/subscriptions' => Http::response(stripeBillingSubscription(['status' => 'incomplete', 'latest_invoice' => startTestInvoice()]))]);
    $fake = Purchases::fake();

    $facade = Purchases::stripe()->subscriptions()->start($company, startTestData());
    $injected = app(PurchasesManager::class)->stripe()->subscriptions()->start($company, startTestData());
    $action = app(StartStripeSubscriptionAction::class)->execute($company, startTestData());

    expect([$facade->clientSecret, $injected->clientSecret, $action->clientSecret])->toBe(['pi_1_secret_abc', 'pi_1_secret_abc', 'pi_1_secret_abc']);
    $fake->assertBilled('subscriptions.start', fn ($owner, CreateSubscriptionData $data): bool => $owner->is($company) && $data->name === 'team');
    $fake->assertNotBilled('subscriptions.create');
    expect(fn () => $fake->assertNotBilled('subscriptions.start'))->toThrow(AssertionFailedError::class);
});
