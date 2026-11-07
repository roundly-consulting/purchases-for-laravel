<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\PortalSession;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Opens a Stripe Customer Portal session for an owner's customer — payment methods and
 * invoices, plus whatever the portal configuration allows. Redirect to its `url`.
 */
final readonly class CreateStripePortalSessionAction
{
    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
    ) {}

    /**
     * @param  string|null  $returnUrl  where the portal's back link goes
     * @param  string|null  $configuration  a portal configuration (`bpc_…`); the default one otherwise
     */
    public function execute(Model $owner, ?string $returnUrl = null, ?string $configuration = null, ?string $idempotencyKey = null): PortalSession
    {
        $customer = $this->customers->customerId($owner, 'stripe') ?? throw BillingException::noCustomer($owner);

        return PortalSession::fromRaw($this->api->post('/billing_portal/sessions', [
            'customer' => $customer,
            'return_url' => $returnUrl,
            'configuration' => $configuration,
        ], IdempotencyKey::resolve($idempotencyKey))->body);
    }
}
