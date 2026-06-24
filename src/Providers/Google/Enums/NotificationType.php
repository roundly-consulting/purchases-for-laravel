<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Enums;

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
}
