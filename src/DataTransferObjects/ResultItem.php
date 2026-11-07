<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use RoundlyConsulting\Money\Money;

/**
 * A single line item belonging to a provider result.
 *
 * `metered` marks a usage-based item (a Stripe metered price), which has no quantity: it is
 * stored with a null `quantity`. `priceId` is the provider's price the item bills (a Stripe
 * `price_…`), when it names one.
 */
final readonly class ResultItem
{
    public function __construct(
        public string $name,
        public ?string $providerId = null,
        public ?Money $price = null,
        public int $quantity = 1,
        public bool $metered = false,
        public ?string $priceId = null,
    ) {}
}
