<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use Carbon\CarbonInterface;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentBehavior;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\ProrationBehavior;

/**
 * A change to a Stripe subscription's items, and how it is billed. The same change can be
 * previewed (`subscriptions()->preview()`) and then applied (`subscriptions()->change()`):
 * pass the same `prorationDate` to both and the applied proration matches the preview.
 */
final readonly class SubscriptionChange
{
    /**
     * @param  list<ItemChange>  $items
     */
    public function __construct(
        public array $items,
        public ProrationBehavior $proration = ProrationBehavior::CreateProrations,
        public ?CarbonInterface $prorationDate = null,
        public ?PaymentBehavior $paymentBehavior = null,
    ) {
        if ($items === []) {
            throw BillingException::invalid('A subscription change needs at least one item change.');
        }
    }

    /**
     * Parameters for `POST /v1/subscriptions/{id}`.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return [
            'items' => $this->itemParams(),
            'proration_behavior' => $this->proration->value,
            'proration_date' => $this->prorationDate?->getTimestamp(),
            'payment_behavior' => $this->paymentBehavior?->value,
        ];
    }

    /**
     * Parameters for `subscription_details[…]` of `POST /v1/invoices/create_preview`.
     *
     * @return array<string, mixed>
     */
    public function toPreviewParams(): array
    {
        return [
            'items' => $this->itemParams(),
            'proration_behavior' => $this->proration->value,
            'proration_date' => $this->prorationDate?->getTimestamp(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemParams(): array
    {
        return array_map(static fn (ItemChange $item): array => $item->toParams(), $this->items);
    }
}
