<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum PriceIncreaseConsent: int
{
    case Accepted = 1;
    case NoResponse = 0;
}
