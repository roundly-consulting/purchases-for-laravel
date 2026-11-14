<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeApiException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeInvalidRequestException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Invoice;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentFailure;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentMethod;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_payment_methods');
    Http::preventStrayRequests();
});

function paymentMethodCompany(): Organization
{
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    return $company;
}

/**
 * @return array<string, mixed>
 */
function paymentMethodCard(): array
{
    return [
        'id' => 'pm_card',
        'object' => 'payment_method',
        'type' => 'card',
        'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2027, 'funding' => 'credit'],
        'customer' => 'cus_1',
    ];
}

/**
 * @param  array<string, mixed>|string|null  $default
 * @return array<string, mixed>
 */
function paymentMethodCustomer(array|string|null $default): array
{
    return ['id' => 'cus_1', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => $default]];
}

describe('customers()->defaultPaymentMethod()', function (): void {
    it('returns null for an owner without a stripe customer, asking Stripe nothing', function (): void {
        Http::fake();

        expect(Purchases::stripe()->customers()->defaultPaymentMethod(Organization::query()->create(['name' => 'Stranger'])))->toBeNull();

        Http::assertNothingSent();
    });

    it('returns null for a customer without a default, or one deleted in Stripe', function (array $customer): void {
        Http::fake(['api.stripe.com/v1/customers/cus_1*' => Http::response($customer)]);

        expect(Purchases::stripe()->customers()->defaultPaymentMethod(paymentMethodCompany()))->toBeNull();
    })->with([
        'no default' => [paymentMethodCustomer(null)],
        'no invoice settings' => [['id' => 'cus_1', 'object' => 'customer']],
        'deleted' => [['id' => 'cus_1', 'object' => 'customer', 'deleted' => true]],
    ]);

    it('maps a card default, expanded in one GET', function (): void {
        $company = paymentMethodCompany();
        Event::fake();
        Http::fake(['api.stripe.com/v1/customers/cus_1*' => Http::response(paymentMethodCustomer(paymentMethodCard()))]);

        $method = Purchases::stripe()->customers()->defaultPaymentMethod($company);

        expect($method)->toBeInstanceOf(PaymentMethod::class)
            ->and($method?->id)->toBe('pm_card')
            ->and($method?->type)->toBe('card')
            ->and($method?->isCard())->toBeTrue()
            ->and($method?->brand)->toBe('visa')
            ->and($method?->last4)->toBe('4242')
            ->and($method?->expMonth)->toBe(8)
            ->and($method?->expYear)->toBe(2027)
            ->and($method?->raw)->toBe(paymentMethodCard());

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://api.stripe.com/v1/customers/cus_1?')
                && $query === ['expand' => ['invoice_settings.default_payment_method']];
        });
        foreach (glob(__DIR__.'/../../../src/Events/*.php') ?: [] as $file) {
            Event::assertNotDispatched('RoundlyConsulting\\Purchases\\Events\\'.basename($file, '.php'));
        }
        expect(BillingCustomer::query()->count())->toBe(1);
    });

    it('maps a sepa_debit default: last4, no brand, no expiry', function (): void {
        Http::fake(['api.stripe.com/v1/customers/cus_1*' => Http::response(paymentMethodCustomer([
            'id' => 'pm_sepa',
            'object' => 'payment_method',
            'type' => 'sepa_debit',
            'sepa_debit' => ['bank_code' => '37040044', 'country' => 'DE', 'last4' => '3000'],
        ]))]);

        $method = Purchases::stripe()->customers()->defaultPaymentMethod(paymentMethodCompany());

        expect($method?->type)->toBe('sepa_debit')
            ->and($method?->isCard())->toBeFalse()
            ->and($method?->brand)->toBeNull()
            ->and($method?->last4)->toBe('3000')
            ->and($method?->expMonth)->toBeNull()
            ->and($method?->expYear)->toBeNull()
            ->and($method?->isExpired())->toBeFalse()
            ->and($method?->expiresAt())->toBeNull();
    });

    it('fetches a default Stripe returned unexpanded', function (): void {
        Http::fake([
            'api.stripe.com/v1/customers/cus_1*' => Http::response(paymentMethodCustomer('pm_card')),
            'api.stripe.com/v1/payment_methods/pm_card' => Http::response(paymentMethodCard()),
        ]);

        expect(Purchases::stripe()->customers()->defaultPaymentMethod(paymentMethodCompany())?->last4)->toBe('4242');
    });

    it('throws the typed stripe exceptions', function (int $status, array $error, string $class): void {
        Http::fake(['api.stripe.com/v1/customers/cus_1*' => Http::response(['error' => $error], $status)]);

        expect(fn () => Purchases::stripe()->customers()->defaultPaymentMethod(paymentMethodCompany()))->toThrow($class);
    })->with([
        '404' => [404, ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such customer: cus_1'], StripeInvalidRequestException::class],
        '500' => [500, ['type' => 'api_error', 'message' => 'Something went wrong.'], StripeApiException::class],
        '503' => [503, [], StripeException::class],
    ]);

    it('refuses to ask Stripe without a secret key', function (): void {
        $company = paymentMethodCompany();
        config()->set('purchases.settings.stripe.secret', '');
        Http::fake();

        expect(fn () => Purchases::stripe()->customers()->defaultPaymentMethod($company))
            ->toThrow(InvalidConfigurationException::class, '[purchases.settings.stripe.secret] is not set');
        Http::assertNothingSent();
    });
});

