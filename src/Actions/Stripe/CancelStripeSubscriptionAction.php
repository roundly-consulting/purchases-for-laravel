<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\SubscriptionLocator;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Cancels a Stripe subscription — at the end of its paid period (it stays active until
 * then and can still be resumed), or immediately — and records the result locally.
 *
 * Immediately, `invoiceNow` bills un-invoiced metered usage and pending prorations in a
 * final invoice, and `prorate` credits the unused time. Stripe answers an immediate cancel
 * (a DELETE) without honouring an idempotency key; a repeated one is refused by Stripe, as
 * the subscription is already canceled.
 */
final readonly class CancelStripeSubscriptionAction
{
    public function __construct(
        private BillingApi $api,
        private RecordStripeSubscriptionAction $record,
    ) {}

    public function execute(Subscription|string $subscription, bool $atPeriodEnd = true, bool $invoiceNow = false, bool $prorate = false, ?string $idempotencyKey = null): Subscription
    {
        $key = IdempotencyKey::resolve($idempotencyKey);
        $path = BillingApi::path('subscriptions', SubscriptionLocator::id($subscription));

        $response = $atPeriodEnd
            ? $this->api->post($path, ['cancel_at_period_end' => true], $key)
            : $this->api->delete($path, ['invoice_now' => $invoiceNow ?: null, 'prorate' => $prorate ?: null], $key);

        return $this->record->execute($response);
    }
}
