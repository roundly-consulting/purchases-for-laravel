<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Enums;

enum ConsumptionState: int
{
    case YetToBeConsumed = 0;
    case Consumed = 1;
}
