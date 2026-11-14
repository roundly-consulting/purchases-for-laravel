<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Billing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Actions\Stripe\FindStripeLastPaymentFailureAction;
use RoundlyConsulting\Purchases\Actions\Stripe\ListStripeInvoicesAction;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\InvoiceList;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentFailure;

/**
 * An owner's Stripe invoices — `Purchases::stripe()->invoices()`. Read from Stripe on every
 * call; nothing is stored. `list()` changes nothing, so the fake does not log it;
 * `lastPaymentFailure()` is logged so a test can answer it (`PurchasesFake::fakeLastPaymentFailure()`).
 */
final readonly class StripeInvoices
{
    /**
     * @param  (Closure(string, list<mixed>): void)|null  $recorder
     * @param  (Closure(string, list<mixed>): ?Stubbed)|null  $stub  the fake's canned answers
     */
    public function __construct(
        private Container $container,
        private ?Closure $recorder = null,
        private ?Closure $stub = null,
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

    /**
     * The owner's last failed payment that is still unpaid — "Payment failed on Nov 3:
     * card_declined" — or null when the owner has no Stripe customer (Stripe is not asked) or
     * nothing failed lately. Nothing is stored and no event fires.
     */
    public function lastPaymentFailure(Model $owner): ?PaymentFailure
    {
        if ($this->recorder !== null) {
            ($this->recorder)('invoices.lastPaymentFailure', [$owner]);
        }

        $stubbed = $this->stub === null ? null : ($this->stub)('invoices.lastPaymentFailure', [$owner]);

        if ($stubbed !== null) {
            /** @var PaymentFailure|null */
            return $stubbed->value;
        }

        return $this->container->make(FindStripeLastPaymentFailureAction::class)->execute($owner);
    }
}
