<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Actions\Stripe\ListStripeInvoicesAction;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Facades\Purchases;
use RoundlyConsulting\Purchases\Models\BillingCustomer;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Invoice;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\InvoiceList;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\Tests\Fixtures\Organization;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_invoices');
    Http::preventStrayRequests();
});

function invoicesTestCompany(): Organization
{
    $company = Organization::query()->create(['name' => 'Acme']);
    BillingCustomer::factory()->ownedBy($company)->create(['provider_id' => 'cus_1']);

    return $company;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoicesTestInvoice(string $id, array $overrides = []): array
{
    return $overrides + [
        'id' => $id,
        'object' => 'invoice',
        'number' => 'ACME-'.$id,
        'created' => 1_762_000_000,
        'status' => 'paid',
        'currency' => 'eur',
        'total' => 3600,
        'amount_due' => 3600,
        'amount_paid' => 3600,
        'customer' => 'cus_1',
        'hosted_invoice_url' => 'https://invoice.stripe.com/i/'.$id,
        'invoice_pdf' => 'https://pay.stripe.com/invoice/'.$id.'/pdf',
        'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
    ];
}

it('lists an owner\'s invoices as value objects, newest first, with the next page\'s cursor', function (): void {
    $company = invoicesTestCompany();
    Http::fake(['api.stripe.com/v1/invoices*' => Http::response([
        'object' => 'list',
        'data' => [invoicesTestInvoice('in_2'), invoicesTestInvoice('in_1', ['status' => 'open', 'amount_due' => 1200, 'amount_paid' => 0, 'total' => 1200])],
        'has_more' => true,
    ])]);

    $page = Purchases::stripe()->invoices()->list($company, 2);

    [$latest, $open] = $page->invoices;

    expect($page)->toBeInstanceOf(InvoiceList::class)
        ->and($page->hasMore)->toBeTrue()
        ->and($page->isEmpty())->toBeFalse()
        ->and($page->nextCursor())->toBe('in_1')
        ->and($latest)->toBeInstanceOf(Invoice::class)
        ->and($latest->id)->toBe('in_2')
        ->and($latest->number)->toBe('ACME-in_2')
        ->and($latest->created)->toBeInstanceOf(CarbonImmutable::class)
        ->and($latest->created?->getTimestamp())->toBe(1_762_000_000)
        ->and($latest->status)->toBe('paid')
        ->and($latest->total?->minor())->toBe('3600')
        ->and($latest->total?->currency()->code)->toBe('EUR')
        ->and($latest->amountDue?->minor())->toBe('3600')
        ->and($latest->currency)->toBe('EUR')
        ->and($latest->hostedInvoiceUrl)->toBe('https://invoice.stripe.com/i/in_2')
        ->and($latest->invoicePdf)->toBe('https://pay.stripe.com/invoice/in_2/pdf')
        ->and($latest->subscription)->toBe('sub_1')
        ->and($latest->customer)->toBe('cus_1')
        ->and($open->status)->toBe('open')
        ->and($open->amountDue?->minor())->toBe('1200');

    Http::assertSent(function (HttpRequest $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.stripe.com/v1/invoices?')
            && $query === ['customer' => 'cus_1', 'limit' => '2'];
    });
});

it('reads the next page after a cursor, and has none after the last', function (): void {
    $company = invoicesTestCompany();
    Http::fake(['api.stripe.com/v1/invoices*' => Http::response(['object' => 'list', 'data' => [invoicesTestInvoice('in_0')], 'has_more' => false])]);

    $page = Purchases::stripe()->invoices()->list($company, startingAfter: 'in_1');

    expect($page->hasMore)->toBeFalse()
        ->and($page->nextCursor())->toBeNull();

    Http::assertSent(function (HttpRequest $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['customer' => 'cus_1', 'limit' => '10', 'starting_after' => 'in_1'];
    });
});

it('reads an empty page and an invoice that is not finalized yet', function (): void {
    expect(InvoiceList::fromRaw(['object' => 'list', 'data' => [], 'has_more' => true]))
        ->isEmpty()->toBeTrue()
        ->nextCursor()->toBeNull();

    $draft = Invoice::fromRaw(['id' => 'in_draft', 'status' => 'draft', 'number' => null, 'hosted_invoice_url' => null, 'invoice_pdf' => null, 'currency' => 'eur', 'total' => 0, 'customer' => ['id' => 'cus_1']]);

    expect($draft->number)->toBeNull()
        ->and($draft->hostedInvoiceUrl)->toBeNull()
        ->and($draft->invoicePdf)->toBeNull()
        ->and($draft->created)->toBeNull()
        ->and($draft->total?->minor())->toBe('0')
        ->and($draft->customer)->toBe('cus_1');

    expect(InvoiceList::fromRaw(['data' => 'nonsense'])->invoices)->toBe([])
        ->and(InvoiceList::fromRaw(['data' => ['nonsense', invoicesTestInvoice('in_1')]])->invoices)->toHaveCount(1);
});

it('refuses a page size outside 1 to 100, a blank cursor and an owner without a customer', function (int $limit, ?string $after, string $message): void {
    $company = invoicesTestCompany();
    Http::fake();

    expect(fn () => Purchases::stripe()->invoices()->list($company, $limit, $after))->toThrow(BillingException::class, $message);
    Http::assertNothingSent();
})->with([
    'zero' => [0, null, '1 to 100'],
    'too many' => [101, null, '1 to 100'],
    'blank cursor' => [10, ' ', 'has no Stripe id'],
]);

it('refuses an owner without a stripe customer', function (): void {
    Http::fake();

    expect(fn () => Purchases::stripe()->invoices()->list(Organization::query()->create(['name' => 'Stranger'])))->toThrow(BillingException::class, 'has no Stripe customer yet');
});

it('accepts the page size bounds', function (int $limit): void {
    Http::fake(['api.stripe.com/v1/invoices*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false])]);

    expect(Purchases::stripe()->invoices()->list(invoicesTestCompany(), $limit)->isEmpty())->toBeTrue();
})->with([1, 100]);

it('runs the same read through every layer, and the fake does not log it', function (): void {
    $company = invoicesTestCompany();
    Http::fake(['api.stripe.com/v1/invoices*' => Http::response(['object' => 'list', 'data' => [invoicesTestInvoice('in_1')], 'has_more' => false])]);
    $fake = Purchases::fake();

    $facade = Purchases::stripe()->invoices()->list($company);
    $injected = app(PurchasesManager::class)->stripe()->invoices()->list($company);
    $action = app(ListStripeInvoicesAction::class)->execute($company);

    expect([$facade->invoices[0]->id, $injected->invoices[0]->id, $action->invoices[0]->id])->toBe(['in_1', 'in_1', 'in_1']);
    $fake->assertNothingBilled();
});
