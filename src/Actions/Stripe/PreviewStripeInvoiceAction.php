<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\SubscriptionChange;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\SubscriptionLocator;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\InvoicePreview;

/**
 * Previews the next invoice of a Stripe subscription — with a planned change applied, when
 * one is given — through `POST /v1/invoices/create_preview` (the replacement for the
 * upcoming-invoice endpoint Stripe removed in API version 2025-03-31). It changes nothing,
 * so it takes no idempotency key.
 */
final readonly class PreviewStripeInvoiceAction
{
    public function __construct(
        private BillingApi $api,
    ) {}

    public function execute(Subscription|string $subscription, ?SubscriptionChange $change = null): InvoicePreview
    {
        $params = ['subscription' => SubscriptionLocator::id($subscription)];

        if ($change !== null) {
            $params['subscription_details'] = $change->toPreviewParams();
        }

        return InvoicePreview::fromRaw($this->api->post('/invoices/create_preview', $params, null)->body);
    }
}
