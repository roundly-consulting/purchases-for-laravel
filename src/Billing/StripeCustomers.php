<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Billing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Actions\Stripe\FindStripeDefaultPaymentMethodAction;
use RoundlyConsulting\Purchases\Actions\Stripe\SyncStripeCustomerAction;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CustomerData;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Customer;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentMethod;

/**
 * Stripe customers — `Purchases::stripe()->customers()`. One customer per owner model (a
 * company, a team, a user), linked through BillingCustomers.
 */
final readonly class StripeCustomers
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
     * Create the owner's Stripe customer, or update the one it has, from the data given.
     */
    public function sync(Model $owner, CustomerData $data, ?string $idempotencyKey = null): Customer
    {
        $this->record('customers.sync', [$owner, $data, $idempotencyKey]);

        return $this->container->make(SyncStripeCustomerAction::class)->execute($owner, $data, $idempotencyKey);
    }

    /**
     * The owner's default payment method for invoices — "Visa ••4242, expires 08/27" — read from
     * Stripe on every call. Null when the owner has no Stripe customer (Stripe is not asked) or
     * the customer has no default. Nothing is stored and no event fires.
     */
    public function defaultPaymentMethod(Model $owner): ?PaymentMethod
    {
        $this->record('customers.defaultPaymentMethod', [$owner]);

        $stubbed = $this->stub === null ? null : ($this->stub)('customers.defaultPaymentMethod', [$owner]);

        if ($stubbed !== null) {
            /** @var PaymentMethod|null */
            return $stubbed->value;
        }

        return $this->container->make(FindStripeDefaultPaymentMethodAction::class)->execute($owner);
    }

    /**
     * The owner's Stripe customer id, or null when it has none yet.
     */
    public function id(Model $owner): ?string
    {
        return $this->container->make(BillingCustomers::class)->customerId($owner, 'stripe');
    }

    /**
     * The owner a Stripe customer id belongs to, or null when none is linked — e.g. for a
     * webhook's `customer`.
     */
    public function owner(string $customerId): ?Model
    {
        return $this->container->make(BillingCustomers::class)->owner('stripe', $customerId);
    }

    /**
     * @param  list<mixed>  $arguments
     */
    private function record(string $operation, array $arguments): void
    {
        if ($this->recorder !== null) {
            ($this->recorder)($operation, $arguments);
        }
    }
}
