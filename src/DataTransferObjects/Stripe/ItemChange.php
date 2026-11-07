<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;

/**
 * One change to one item of a Stripe subscription. Build it with a named constructor:
 * `quantity()`, `swap()`, `add()` or `remove()`. An item is a stored SubscriptionItem or its
 * Stripe id (`si_…`).
 */
final readonly class ItemChange
{
    private function __construct(
        public ?string $item,
        public ?string $price,
        public ?int $quantity,
        public bool $deleted = false,
        public bool $clearUsage = false,
    ) {
        if ($quantity !== null && $quantity < 0) {
            throw BillingException::invalid('A quantity cannot be negative.');
        }

        if ($price !== null && trim($price) === '') {
            throw BillingException::invalid('A subscription price needs a Stripe price id.');
        }
    }

    /** Set an item's quantity — e.g. the seat count. */
    public static function quantity(SubscriptionItem|string $item, int $quantity): self
    {
        return new self(self::itemId($item), null, $quantity);
    }

    /** Bill an item at another price (monthly → annual). Stripe resets the quantity to 1 unless one is given. */
    public static function swap(SubscriptionItem|string $item, string $price, ?int $quantity = null): self
    {
        return new self(self::itemId($item), $price, $quantity);
    }

    /** Add a price as a new item; a metered price takes no quantity. */
    public static function add(string $price, ?int $quantity = null): self
    {
        return new self(null, $price, $quantity);
    }

    /**
     * Remove an item. `clearUsage` drops the usage a metered item has collected this period
     * instead of billing it.
     */
    public static function remove(SubscriptionItem|string $item, bool $clearUsage = false): self
    {
        return new self(self::itemId($item), null, null, deleted: true, clearUsage: $clearUsage);
    }

    /**
     * Stripe's `items[n]` parameters.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return [
            'id' => $this->item,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'deleted' => $this->deleted ? true : null,
            'clear_usage' => $this->clearUsage ? true : null,
        ];
    }

    private static function itemId(SubscriptionItem|string $item): string
    {
        $id = $item instanceof SubscriptionItem ? $item->provider_id : $item;

        if ($id === null || trim($id) === '') {
            throw BillingException::invalid('The subscription item has no Stripe id.');
        }

        return $id;
    }
}
