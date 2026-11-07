<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Exceptions\BillingException;

/**
 * A hosted Stripe Checkout page that starts a subscription for an owner's Stripe customer —
 * for when Stripe should collect the payment details.
 *
 * Stripe appends nothing to `successUrl` on its own: put `{CHECKOUT_SESSION_ID}` in it to get
 * the session id back. With automatic tax on, Checkout saves the address it collects to the
 * customer, which Stripe Tax needs.
 */
final readonly class CheckoutSubscriptionData
{
    /**
     * @param  list<SubscriptionPrice>  $prices
     * @param  array<string, string>  $metadata  set on the subscription Checkout creates
     */
    public function __construct(
        public array $prices,
        public string $successUrl,
        public ?string $cancelUrl = null,
        public ?int $trialDays = null,
        public ?bool $automaticTax = null,
        public array $metadata = [],
        public ?string $clientReferenceId = null,
    ) {
        if ($prices === []) {
            throw BillingException::invalid('A subscription needs at least one price.');
        }

        if ($trialDays !== null && $trialDays < 1) {
            throw BillingException::invalid('A trial must last at least one day.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(string $customer): array
    {
        return [
            'mode' => 'subscription',
            'customer' => $customer,
            'line_items' => array_map(static fn (SubscriptionPrice $price): array => [
                'price' => $price->price,
                'quantity' => $price->quantity,
            ], $this->prices),
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'client_reference_id' => $this->clientReferenceId,
            'automatic_tax' => $this->automaticTax !== null ? ['enabled' => $this->automaticTax] : null,
            'customer_update' => $this->automaticTax === true ? ['address' => 'auto'] : null,
            'subscription_data' => array_filter([
                'trial_period_days' => $this->trialDays,
                'metadata' => $this->metadata === [] ? null : $this->metadata,
            ], static fn (mixed $value): bool => $value !== null) ?: null,
        ];
    }
}
