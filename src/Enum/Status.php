<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Enum;

use RoundlyConsulting\Enums\Helpers;

enum Status: string
{
    use Helpers;

    case New = 'new';
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case InGracePeriod = 'in_grace';
    case OnHold = 'on_hold';
    case Refunded = 'refunded';

    /**
     * Whether this status represents an entitlement the customer should still hold.
     *
     * Active subscriptions and those in a billing-retry grace period keep access;
     * an account-hold, refund, cancellation, or failure does not.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Completed, self::InGracePeriod => true,
            default => false,
        };
    }
}
