<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

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
}