describe('PaymentMethod::isExpired()', function (): void {
    it('is good through the last day of the expiry month, UTC', function (string $at, bool $expired): void {
        $method = PaymentMethod::fromRaw(paymentMethodCard());

        expect($method->isExpired(CarbonImmutable::parse($at)))->toBe($expired)
            ->and($method->expiresAt()?->toIso8601String())->toBe('2027-09-01T00:00:00+00:00');
    })->with([
        'before' => ['2027-07-31 23:59:59', false],
        'last moment of the month' => ['2027-08-31 23:59:59 UTC', false],
        'first moment after' => ['2027-09-01 00:00:00 UTC', true],
        'a year later' => ['2028-08-01', true],
        'another zone, still August in UTC' => ['2027-09-01 01:00:00 Europe/Bratislava', false],
    ]);

    it('reads now by default, and drops an expiry Stripe sent malformed', function (): void {
        $this->travelTo(CarbonImmutable::parse('2027-09-02', 'UTC'));

        expect(PaymentMethod::fromRaw(paymentMethodCard())->isExpired())->toBeTrue();

        $broken = PaymentMethod::fromRaw(['id' => 'pm_x', 'type' => 'card', 'card' => ['exp_month' => 13, 'exp_year' => '2027']]);

        expect($broken->expMonth)->toBeNull()
            ->and($broken->expYear)->toBeNull()
            ->and($broken->isExpired())->toBeFalse()
            ->and(PaymentMethod::fromRaw(['id' => 'pm_y'])->type)->toBe('unknown');
    });
});

