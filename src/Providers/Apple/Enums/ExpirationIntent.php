<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum ExpirationIntent: int
{
    case VoluntaryCancel = 1;
    case BillingError = 2;
    case DidNotAgreePriceInrease = 3;
    case ProductUnavailable = 4;
    case UnknownError = 5;
}
