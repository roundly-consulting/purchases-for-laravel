<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * Stripe's helpers for a cancellation date (`cancel_at`) taken from the subscription's own
 * billing periods — for a flexible-billing subscription whose items renew at different times,
 * where `cancel_at_period_end` would end it at the earliest item period end. Stripe resolves
 * the helper to a timestamp at once. Only a subscription in flexible billing mode takes them.
 *
 * @link https://docs.stripe.com/billing/subscriptions/cancel#custom-cancel-date
 */
enum CancelAt: string
{
    /** The latest end among the items' current billing periods — every item runs out its paid time. */
    case MaxPeriodEnd = 'max_period_end';

    /** The earliest end among the items' current billing periods. */
    case MinPeriodEnd = 'min_period_end';
}
