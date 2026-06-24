<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * A single line item belonging to a provider result.
 */
final readonly class ResultItem
{
    public function __construct(
        public string $name,
        public ?string $providerId = null,
        public ?Money $price = null,
        public int $quantity = 1,
    ) {}
}
