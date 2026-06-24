<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum AutoRenewStatus: int
{
    case Active = 1;
    case Inactive = 0;
}
