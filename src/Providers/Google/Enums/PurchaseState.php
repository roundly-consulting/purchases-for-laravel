<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Enums;

enum PurchaseState: int
{
    case Purchased = 0;
    case Canceled = 1;
    case Pending = 2;

    public function isPurchased(): bool
    {
        return $this === self::Purchased;
    }
}
