<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * Whether the payment method that pays a subscription's invoice becomes the subscription's
 * default (`payment_settings[save_default_payment_method]`).
 *
 * @link https://docs.stripe.com/api/subscriptions/create#create_subscription-payment_settings-save_default_payment_method
 */
enum SaveDefaultPaymentMethod: string
{
    /** Save the method the first invoice is paid with as the subscription's default — later invoices charge it. */
    case OnSubscription = 'on_subscription';

    /** Save nothing (Stripe's default). */
    case Off = 'off';
}
