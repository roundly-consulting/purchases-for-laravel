<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Enums;

enum AcknowledgementState: int
{
    case YetToBeAcknowledged = 0;
    case Acknowledged = 1;

    public function isAcknowledged(): bool
    {
        return $this === self::Acknowledged;
    }
}
