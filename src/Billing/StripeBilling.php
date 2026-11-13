<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Billing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Actions\Stripe\CreateStripePortalSessionAction;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PortalSession;

/**
 * Outbound Stripe billing — `Purchases::stripe()`: customers, subscriptions, meters, invoices,
 * setup Checkout and the Customer Portal, over Stripe's REST API with no SDK. Every write takes an idempotency key
 * (a fresh one when none is given) and every Stripe failure throws a typed
 * `Exceptions\Stripe\StripeException`.
 *
 * Each method resolves its action from the container, so the facade, an injected manager and
 * the raw action run the same code, and `Purchases::fake()` records every write.
 */
final readonly class StripeBilling
{
    /**
     * @param  (Closure(string, list<mixed>): void)|null  $recorder  the fake's call log
     */
    public function __construct(
        private Container $container,
        private ?Closure $recorder = null,
    ) {}

    public function customers(): StripeCustomers
    {
        return new StripeCustomers($this->container, $this->recorder);
    }

    public function subscriptions(): StripeSubscriptions
    {
        return new StripeSubscriptions($this->container, $this->recorder);
    }

    public function meters(): StripeMeters
    {
        return new StripeMeters($this->container, $this->recorder);
    }

    public function invoices(): StripeInvoices
    {
        return new StripeInvoices($this->container);
    }

    public function checkout(): StripeCheckout
    {
        return new StripeCheckout($this->container, $this->recorder);
    }

    /**
     * A Customer Portal session for the owner's customer — redirect to its `url`.
     *
     * @param  string|null  $configuration  a portal configuration (`bpc_…`); the default one otherwise
     */
    public function portal(Model $owner, ?string $returnUrl = null, ?string $configuration = null, ?string $idempotencyKey = null): PortalSession
    {
        if ($this->recorder !== null) {
            ($this->recorder)('portal', [$owner, $returnUrl, $configuration, $idempotencyKey]);
        }

        return $this->container->make(CreateStripePortalSessionAction::class)->execute($owner, $returnUrl, $configuration, $idempotencyKey);
    }
}
