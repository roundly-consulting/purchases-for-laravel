<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Enums;

use RoundlyConsulting\Purchases\Enum\Status;

/**
 * Real-time Developer Notification subscription notification types.
 *
 * @link https://developer.android.com/google/play/billing/rtdn-reference#sub
 */
enum NotificationType: int
{
    case Recovered = 1;
    case Renewed = 2;
    case Canceled = 3;
    case Purchased = 4;
    case OnHold = 5;
    case InGracePeriod = 6;
    case Restarted = 7;
    case PriceChangeConfirmed = 8;
    case Deferred = 9;
    case Paused = 10;
    case PauseScheduleChanged = 11;
    case Revoked = 12;
    case Expired = 13;
    case PendingPurchaseCanceled = 20;

    /**
     * Map a developer notification type onto the package's normalized status.
     */
    public function status(): Status
    {
        return match ($this) {
            self::Recovered, self::Renewed, self::Purchased, self::Restarted => Status::Completed,
            self::InGracePeriod => Status::InGracePeriod,
            self::OnHold, self::Paused => Status::OnHold,
            self::Canceled, self::PendingPurchaseCanceled => Status::Canceled,
            self::Revoked => Status::Refunded,
            self::Expired => Status::Failed,
            self::Deferred, self::PriceChangeConfirmed, self::PauseScheduleChanged => Status::Processing,
        };
    }

    /**
     * Whether this notification revokes/voids an existing purchase.
     */
    public function isRefund(): bool
    {
        return $this === self::Revoked;
    }
}
