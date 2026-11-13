<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\InvoiceList;

/**
 * Lists an owner's Stripe invoices, newest first (`GET /v1/invoices?customer=…`), one page of
 * 1 to 100 at a time — for a billing page that shows each invoice's number, date, status,
 * amounts and links to its hosted page and PDF. Nothing is stored; every call asks Stripe.
 */
final readonly class ListStripeInvoicesAction
{
    public const int MAX_LIMIT = 100;

    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
    ) {}

    /**
     * @param  string|null  $startingAfter  the last invoice id of the page before (`InvoiceList::nextCursor()`)
     */
    public function execute(Model $owner, int $limit = 10, ?string $startingAfter = null): InvoiceList
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw BillingException::invalid('An invoice page holds 1 to '.self::MAX_LIMIT.' invoices.');
        }

        if ($startingAfter !== null && trim($startingAfter) === '') {
            throw BillingException::invalid('The invoice to start after has no Stripe id.');
        }

        $customer = $this->customers->customerId($owner, 'stripe') ?? throw BillingException::noCustomer($owner);

        return InvoiceList::fromRaw($this->api->get('/invoices', [
            'customer' => $customer,
            'limit' => $limit,
            'starting_after' => $startingAfter,
        ])->body);
    }
}
