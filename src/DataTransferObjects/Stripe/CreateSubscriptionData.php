<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\CollectionMethod;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentBehavior;

/**
 * A subscription to start for an owner's Stripe customer.
 *
 * Null options are not sent, so Stripe's defaults apply: no trial, automatic tax as the
 * account sets it, `charge_automatically`, `allow_incomplete`. `name` names the local
 * subscription row (what `subscribedTo()` checks); Stripe itself names no plan.
 */
final readonly class CreateSubscriptionData
{
    /**
     * @param  list<SubscriptionPrice>  $prices
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public array $prices,
        public ?string $name = null,
        public ?int $trialDays = null,
        public ?bool $automaticTax = null,
        public ?CollectionMethod $collectionMethod = null,
        public ?int $daysUntilDue = null,
        public ?PaymentBehavior $paymentBehavior = null,
        public array $metadata = [],
    ) {
        if ($prices === []) {
            throw BillingException::invalid('A subscription needs at least one price.');
        }

        if ($trialDays !== null && $trialDays < 1) {
            throw BillingException::invalid('A trial must last at least one day.');
        }

        if ($daysUntilDue !== null && $collectionMethod !== CollectionMethod::SendInvoice) {
            throw BillingException::invalid('Days until due apply only to the send_invoice collection method.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(string $customer): array
    {
        return [
            'customer' => $customer,
            'items' => array_map(static fn (SubscriptionPrice $price): array => $price->toParams(), $this->prices),
            'trial_period_days' => $this->trialDays,
            'automatic_tax' => $this->automaticTax !== null ? ['enabled' => $this->automaticTax] : null,
            'collection_method' => $this->collectionMethod?->value,
            'days_until_due' => $this->daysUntilDue,
            'payment_behavior' => $this->paymentBehavior?->value,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ];
    }
}
