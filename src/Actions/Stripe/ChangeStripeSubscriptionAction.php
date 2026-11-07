<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionChange;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\SubscriptionLocator;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Changes a Stripe subscription's items — a quantity, a price swap, an item added or
 * removed — billed as the change's proration behaviour says, and records the result
 * locally (SubscriptionUpdated fires for the new quantity or price).
 *
 * Every change is one `POST /v1/subscriptions/{id}`, which honours the idempotency key and
 * answers with the whole subscription. With no subscription given, it is the one the
 * change's first item belongs to.
 */
final readonly class ChangeStripeSubscriptionAction
{
    public function __construct(
        private BillingApi $api,
        private SubscriptionLocator $locator,
        private RecordStripeSubscriptionAction $record,
    ) {}

    public function execute(Subscription|string|null $subscription, SubscriptionChange $change, ?string $idempotencyKey = null): Subscription
    {
        $key = IdempotencyKey::resolve($idempotencyKey);
        $id = $subscription !== null ? SubscriptionLocator::id($subscription) : $this->locator->forItem($this->firstItem($change));

        return $this->record->execute($this->api->post(BillingApi::path('subscriptions', $id), $change->toParams(), $key));
    }

    private function firstItem(SubscriptionChange $change): string
    {
        foreach ($change->items as $item) {
            if ($item->item !== null) {
                return $item->item;
            }
        }

        throw BillingException::invalid('Name the subscription to add an item to.');
    }
}
