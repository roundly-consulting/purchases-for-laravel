<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;

final class PurchaseRefunded
{
    use Dispatchable;

    public function __construct(
        public readonly PurchaseRefund $refund,
        public readonly ProviderResult $result,
    ) {}
}
