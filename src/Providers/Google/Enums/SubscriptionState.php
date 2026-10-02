<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\Enums;

use RoundlyConsulting\Purchases\Enum\Status;

/**
 * Subscription states returned by the Play Developer API subscriptionsv2 endpoint.
 *
 * @link https://developer.android.com/google/play/billing/rtdn-reference#sub-states
 */
enum SubscriptionState: string
{
    case Unspecified = 'SUBSCRIPTION_STATE_UNSPECIFIED';
    case Pending = 'SUBSCRIPTION_STATE_PENDING';
    case Active = 'SUBSCRIPTION_STATE_ACTIVE';
    case Paused = 'SUBSCRIPTION_STATE_PAUSED';
    case InGracePeriod = 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD';
    case OnHold = 'SUBSCRIPTION_STATE_ON_HOLD';
    case Canceled = 'SUBSCRIPTION_STATE_CANCELED';
    case Expired = 'SUBSCRIPTION_STATE_EXPIRED';

    public function isActive(): bool
    {
        return match ($this) {
            self::Active, self::InGracePeriod => true,
            default => false,
        };
    }

    /**
     * Whether the subscription is over for good. CANCELED is not: it means auto-renew is
     * off, and the customer keeps access until the expiry (see SubscriptionPurchase).
     */
    public function isTerminal(): bool
    {
        return $this === self::Expired;
    }

    /**
     * The state on its own. A CANCELED subscription with paid time left is still active —
     * SubscriptionPurchase::status(), which knows the expiry, reads it as Completed.
     */
    public function status(): Status
    {
        return match ($this) {
            self::Active => Status::Completed,
            self::InGracePeriod => Status::InGracePeriod,
            self::Pending => Status::Pending,
            self::OnHold => Status::OnHold,
            self::Paused => Status::Processing,
            self::Canceled => Status::Canceled,
            self::Expired => Status::Failed,
            self::Unspecified => Status::Processing,
        };
    }
}
