<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Purchases\Actions\Stripe\PreviewStripeInvoiceAction;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CustomerData;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_fake');
    Http::preventStrayRequests();
});

it('logs every billing write made through the facade, an injected manager and every sub-accessor', function (): void {
    $fake = Purchases::fake();
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    Http::fake([
        'api.stripe.com/v1/customers/cus_1' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/subscription_items/si_seats' => Http::response(['id' => 'si_seats', 'subscription' => 'sub_1']),
        'api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription()),
        'api.stripe.com/v1/billing/meter_events' => Http::response(['event_name' => 'storage_gb_days']),
        'api.stripe.com/v1/billing_portal/sessions' => Http::response(['id' => 'bps_1', 'url' => 'https://billing.stripe.com/p/1']),
        'api.stripe.com/v1/invoices/create_preview' => Http::response(['currency' => 'eur', 'amount_due' => 0]),
    ]);

    $fake->assertNothingBilled();

    Purchases::stripe()->customers()->sync($company, new CustomerData(name: 'Acme'));
    app(PurchasesManager::class)->stripe()->subscriptions()->updateQuantity('si_seats', 11);
    Purchases::stripe()->subscriptions()->cancelAtPeriodEnd('sub_1');
    Purchases::stripe()->meters()->report($company, 'storage_gb_days', 2);
    Purchases::stripe()->portal($company, 'https://app.test');
    Purchases::stripe()->subscriptions()->preview('sub_1');

    $fake->assertBilled('customers.sync', fn ($owner, CustomerData $data): bool => $owner->is($company) && $data->name === 'Acme');
    $fake->assertBilled('subscriptions.updateQuantity', fn (string $item, int $quantity): bool => $item === 'si_seats' && $quantity === 11);
    $fake->assertBilled('subscriptions.cancelAtPeriodEnd');
    $fake->assertBilled('meters.report', fn ($event): bool => $event->customer === 'cus_1' && $event->value === '2');
    $fake->assertBilled('portal');
    $fake->assertNotBilled('subscriptions.cancel');

    // Reads are not writes.
    expect($fake->billingCalls()->pluck('operation')->all())->toBe([
        'customers.sync', 'subscriptions.updateQuantity', 'subscriptions.cancelAtPeriodEnd', 'meters.report', 'portal',
    ])->and(Subscription::query()->sole()->provider_id)->toBe('sub_1');
});

it('fails its billing assertions when the call was not made', function (): void {
    $fake = Purchases::fake();

    expect(fn () => $fake->assertBilled('subscriptions.create'))->toThrow(AssertionFailedError::class, 'Expected the billing call [subscriptions.create].')
        ->and(fn () => $fake->assertBilled('subscriptions.create', fn (): bool => true))->toThrow(AssertionFailedError::class, 'matching the callback');

    Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response(stripeBillingSubscription())]);
    Purchases::stripe()->subscriptions()->resume('sub_1');

    expect(fn () => $fake->assertNothingBilled())->toThrow(AssertionFailedError::class, 'Expected no billing calls.')
        ->and(fn () => $fake->assertNotBilled('subscriptions.resume'))->toThrow(AssertionFailedError::class, 'Expected no billing call [subscriptions.resume].')
        ->and(fn () => $fake->assertBilled('subscriptions.resume', fn (): bool => false))->toThrow(AssertionFailedError::class);
});

it('runs the same code through the facade, the injected manager and the raw action', function (): void {
    Http::fake(['api.stripe.com/v1/invoices/create_preview' => Http::response(['currency' => 'eur', 'amount_due' => 4200])]);

    $facade = Purchases::stripe()->subscriptions()->preview('sub_1');
    $injected = app(PurchasesManager::class)->stripe()->subscriptions()->preview('sub_1');
    $action = app(PreviewStripeInvoiceAction::class)->execute('sub_1');

    expect([$facade->amountDue?->minor(), $injected->amountDue?->minor(), $action->amountDue?->minor()])->toBe(['4200', '4200', '4200']);
    Http::assertSentCount(3);
});
