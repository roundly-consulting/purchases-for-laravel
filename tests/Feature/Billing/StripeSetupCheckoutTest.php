<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Purchases\Actions\Stripe\CreateStripeSetupSessionAction;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Signal;
use RoundlyConsulting\Purchases\Events\DefaultPaymentMethodUpdated;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_setup',
        'webhook_secret' => 'whsec_test',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00', 'UTC'));
    Http::preventStrayRequests();
});

afterEach(fn () => Carbon::setTestNow());

function setupTestCompany(): Organization
{
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    return $company;
}

/**
 * A completed setup Checkout Session as `checkout.session.completed` carries it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function setupTestSession(array $overrides = []): array
{
    return $overrides + [
        'id' => 'cs_setup_1',
        'object' => 'checkout.session',
        'mode' => 'setup',
        'status' => 'complete',
        'customer' => 'cus_1',
        'setup_intent' => 'seti_1',
        'metadata' => ['purchases_default_payment_method' => 'true'],
    ];
}

function setupTestStripe(): void
{
    Http::fake([
        'api.stripe.com/v1/setup_intents/seti_1' => Http::response(['id' => 'seti_1', 'object' => 'setup_intent', 'status' => 'succeeded', 'payment_method' => 'pm_card']),
        'api.stripe.com/v1/customers/cus_1' => Http::response(['id' => 'cus_1', 'object' => 'customer']),
    ]);
}

function setupTestDefaultWasSet(): bool
{
    return Http::recorded(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.stripe.com/v1/customers/cus_1'
        && stripeForm($request)['invoice_settings'] === ['default_payment_method' => 'pm_card'])->isNotEmpty();
}

it('opens a setup checkout for the owner\'s customer, marked to set the default', function (): void {
    $company = setupTestCompany();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_setup_1', 'object' => 'checkout.session', 'mode' => 'setup', 'customer' => 'cus_1', 'url' => 'https://checkout.stripe.com/c/setup'])]);

    $session = Purchases::stripe()->checkout()->setup($company, 'https://app.test/billing?done=1', 'https://app.test/billing', 'EUR', 'setup-acme');

    expect($session->id)->toBe('cs_setup_1')
        ->and($session->url)->toBe('https://checkout.stripe.com/c/setup')
        ->and($session->customer)->toBe('cus_1');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
        && $request->hasHeader('Idempotency-Key', 'setup-acme')
        && stripeForm($request) === [
            'mode' => 'setup',
            'customer' => 'cus_1',
            'currency' => 'eur',
            'success_url' => 'https://app.test/billing?done=1',
            'cancel_url' => 'https://app.test/billing',
            'metadata' => ['purchases_default_payment_method' => 'true'],
        ]);
});

it('refuses a setup checkout for an owner without a customer, or with a malformed currency', function (): void {
    Http::fake();
    $stranger = Organization::query()->create(['name' => 'Stranger']);
    $company = setupTestCompany();

    expect(fn () => Purchases::stripe()->checkout()->setup($stranger, 'https://a.test', 'https://b.test', 'eur'))->toThrow(BillingException::class, 'has no Stripe customer yet')
        ->and(fn () => Purchases::stripe()->checkout()->setup($company, 'https://a.test', 'https://b.test', 'euro'))->toThrow(BillingException::class, 'three-letter ISO code')
        ->and(fn () => Purchases::stripe()->checkout()->setup($company, 'https://a.test', 'https://b.test', ''))->toThrow(BillingException::class, 'three-letter ISO code');

    Http::assertNothingSent();
});

it('takes the currency from the config when the call passes none, and the call\'s over the config', function (?string $passed, string $sent): void {
    config()->set('purchases.settings.stripe.currency', 'CZK');
    $company = setupTestCompany();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_setup_1'])]);

    Purchases::stripe()->checkout()->setup($company, 'https://a.test', 'https://b.test', $passed);

    Http::assertSent(fn (HttpRequest $request): bool => stripeForm($request)['currency'] === $sent);
})->with([
    'config' => [null, 'czk'],
    'argument' => ['EUR', 'eur'],
]);

it('refuses a setup checkout without any currency, before stripe is asked', function (?string $configured): void {
    config()->set('purchases.settings.stripe.currency', $configured);
    $company = setupTestCompany();
    Http::fake();

    expect(fn () => Purchases::stripe()->checkout()->setup($company, 'https://a.test', 'https://b.test'))
        ->toThrow(BillingException::class, 'needs a currency: pass one, or set [purchases.settings.stripe.currency]');
    Http::assertNothingSent();
})->with(['unset' => [null], 'blank' => ['  ']]);

it('refuses a configured currency that is no iso code, naming the key', function (mixed $configured): void {
    config()->set('purchases.settings.stripe.currency', $configured);
    $company = setupTestCompany();
    Http::fake();

    expect(fn () => Purchases::stripe()->checkout()->setup($company, 'https://a.test', 'https://b.test'))
        ->toThrow(InvalidConfigurationException::class, '[purchases.settings.stripe.currency]');
    Http::assertNothingSent();
})->with(['not a code' => ['euro'], 'not a string' => [978]]);

it('makes the collected payment method the customer\'s default when the setup checkout completes', function (): void {
    $company = setupTestCompany();
    setupTestStripe();
    Event::fake([DefaultPaymentMethodUpdated::class]);

    stripeWebhook('checkout.session.completed', setupTestSession(), Carbon::now()->subMinute());

    expect(setupTestDefaultWasSet())->toBeTrue();
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/setup_intents/seti_1');
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.stripe.com/v1/customers/cus_1'
        && preg_match('/^[0-9a-f-]{36}$/', $request->header('Idempotency-Key')[0] ?? '') === 1);

    Event::assertDispatched(DefaultPaymentMethodUpdated::class, fn (DefaultPaymentMethodUpdated $event): bool => $event->customerId === 'cus_1'
        && $event->paymentMethodId === 'pm_card'
        && $event->owner?->is($company) === true
        && $event->result->providerId() === 'cs_setup_1');
});

it('reads the payment method of an expanded setup intent without asking stripe for it', function (): void {
    setupTestCompany();
    setupTestStripe();

    stripeWebhook('checkout.session.completed', setupTestSession(['setup_intent' => ['id' => 'seti_1', 'payment_method' => ['id' => 'pm_card']]]), Carbon::now()->subMinute());

    expect(setupTestDefaultWasSet())->toBeTrue();
    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'setup_intents'));
});

it('decodes a marked setup checkout into a notification that signals the collected method', function (): void {
    $result = Purchases::result('stripe', stripeSignedRequest([
        'id' => 'evt_setup', 'type' => 'checkout.session.completed', 'created' => Carbon::now()->getTimestamp(),
        'data' => ['object' => setupTestSession()],
    ]));

    expect($result)->toBeInstanceOf(GenericResult::class)
        ->and($result->type())->toBe(ResultType::Notification)
        ->and($result instanceof GenericResult ? $result->signal() : null)->toBe(Signal::PaymentMethodCollected)
        ->and($result instanceof GenericResult ? $result->customerId() : null)->toBe('cus_1');

    // Decoding asks Stripe nothing: the write belongs to the recording step.
    Http::assertNothingSent();
});

it('leaves a setup checkout it did not open, and a subscription checkout, alone', function (array $session): void {
    setupTestCompany();
    Http::fake();
    Event::fake([DefaultPaymentMethodUpdated::class]);

    stripeWebhook('checkout.session.completed', $session, Carbon::now()->subMinute());

    Http::assertNothingSent();
    Event::assertNotDispatched(DefaultPaymentMethodUpdated::class);
})->with([
    'unmarked setup' => [setupTestSession(['metadata' => []])],
    'marker not true' => [setupTestSession(['metadata' => ['purchases_default_payment_method' => 'false']])],
    'subscription checkout' => [setupTestSession(['mode' => 'subscription', 'subscription' => 'sub_1'])],
]);

it('does nothing when no secret key is configured, or there is nothing to set', function (array $session, ?string $secret): void {
    setupTestCompany();
    config()->set('purchases.settings.stripe.secret', $secret);
    Http::fake(['api.stripe.com/v1/setup_intents/seti_1' => Http::response(['id' => 'seti_1', 'payment_method' => null])]);
    Event::fake([DefaultPaymentMethodUpdated::class]);

    stripeWebhook('checkout.session.completed', $session, Carbon::now()->subMinute());

    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/customers/'));
    Event::assertNotDispatched(DefaultPaymentMethodUpdated::class);
})->with([
    'no secret' => [setupTestSession(), null],
    'blank secret' => [setupTestSession(), '  '],
    'no customer' => [setupTestSession(['customer' => null]), 'sk_test_setup'],
    'no setup intent' => [setupTestSession(['setup_intent' => null]), 'sk_test_setup'],
    'setup intent without a method' => [setupTestSession(), 'sk_test_setup'],
]);

it('fails the webhook when stripe refuses the default, so stripe delivers it again', function (): void {
    config()->set('purchases.audit.deduplicate', true);
    setupTestCompany();
    Http::fake([
        'api.stripe.com/v1/setup_intents/seti_1' => Http::response(['id' => 'seti_1', 'payment_method' => 'pm_card']),
        'api.stripe.com/v1/customers/cus_1' => Http::sequence()
            ->push(['error' => ['type' => 'api_error', 'message' => 'Something went wrong.']], 500)
            ->push(['id' => 'cus_1', 'object' => 'customer']),
    ]);

    $deliver = fn () => Purchases::handle('stripe', stripeSignedRequest([
        'id' => 'evt_setup_retry', 'type' => 'checkout.session.completed', 'created' => Carbon::now()->getTimestamp(),
        'data' => ['object' => setupTestSession()],
    ]));

    expect($deliver)->toThrow(StripeException::class);

    // The claim is released, so the redelivery is processed at once — and succeeds now.
    expect($deliver())->toBeInstanceOf(PurchaseNotification::class)
        ->and(PurchaseNotification::query()->sole()->processed_at)->not->toBeNull();
});

it('does not set the default again when an already processed notification is replayed', function (): void {
    setupTestCompany();
    setupTestStripe();

    stripeWebhook('checkout.session.completed', setupTestSession(), Carbon::now()->subMinute());
    $posts = Http::recorded(fn (HttpRequest $request): bool => $request->method() === 'POST')->count();

    Purchases::replay(PurchaseNotification::query()->sole());

    expect($posts)->toBe(1)
        ->and(Http::recorded(fn (HttpRequest $request): bool => $request->method() === 'POST')->count())->toBe(1);
});

it('sets the default on the queue when processing is queued', function (): void {
    config()->set('purchases.queue.enabled', true);
    setupTestCompany();
    setupTestStripe();
    Queue::fake();

    stripeWebhook('checkout.session.completed', setupTestSession(), Carbon::now()->subMinute());

    expect(setupTestDefaultWasSet())->toBeFalse();
    Queue::assertPushed(ProcessProviderNotification::class, function (ProcessProviderNotification $job): bool {
        app()->call([$job, 'handle']);

        return true;
    });

    expect(setupTestDefaultWasSet())->toBeTrue();
});

it('logs the setup checkout under the fake and runs the same code through every layer', function (): void {
    $company = setupTestCompany();
    Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_setup_1', 'url' => 'https://checkout.stripe.com/c/setup'])]);
    $fake = Purchases::fake();

    $facade = Purchases::stripe()->checkout()->setup($company, 'https://a.test', 'https://b.test', 'eur');
    $injected = app(PurchasesManager::class)->stripe()->checkout()->setup($company, 'https://a.test', 'https://b.test', 'eur');
    $action = app(CreateStripeSetupSessionAction::class)->execute($company, 'https://a.test', 'https://b.test', 'eur');

    expect([$facade->id, $injected->id, $action->id])->toBe(['cs_setup_1', 'cs_setup_1', 'cs_setup_1']);
    $fake->assertBilled('checkout.setup', fn ($owner, string $success, string $cancel, string $currency): bool => $owner->is($company) && $currency === 'eur');
    expect($fake->billingCalls()->pluck('operation')->all())->toBe(['checkout.setup', 'checkout.setup']);
    expect(fn () => $fake->assertNotBilled('checkout.setup'))->toThrow(AssertionFailedError::class);
});
