<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Billing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Actions\Stripe\ListStripeInvoicesAction;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\InvoiceList;

/**
 * An owner's Stripe invoices — `Purchases::stripe()->invoices()`. Read from Stripe on every
 * call; nothing is stored. Reads change nothing, so the fake does not log them.
 */
final readonly class StripeInvoices
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * One page of the owner's invoices, newest first.
     *
     * @param  int  $limit  1 to 100
     * @param  string|null  $startingAfter  the previous page's `nextCursor()`
     */
    public function list(Model $owner, int $limit = 10, ?string $startingAfter = null): InvoiceList
    {
        return $this->container->make(ListStripeInvoicesAction::class)->execute($owner, $limit, $startingAfter);
    }
}
