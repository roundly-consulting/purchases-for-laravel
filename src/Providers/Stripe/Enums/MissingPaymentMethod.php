<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * What Stripe does with a subscription whose trial ends without a payment method
 * (`trial_settings[end_behavior][missing_payment_method]`).
 *
 * @link https://docs.stripe.com/billing/subscriptions/trials/free-trials#create-free-trials-without-payment
 */
enum MissingPaymentMethod: string
{
    /** Cancel it at once: `customer.subscription.deleted`, SubscriptionCanceled. */
    case Cancel = 'cancel';

    /** Pause it, invoicing nothing until it resumes: `customer.subscription.paused`, SubscriptionOnHold. */
    case Pause = 'pause';

    /** Invoice anyway (Stripe's default); unpaid, it goes `past_due`: SubscriptionInGracePeriod. */
    case CreateInvoice = 'create_invoice';
}
