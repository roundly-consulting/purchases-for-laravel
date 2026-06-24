<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum RetryPeriod: int
{
    case AttemptingToRenew = 1;
    case StoppedAttempting = 0;
}
