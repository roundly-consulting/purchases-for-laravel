<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Exceptions\BillingException;

/**
 * One price on a subscription: a licensed price with its quantity (`price_seats`, 10 seats),
 * or a metered price with none (`price_storage_gb`) — Stripe bills a metered price from its
 * meter, so it takes no quantity.
 */
final readonly class SubscriptionPrice
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $price,
        public ?int $quantity = null,
        public array $metadata = [],
    ) {
        if (trim($price) === '') {
            throw BillingException::invalid('A subscription price needs a Stripe price id.');
        }

        if ($quantity !== null && $quantity < 0) {
            throw BillingException::invalid('A quantity cannot be negative.');
        }
    }

    public static function licensed(string $price, int $quantity = 1): self
    {
        return new self($price, $quantity);
    }

    public static function metered(string $price): self
    {
        return new self($price);
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return [
            'price' => $this->price,
            'quantity' => $this->quantity,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
        ];
    }
}