describe('invoices()->lastPaymentFailure()', function (): void {
    it('returns null for an owner without a stripe customer, asking Stripe nothing', function (): void {
        Http::fake();

        expect(Purchases::stripe()->invoices()->lastPaymentFailure(Organization::query()->create(['name' => 'Stranger'])))->toBeNull();

        Http::assertNothingSent();
    });

    it('reads the newest unpaid PaymentIntent with a payment error, dated by its failed charge', function (): void {
        Http::fake(['api.stripe.com/v1/payment_intents*' => Http::response(['object' => 'list', 'has_more' => false, 'data' => [
            ['id' => 'pi_ok', 'status' => 'succeeded', 'created' => 1_762_300_000, 'last_payment_error' => null],
            ['id' => 'pi_given_up', 'status' => 'canceled', 'created' => 1_762_250_000, 'last_payment_error' => ['code' => 'expired_card']],
            ['id' => 'pi_failed', 'status' => 'requires_payment_method', 'created' => 1_762_000_000,
                'latest_charge' => ['id' => 'ch_1', 'created' => 1_762_128_000, 'status' => 'failed'],
                'last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.']],
            ['id' => 'pi_older', 'status' => 'requires_payment_method', 'created' => 1_761_000_000, 'last_payment_error' => ['code' => 'card_declined']],
        ]])]);

        $failure = Purchases::stripe()->invoices()->lastPaymentFailure(paymentMethodCompany());

        expect($failure)->toBeInstanceOf(PaymentFailure::class)
            ->and($failure?->paymentIntent)->toBe('pi_failed')
            ->and($failure?->code)->toBe('card_declined')
            ->and($failure?->declineCode)->toBe('insufficient_funds')
            ->and($failure?->message)->toBe('Your card has insufficient funds.')
            ->and($failure?->at?->getTimestamp())->toBe(1_762_128_000);

        Http::assertSent(function (HttpRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'GET'
                && $query === ['customer' => 'cus_1', 'limit' => '10', 'expand' => ['data.latest_charge']];
        });
    });

    it('returns null when nothing failed, and dates a failure without a charge by its PaymentIntent', function (): void {
        Http::fake(['api.stripe.com/v1/payment_intents*' => Http::sequence()
            ->push(['object' => 'list', 'data' => [['id' => 'pi_ok', 'status' => 'succeeded', 'last_payment_error' => null]]])
            ->push(['object' => 'list', 'data' => [['id' => 'pi_3ds', 'status' => 'requires_action', 'created' => 1_762_000_000, 'latest_charge' => null, 'last_payment_error' => ['code' => 'authentication_required']]]])]);
        $company = paymentMethodCompany();

        expect(Purchases::stripe()->invoices()->lastPaymentFailure($company))->toBeNull();

        $failure = Purchases::stripe()->invoices()->lastPaymentFailure($company);

        expect($failure?->code)->toBe('authentication_required')
            ->and($failure?->declineCode)->toBeNull()
            ->and($failure?->at?->getTimestamp())->toBe(1_762_000_000);
    });

    it('throws the typed stripe exceptions', function (): void {
        Http::fake(['api.stripe.com/v1/payment_intents*' => Http::response(['error' => ['type' => 'api_error']], 500)]);

        expect(fn () => Purchases::stripe()->invoices()->lastPaymentFailure(paymentMethodCompany()))->toThrow(StripeApiException::class);
    });
});

it('reads an invoice\'s due date', function (): void {
    expect(Invoice::fromRaw(['id' => 'in_1', 'due_date' => 1_762_992_000])->dueDate?->toIso8601String())->toBe('2025-11-13T00:00:00+00:00')
        ->and(Invoice::fromRaw(['id' => 'in_2', 'due_date' => null])->dueDate)->toBeNull();
});

describe('the fake', function (): void {
    it('logs both reads and answers them with what the test set, without asking Stripe', function (): void {
        $fake = Purchases::fake();
        Http::fake();
        $company = paymentMethodCompany();
        $card = PaymentMethod::fromRaw(paymentMethodCard());
        $failure = new PaymentFailure(CarbonImmutable::parse('2026-11-03', 'UTC'), 'card_declined', null, null, 'pi_1', []);

        $fake->fakeDefaultPaymentMethod($card)->fakeLastPaymentFailure($failure);

        expect(Purchases::stripe()->customers()->defaultPaymentMethod($company))->toBe($card)
            ->and(app(PurchasesManager::class)->stripe()->invoices()->lastPaymentFailure($company))->toBe($failure);

        $fake->fakeDefaultPaymentMethod(null)->fakeLastPaymentFailure(fn (Organization $owner): ?PaymentFailure => $owner->is($company) ? $failure : null);

        expect(Purchases::stripe()->customers()->defaultPaymentMethod($company))->toBeNull()
            ->and(Purchases::stripe()->invoices()->lastPaymentFailure($company))->toBe($failure)
            ->and(Purchases::stripe()->invoices()->lastPaymentFailure(Organization::query()->create(['name' => 'Other'])))->toBeNull();

        $fake->assertBilled('customers.defaultPaymentMethod', fn (Organization $owner): bool => $owner->is($company));
        $fake->assertBilled('invoices.lastPaymentFailure');
        Http::assertNothingSent();
    });

    it('asks Stripe when the test set nothing', function (): void {
        $fake = Purchases::fake();
        Http::fake(['api.stripe.com/v1/customers/cus_1*' => Http::response(paymentMethodCustomer(paymentMethodCard()))]);

        expect(Purchases::stripe()->customers()->defaultPaymentMethod(paymentMethodCompany())?->id)->toBe('pm_card');

        $fake->assertBilled('customers.defaultPaymentMethod');
    });
});
