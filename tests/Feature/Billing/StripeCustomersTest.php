<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\AddressData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CustomerData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\TaxIdData;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;
use RoundlyConsulting\Purchases\Tests\Fixtures\User;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_customers');
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function customersTestStripeCustomer(array $overrides = []): array
{
    return $overrides + [
        'id' => 'cus_1',
        'object' => 'customer',
        'name' => 'Acme s.r.o.',
        'email' => 'billing@acme.test',
        'metadata' => ['plan' => 'team'],
        'tax_ids' => ['object' => 'list', 'data' => [], 'has_more' => false],
    ];
}

it('creates a stripe customer for a company and links the two', function (): void {
    Http::fake(['api.stripe.com/v1/customers' => Http::response(customersTestStripeCustomer([
        'tax_ids' => ['object' => 'list', 'data' => [['id' => 'txi_1', 'object' => 'tax_id', 'type' => 'eu_vat', 'value' => 'SK2020123456']]],
    ]))]);

    $company = Organization::query()->create(['name' => 'Acme']);

    $customer = Purchases::stripe()->customers()->sync($company, new CustomerData(
        name: 'Acme s.r.o.',
        email: 'billing@acme.test',
        address: new AddressData(line1: 'Hlavná 1', city: 'Bratislava', postalCode: '81101', country: 'sk'),
        taxIds: [new TaxIdData('eu_vat', 'SK2020123456')],
        metadata: ['plan' => 'team'],
        preferredLocale: 'sk',
    ));

    expect($customer->id)->toBe('cus_1')
        ->and($customer->name)->toBe('Acme s.r.o.')
        ->and($customer->taxIds)->toHaveCount(1)
        ->and($customer->taxIds[0]->value)->toBe('SK2020123456')
        ->and(Purchases::stripe()->customers()->id($company))->toBe('cus_1')
        ->and(Purchases::stripe()->customers()->owner('cus_1')?->is($company))->toBeTrue()
        ->and(BillingCustomer::query()->sole()->owner_type)->toBe($company->getMorphClass());

    Http::assertSent(function (HttpRequest $request): bool {
        return $request->method() === 'POST'
            && $request->url() === 'https://api.stripe.com/v1/customers'
            && $request->hasHeader('Authorization', 'Bearer sk_test_customers')
            && $request->hasHeader('Stripe-Version', '2026-05-27.dahlia')
            && preg_match('/^[0-9a-f-]{36}$/', $request->header('Idempotency-Key')[0] ?? '') === 1
            && stripeForm($request)['name'] === 'Acme s.r.o.'
            && stripeForm($request)['email'] === 'billing@acme.test'
            && stripeForm($request)['address'] === ['line1' => 'Hlavná 1', 'city' => 'Bratislava', 'postal_code' => '81101', 'country' => 'SK']
            && stripeForm($request)['tax_id_data'] === [['type' => 'eu_vat', 'value' => 'SK2020123456']]
            && stripeForm($request)['metadata'] === ['plan' => 'team']
            && stripeForm($request)['preferred_locales'] === ['sk']
            && stripeForm($request)['expand'] === ['tax_ids'];
    });
});

it('updates the customer the owner has and reconciles its tax ids', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    Http::fake([
        'api.stripe.com/v1/customers/cus_1' => Http::response(customersTestStripeCustomer([
            'tax_ids' => ['object' => 'list', 'data' => [
                ['id' => 'txi_keep', 'type' => 'eu_vat', 'value' => 'SK 2020 123456'],
                ['id' => 'txi_old', 'type' => 'eu_vat', 'value' => 'CZ12345678'],
            ]],
        ])),
        'api.stripe.com/v1/customers/cus_1/tax_ids/txi_old*' => Http::response(['id' => 'txi_old', 'object' => 'tax_id', 'deleted' => true]),
        'api.stripe.com/v1/customers/cus_1/tax_ids' => Http::response(['id' => 'txi_new', 'object' => 'tax_id', 'type' => 'gb_vat', 'value' => 'GB123456789']),
    ]);

    $customer = Purchases::stripe()->customers()->sync($company, new CustomerData(
        email: 'finance@acme.test',
        taxIds: [new TaxIdData('eu_vat', 'sk2020123456'), new TaxIdData('gb_vat', 'GB123456789')],
    ), idempotencyKey: 'sync-acme-1');

    expect(array_map(fn ($taxId): string => $taxId->id, $customer->taxIds))->toBe(['txi_keep', 'txi_new'])
        ->and(BillingCustomer::query()->count())->toBe(1);

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.stripe.com/v1/customers/cus_1'
        && $request->hasHeader('Idempotency-Key', 'sync-acme-1')
        && stripeForm($request)['email'] === 'finance@acme.test'
        && ! isset(stripeForm($request)['name'])
        && ! isset(stripeForm($request)['tax_id_data']));
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
        && str_starts_with($request->url(), 'https://api.stripe.com/v1/customers/cus_1/tax_ids/txi_old')
        && $request->hasHeader('Idempotency-Key', 'sync-acme-1:tax_id.delete.txi_old'));
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.stripe.com/v1/customers/cus_1/tax_ids'
        && $request->hasHeader('Idempotency-Key', 'sync-acme-1:tax_id.1')
        && stripeForm($request)['type'] === 'gb_vat'
        && stripeForm($request)['value'] === 'GB123456789');
    Http::assertSentCount(3);
});

