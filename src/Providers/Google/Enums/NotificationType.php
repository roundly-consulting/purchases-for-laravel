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
    case ItemsChanged = 17;
    case CancellationScheduled = 18;
    case PriceChangeUpdated = 19;
    case PendingPurchaseCanceled = 20;
    case PriceStepUpConsentUpdated = 22;

    /**
     * Map a developer notification type onto the package's normalized status.
     *
     * SUBSCRIPTION_CANCELED means the customer turned auto-renew off: the period they paid
     * for still runs, so it stays Completed and its expiry ends it. A canceled PENDING
     * purchase was never paid for.
     */
    public function status(): Status
    {
        return match ($this) {
            self::Recovered, self::Renewed, self::Purchased, self::Restarted, self::Canceled => Status::Completed,
            self::InGracePeriod => Status::InGracePeriod,
            self::OnHold, self::Paused => Status::OnHold,
            self::PendingPurchaseCanceled => Status::Canceled,
            self::Revoked => Status::Refunded,
            self::Expired => Status::Failed,
            self::Deferred,
            self::PriceChangeConfirmed,
            self::PauseScheduleChanged,
            self::ItemsChanged,
            self::CancellationScheduled,
            self::PriceChangeUpdated,
            self::PriceStepUpConsentUpdated => Status::Processing,
        };
    }

    /**
     * Whether this notification only reports something — a deferral, a price change or
     * consent update, a pause schedule, a changed bundle item, a scheduled cancellation —
     * without changing what the customer is entitled to now. Such a notification is
     * audited but never applied to a subscription.
     */
    public function isInformational(): bool
    {
        return match ($this) {
            self::Deferred,
            self::PriceChangeConfirmed,
            self::PauseScheduleChanged,
            self::ItemsChanged,
            self::CancellationScheduled,
            self::PriceChangeUpdated,
            self::PriceStepUpConsentUpdated => true,
            default => false,
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
