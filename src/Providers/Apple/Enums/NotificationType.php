<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

use RoundlyConsulting\Purchases\Enum\Status;

enum NotificationType: string
{
    case TypeConsumptionRequest = 'CONSUMPTION_REQUEST';
    case TypeDidChangeRenewalPref = 'DID_CHANGE_RENEWAL_PREF';
    case TypeDidChangeRenewalStatus = 'DID_CHANGE_RENEWAL_STATUS';
    case TypeDidFailToRenew = 'DID_FAIL_TO_RENEW';
    case TypeDidRenew = 'DID_RENEW';
    case TypeExpired = 'EXPIRED';
    case TypeGracePeriodExpired = 'GRACE_PERIOD_EXPIRED';
    case TypeOfferRedeemed = 'OFFER_REDEEMED';
    case TypePriceIncrease = 'PRICE_INCREASE';
    case TypeRefund = 'REFUND';
    case TypeRefundDeclined = 'REFUND_DECLINED';
    case TypeRenewalExtended = 'RENEWAL_EXTENDED';
    case TypeRevoke = 'REVOKE';
    case TypeSubscribed = 'SUBSCRIBED';
    case TypeTest = 'TEST';

    /**
     * Map an App Store notification type onto the package's normalized status.
     */
    public function status(): Status
    {
        return match ($this) {
            self::TypeSubscribed,
            self::TypeDidRenew,
            self::TypeOfferRedeemed,
            self::TypeRenewalExtended => Status::Completed,
            self::TypeDidFailToRenew => Status::InGracePeriod,
            self::TypeRefundDeclined => Status::Failed,
            self::TypeExpired,
            self::TypeGracePeriodExpired => Status::Failed,
            self::TypeRefund,
            self::TypeRevoke => Status::Refunded,
            default => Status::Processing,
        };
    }

    /**
     * Whether this notification represents a refund or revocation.
     */
    public function isRefund(): bool
    {
        return match ($this) {
            self::TypeRefund, self::TypeRevoke => true,
            default => false,
        };
    }
}
