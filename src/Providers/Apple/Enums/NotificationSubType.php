<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\Enums;

enum NotificationSubType: string
{
    case SubtypeInitialBuy = 'INITIAL_BUY';
    case SubtypeResubscribe = 'RESUBSCRIBE';
    case SubtypeDowngrade = 'DOWNGRADE';
    case SubtypeUpgrade = 'UPGRADE';
    case SubtypeAutoRenewEnabled = 'AUTO_RENEW_ENABLED';
    case SubtypeAutoRenewDisabled = 'AUTO_RENEW_DISABLED';
    case SubtypeVoluntary = 'VOLUNTARY';
    case SubtypeBillingRetry = 'BILLING_RETRY';
    case SubtypePriceIncrease = 'PRICE_INCREASE';
    case SubtypeGracePeriod = 'GRACE_PERIOD';
    case SubtypeBillingRecovery = 'BILLING_RECOVERY';
    case SubtypePending = 'PENDING';
    case SubtypeAccepted = 'ACCEPTED';
}
