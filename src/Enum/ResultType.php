<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Enum;

enum ResultType: string
{
    case Purchase = 'purchase';
    case Subscription = 'subscription';
    case Refund = 'refund';
    case Notification = 'notification';
    case Unknown = 'unknown';
}
