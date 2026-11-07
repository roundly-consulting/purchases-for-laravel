<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Contracts\BillingCustomers;
use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\CreateSubscriptionData;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Starts a Stripe subscription for an owner's customer — one or more prices, each with an
 * optional quantity — and records it locally, owned by that owner and named as given. Its
 * lifecycle events fire as for a webhook: SubscriptionStarted for an active or trialing one.
 */
final readonly class CreateStripeSubscriptionAction
{
    public function __construct(
        private BillingApi $api,
        private BillingCustomers $customers,
        private RecordStripeSubscriptionAction $record,
    ) {}

    public function execute(Model $owner, CreateSubscriptionData $data, ?string $idempotencyKey = null): Subscription
    {
        $customer = $this->customers->customerId($owner, 'stripe') ?? throw BillingException::noCustomer($owner);

        $response = $this->api->post('/subscriptions', $data->toParams($customer), IdempotencyKey::resolve($idempotencyKey));

        return $this->record->execute($response, $owner, $data->name);
    }
}
