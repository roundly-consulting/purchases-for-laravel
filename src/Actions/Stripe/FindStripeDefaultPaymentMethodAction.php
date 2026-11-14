<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PaymentMethod;

/**
 * Reads an owner's default payment method for invoices — the customer's
 * `invoice_settings.default_payment_method`, expanded in one call
 * (`GET /v1/customers/{id}?expand[]=invoice_settings.default_payment_method`).
 *
 * Null when the owner has no Stripe customer (Stripe is not asked), the customer has no default,
 * or it was deleted in Stripe. Nothing is stored and no event fires; a Stripe failure throws a
 * typed StripeException, a missing secret key InvalidConfigurationException.
 */
final readonly class FindStripeDefaultPaymentMethodAction
{
    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
    ) {}

    public function execute(Model $owner): ?PaymentMethod
    {
        $customer = $this->customers->customerId($owner, 'stripe');

        if ($customer === null) {
            return null;
        }

        $body = $this->api->get(BillingApi::path('customers', $customer), [
            'expand' => ['invoice_settings.default_payment_method'],
        ])->body;

        if (($body['deleted'] ?? false) === true) {
            return null;
        }

        $method = $body['invoice_settings']['default_payment_method'] ?? null;

        // Stripe expands it; an id alone (an expansion it skipped) is fetched on its own.
        if (is_string($method) && $method !== '') {
            $method = $this->api->get(BillingApi::path('payment_methods', $method))->body;
        }

        return is_array($method) && is_string($method['id'] ?? null) && $method['id'] !== ''
            ? PaymentMethod::fromRaw($method)
            : null;
    }
}
