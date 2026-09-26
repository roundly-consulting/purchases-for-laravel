<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

use RoundlyConsulting\Purchases\Enum\Status;

/**
 * App Store Server Notifications V2 `notificationType` values.
 *
 * @link https://developer.apple.com/documentation/appstoreservernotifications/notificationtype
 */
enum NotificationType: string
{
    case TypeConsumptionRequest = 'CONSUMPTION_REQUEST';
    case TypeDidChangeRenewalPref = 'DID_CHANGE_RENEWAL_PREF';
    case TypeDidChangeRenewalStatus = 'DID_CHANGE_RENEWAL_STATUS';
    case TypeDidFailToRenew = 'DID_FAIL_TO_RENEW';
    case TypeDidRenew = 'DID_RENEW';
    case TypeExpired = 'EXPIRED';
    case TypeExternalPurchaseToken = 'EXTERNAL_PURCHASE_TOKEN';
    case TypeGracePeriodExpired = 'GRACE_PERIOD_EXPIRED';
    case TypeMetadataUpdate = 'METADATA_UPDATE';
    case TypeMigration = 'MIGRATION';
    case TypeOfferRedeemed = 'OFFER_REDEEMED';
    case TypeOneTimeCharge = 'ONE_TIME_CHARGE';
    case TypePriceChange = 'PRICE_CHANGE';
    case TypePriceIncrease = 'PRICE_INCREASE';
    case TypeRefund = 'REFUND';
    case TypeRefundDeclined = 'REFUND_DECLINED';
    case TypeRefundReversed = 'REFUND_REVERSED';
    case TypeRenewalExtended = 'RENEWAL_EXTENDED';
    case TypeRenewalExtension = 'RENEWAL_EXTENSION';
    case TypeRescindConsent = 'RESCIND_CONSENT';
    case TypeRevoke = 'REVOKE';
    case TypeSubscribed = 'SUBSCRIBED';
    case TypeTest = 'TEST';

    /**
     * A type Apple added after this version: parsed instead of rejected, so a new
     * notification never fails the webhook. Apple never sends this value itself.
     */
    case Unknown = 'UNKNOWN';

    /**
     * Map an App Store notification type onto the package's normalized status.
     */
    public function status(): Status
    {
        return match ($this) {
            self::TypeSubscribed,
            self::TypeDidRenew,
            self::TypeOfferRedeemed,
            self::TypeOneTimeCharge,
            self::TypeRefundReversed,
            self::TypeRenewalExtended => Status::Completed,
            self::TypeDidFailToRenew => Status::InGracePeriod,
            // Billing retry: access stops, but Apple keeps retrying for 60 days and a
            // DID_RENEW (BILLING_RECOVERY) restores it — held, not expired.
            self::TypeGracePeriodExpired => Status::OnHold,
            self::TypeExpired => Status::Failed,
            self::TypeRefund,
            self::TypeRevoke => Status::Refunded,
            default => Status::Processing,
        };
    }

    /**
     * Whether this notification only reports something — a renewal preference or status
     * change, a price increase or change, a consumption request, a declined refund, a
     * test — without changing what the customer is entitled to. Such a notification is
     * audited but never applied to a purchase or subscription. (A DID_CHANGE_RENEWAL_PREF
     * UPGRADE takes effect immediately and is the exception; the provider checks it.)
     */
    public function isInformational(): bool
    {
        return match ($this) {
            self::TypeConsumptionRequest,
            self::TypeDidChangeRenewalPref,
            self::TypeDidChangeRenewalStatus,
            self::TypeExternalPurchaseToken,
            self::TypeMetadataUpdate,
            self::TypeMigration,
            self::TypePriceChange,
            self::TypePriceIncrease,
            self::TypeRefundDeclined,
            self::TypeRenewalExtension,
            self::TypeRescindConsent,
            self::TypeTest,
            self::Unknown => true,
            default => false,
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
