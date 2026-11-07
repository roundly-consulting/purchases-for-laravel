<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Purchases\Actions\RecordProviderResultAction;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeException;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingResponse;
use RoundlyConsulting\Purchases\Providers\Stripe\SubscriptionResults;

/**
 * Records the Subscription object a billing call answered with, through the same pipeline
 * as a webhook (so lifecycle events and SubscriptionUpdated fire), ordered at Stripe's
 * answer time. The webhooks that follow find the row already up to date. It is not written
 * to the audit log: it is no notification.
 *
 * @internal building block of the Stripe billing actions.
 */
final readonly class RecordStripeSubscriptionAction
{
    public function __construct(
        private RecordProviderResultAction $record,
    ) {}

    public function execute(BillingResponse $response, ?Model $owner = null, ?string $name = null): Subscription
    {
        $id = $response->body['id'] ?? null;

        if (! is_string($id) || $id === '' || ($response->body['object'] ?? 'subscription') !== 'subscription') {
            throw StripeException::unreadable(200, null);
        }

        $subscription = $this->record->execute(
            SubscriptionResults::fromObject($response->body, $response->date, 'stripe', $name),
            $owner,
        );

        if (! $subscription instanceof Subscription) {
            throw StripeException::unreadable(200, null);
        }

        return $subscription;
    }
}