it('leaves the tax ids alone when none are given', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    Http::fake(['api.stripe.com/v1/customers/cus_1' => Http::response(customersTestStripeCustomer([
        'tax_ids' => ['object' => 'list', 'data' => [['id' => 'txi_keep', 'type' => 'eu_vat', 'value' => 'SK2020123456']]],
    ]))]);

    $customer = Purchases::stripe()->customers()->sync($company, new CustomerData(name: 'Acme a.s.'));

    expect($customer->taxIds)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('removes every tax id for an empty list', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    Http::fake([
        'api.stripe.com/v1/customers/cus_1' => Http::response(customersTestStripeCustomer([
            'tax_ids' => ['object' => 'list', 'data' => [['id' => 'txi_1', 'type' => 'eu_vat', 'value' => 'SK2020123456']]],
        ])),
        'api.stripe.com/v1/customers/cus_1/tax_ids/txi_1*' => Http::response(['id' => 'txi_1', 'deleted' => true]),
    ]);

    expect(Purchases::stripe()->customers()->sync($company, new CustomerData(taxIds: []))->taxIds)->toBe([]);
    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE');
});

it('refuses an unsaved owner and a malformed idempotency key before stripe is called', function (Model $owner, ?string $key, string $message): void {
    Http::fake();

    expect(fn () => Purchases::stripe()->customers()->sync($owner, new CustomerData(name: 'x'), $key))
        ->toThrow(BillingException::class, $message);

    Http::assertNothingSent();
})->with([
    'unsaved owner' => [fn () => new Organization, null, 'is not saved'],
    'empty key' => [fn () => Organization::query()->create(['name' => 'a']), ' ', 'idempotency key'],
    'too long a key' => [fn () => Organization::query()->create(['name' => 'a']), str_repeat('k', 256), 'idempotency key'],
]);

it('never hands one stripe customer to two owners', function (): void {
    $acme = Organization::query()->create(['name' => 'Acme']);
    $globex = User::query()->create(['name' => 'Globex']);
    $customers = app(BillingCustomers::class);

    $customers->remember($acme, 'stripe', 'cus_1');

    expect(fn () => $customers->remember($globex, 'stripe', 'cus_1'))
        ->toThrow(BillingException::class, 'already belongs to another owner');

    // A same-id owner of another model is another owner.
    $sameId = User::query()->findOrFail($acme->getKey());
    expect(fn () => $customers->remember($sameId, 'stripe', 'cus_1'))->toThrow(BillingException::class)
        ->and($customers->customerId($sameId, 'stripe'))->toBeNull()
        ->and($customers->customerId(new Organization, 'stripe'))->toBeNull()
        ->and(fn () => $customers->remember(new Organization, 'stripe', 'cus_2'))->toThrow(BillingException::class, 'is not saved');
});

it('relinks an owner to a new customer and restores a link the host soft-deleted', function (): void {
    $acme = Organization::query()->create(['name' => 'Acme']);
    $customers = app(BillingCustomers::class);

    $customers->remember($acme, 'stripe', 'cus_1');
    BillingCustomer::query()->sole()->delete();

    expect($customers->customerId($acme, 'stripe'))->toBeNull()
        ->and($customers->owner('stripe', 'cus_1'))->toBeNull();

    $customers->remember($acme, 'stripe', 'cus_2');

    expect($customers->customerId($acme, 'stripe'))->toBe('cus_2')
        ->and(BillingCustomer::query()->withTrashed()->count())->toBe(1);
});

it('uses the billing customers the host binds instead of the table', function (): void {
    $company = Organization::query()->create(['name' => 'Acme']);

    app()->singleton(BillingCustomers::class, fn (): BillingCustomers => new class implements BillingCustomers
    {
        public function customerId(Model $owner, string $provider): ?string
        {
            return 'cus_host';
        }

        public function owner(string $provider, string $customerId): ?Model
        {
            return Organization::query()->first();
        }

        public function remember(Model $owner, string $provider, string $customerId): void {}
    });

    Http::fake(['api.stripe.com/v1/customers/cus_host' => Http::response(customersTestStripeCustomer(['id' => 'cus_host']))]);

    expect(Purchases::stripe()->customers()->sync($company, new CustomerData(name: 'Acme'))->id)->toBe('cus_host')
        ->and(Purchases::stripe()->customers()->owner('cus_host')?->is($company))->toBeTrue()
        ->and(BillingCustomer::query()->count())->toBe(0);
});

it('refuses a tax id without a type or value, and skips one stripe answers malformed', function (): void {
    expect(fn () => new TaxIdData('', 'SK1'))->toThrow(BillingException::class, 'needs a type and a value')
        ->and(fn () => new TaxIdData('eu_vat', ' '))->toThrow(BillingException::class);

    Http::fake(['api.stripe.com/v1/customers' => Http::response(customersTestStripeCustomer([
        'tax_ids' => ['data' => [['id' => 'txi_1', 'type' => 'eu_vat'], 'junk', ['id' => 'txi_2', 'type' => 'eu_vat', 'value' => 'SK1']]],
    ]))]);

    $customer = Purchases::stripe()->customers()->sync(Organization::query()->create(['name' => 'Acme']), new CustomerData(name: 'Acme'));

    expect(array_map(fn ($taxId): string => $taxId->id, $customer->taxIds))->toBe(['txi_2']);
});
