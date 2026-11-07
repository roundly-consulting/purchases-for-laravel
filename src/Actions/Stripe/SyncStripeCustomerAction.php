<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CustomerData;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\TaxIdData;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Customer;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\TaxId;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Creates the owner's Stripe customer, or updates the one it has, and links the two (see
 * BillingCustomers). The owner can be any saved model — a company, a team, a user.
 *
 * Tax ids are reconciled when `taxIds` is given: a missing one is added, one Stripe holds
 * that the list does not name is removed. Each request carries the call's idempotency key
 * (each tax id its own step of it), so retrying a failed sync with the same key repeats
 * nothing that already happened.
 */
final readonly class SyncStripeCustomerAction
{
    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
    ) {}

    public function execute(Model $owner, CustomerData $data, ?string $idempotencyKey = null): Customer
    {
        if (! $owner->exists) {
            throw BillingException::unsavedOwner($owner);
        }

        $key = IdempotencyKey::resolve($idempotencyKey);
        $existing = $this->customers->customerId($owner, 'stripe');

        if ($existing === null) {
            $params = $data->toParams() + ['expand' => ['tax_ids']];

            if ($data->taxIds !== null && $data->taxIds !== []) {
                $params['tax_id_data'] = array_map(
                    static fn (TaxIdData $taxId): array => ['type' => $taxId->type, 'value' => $taxId->value],
                    $data->taxIds,
                );
            }

            $customer = Customer::fromRaw($this->api->post('/customers', $params, $key)->body);
            $this->customers->remember($owner, 'stripe', $customer->id);

            return $customer;
        }

        $customer = Customer::fromRaw($this->api->post(
            BillingApi::path('customers', $existing),
            $data->toParams() + ['expand' => ['tax_ids']],
            $key,
        )->body);

        return $data->taxIds === null ? $customer : $this->reconcileTaxIds($customer, $data->taxIds, $key);
    }

    /**
     * @param  list<TaxIdData>  $wanted
     */
    private function reconcileTaxIds(Customer $customer, array $wanted, string $key): Customer
    {
        $kept = [];

        foreach ($customer->taxIds as $held) {
            $isWanted = array_filter($wanted, static fn (TaxIdData $taxId): bool => $taxId->matches($held->type, $held->value)) !== [];

            if ($isWanted) {
                $kept[] = $held;

                continue;
            }

            $this->api->delete(BillingApi::path('customers', $customer->id, 'tax_ids', $held->id), [], IdempotencyKey::derive($key, 'tax_id.delete.'.$held->id));
        }

        foreach ($wanted as $index => $taxId) {
            $isHeld = array_filter($kept, static fn (TaxId $held): bool => $taxId->matches($held->type, $held->value)) !== [];

            if ($isHeld) {
                continue;
            }

            $created = TaxId::tryFromRaw($this->api->post(
                BillingApi::path('customers', $customer->id, 'tax_ids'),
                ['type' => $taxId->type, 'value' => $taxId->value],
                IdempotencyKey::derive($key, 'tax_id.'.$index),
            )->body);

            if ($created !== null) {
                $kept[] = $created;
            }
        }

        return $customer->withTaxIds($kept);
    }
}
