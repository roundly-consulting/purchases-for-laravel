<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

use RoundlyConsulting\Purchases\Enum\Status;

enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case Incomplete = 'incomplete';
    case IncompleteExpired = 'incomplete_expired';
    case PastDue = 'past_due';
    case Canceled = 'canceled';
    case Unpaid = 'unpaid';
    case Paused = 'paused';

    public function status(): Status
    {
        return match ($this) {
            self::Trialing, self::Active => Status::Completed,
            self::PastDue => Status::InGracePeriod,
            self::Incomplete => Status::Pending,
            self::Paused => Status::Processing,
            self::Canceled, self::IncompleteExpired => Status::Canceled,
            self::Unpaid => Status::Failed,
        };
    }
}
