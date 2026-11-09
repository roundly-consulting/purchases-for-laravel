<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CheckoutSubscriptionData;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingDefaults;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\CheckoutSession;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;
use RoundlyConsulting\Purchases\Support\SubscriptionLimit;

/**
 * Opens a Stripe Checkout Session in subscription mode for an owner's customer: redirect to
 * its `url` and Stripe collects the payment details. The subscription it creates arrives by
 * webhook (`customer.subscription.created`) and is linked to the owner through its customer.
 *
 * Under `purchases.subscriptions.one_per_owner` no session is opened for an owner who still
 * holds a subscription that is not over. The subscription itself starts only when the customer
 * pays, so two sessions opened before either is paid can still both start one: Stripe's own
 * "limit customers to one subscription" Checkout setting closes that gap.
 */
final readonly class CreateStripeCheckoutSessionAction
{
    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
        private SubscriptionLimit $limit,
    ) {}

    public function execute(Model $owner, CheckoutSubscriptionData $data, ?string $idempotencyKey = null): CheckoutSession
    {
        $customer = $this->customers->customerId($owner, 'stripe') ?? throw BillingException::noCustomer($owner);
        $mode = $data->billingMode ?? BillingDefaults::billingMode();
        BillingDefaults::assertSupports($mode);
        $params = $data->toParams($customer, $mode, BillingDefaults::trialEndBehavior());
        $key = IdempotencyKey::resolve($idempotencyKey);

        return $this->limit->guard($owner, fn (): CheckoutSession => CheckoutSession::fromRaw(
            $this->api->post('/checkout/sessions', $params, $key)->body,
        ));
    }
}
