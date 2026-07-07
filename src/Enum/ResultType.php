<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Enum;

use RoundlyConsulting\Enums\Helpers;

enum ResultType: string
{
    use Helpers;

    case Purchase = 'purchase';
    case Subscription = 'subscription';
    case Refund = 'refund';
    case Notification = 'notification';
    case Unknown = 'unknown';
}
