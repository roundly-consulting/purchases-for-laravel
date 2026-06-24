<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum RevocationReason: int
{
    case Other = 0;
    case AppIssue = 1;
}
