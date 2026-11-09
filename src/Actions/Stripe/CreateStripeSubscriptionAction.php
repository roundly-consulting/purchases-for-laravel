<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingDefaults;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;
use RoundlyConsulting\Purchases\Support\SubscriptionLimit;

/**
 * Starts a Stripe subscription for an owner's customer — one or more prices, each with an
 * optional quantity — and records it locally, owned by that owner and named as given. Its
 * lifecycle events fire as for a webhook: SubscriptionStarted for an active or trialing one.
 *
 * Under `purchases.subscriptions.one_per_owner` an owner who still holds a subscription that is
 * not over is refused, race-free (see SubscriptionLimit).
 */
final readonly class CreateStripeSubscriptionAction
{
    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
        private RecordStripeSubscriptionAction $record,
        private SubscriptionLimit $limit,
    ) {}

    public function execute(Model $owner, CreateSubscriptionData $data, ?string $idempotencyKey = null): Subscription
    {
        $customer = $this->customers->customerId($owner, 'stripe') ?? throw BillingException::noCustomer($owner);
        $mode = $data->billingMode ?? BillingDefaults::billingMode();
        BillingDefaults::assertSupports($mode);
        $params = $data->toParams($customer, $mode, BillingDefaults::trialEndBehavior());
        $key = IdempotencyKey::resolve($idempotencyKey);

        return $this->limit->guard($owner, fn (): Subscription => $this->record->execute(
            $this->api->post('/subscriptions', $params, $key),
            $owner,
            $data->name,
        ));
    }
}
