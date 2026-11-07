<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * What Stripe does when a subscription change needs a payment it cannot take right away
 * (`payment_behavior`). Leave it unset for Stripe's default, `allow_incomplete`.
 */
enum PaymentBehavior: string
{
    case AllowIncomplete = 'allow_incomplete';
    case DefaultIncomplete = 'default_incomplete';
    case ErrorIfIncomplete = 'error_if_incomplete';

    /** Update only: keep the change pending until it is paid. */
    case PendingIfIncomplete = 'pending_if_incomplete';
}
