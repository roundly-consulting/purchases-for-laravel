<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\Purchase;

final class PurchaseCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Purchase $purchase,
        public readonly ProviderResult $result,
    ) {}
}
