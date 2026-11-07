<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CheckoutSubscriptionData;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\CheckoutSession;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Opens a Stripe Checkout Session in subscription mode for an owner's customer: redirect to
 * its `url` and Stripe collects the payment details. The subscription it creates arrives by
 * webhook (`customer.subscription.created`) and is linked to the owner through its customer.
 */
final readonly class CreateStripeCheckoutSessionAction
{
    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
    ) {}

    public function execute(Model $owner, CheckoutSubscriptionData $data, ?string $idempotencyKey = null): CheckoutSession
    {
        $customer = $this->customers->customerId($owner, 'stripe') ?? throw BillingException::noCustomer($owner);

        return CheckoutSession::fromRaw(
            $this->api->post('/checkout/sessions', $data->toParams($customer), IdempotencyKey::resolve($idempotencyKey))->body,
        );
    }
}
