<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * How Stripe calculates a subscription's prorations and invoices (`billing_mode[type]`).
 * Flexible is what mixed intervals — an annual licensed item next to a monthly metered one —
 * need. It needs API version 2025-06-30.basil or later, and is the default for new
 * subscriptions from 2025-09-30.clover on. A subscription moves from classic to flexible,
 * never back.
 *
 * @link https://docs.stripe.com/billing/subscriptions/billing-mode
 */
enum BillingMode: string
{
    case Classic = 'classic';
    case Flexible = 'flexible';

    /** The first API version that accepts `billing_mode`. */
    public const string MINIMUM_API_VERSION = '2025-06-30.basil';
}
