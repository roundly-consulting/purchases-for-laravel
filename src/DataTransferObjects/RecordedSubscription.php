<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use RoundlyConsulting\Purchases\Models\Subscription;

/**
 * What recording a subscription did: the row, and whether an existing set of items changed
 * what it bills — a quantity, a price, an item added or removed.
 */
final readonly class RecordedSubscription
{
    public function __construct(
        public Subscription $subscription,
        public bool $itemsChanged = false,
    ) {}
}
