<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum OfferType: int
{
    case Introductory = 1;
    case Promotional = 2;
    case SubscriptionOfferCode = 3;
}
