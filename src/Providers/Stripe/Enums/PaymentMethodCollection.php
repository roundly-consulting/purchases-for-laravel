<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * Whether a subscription Checkout asks for a payment method (`payment_method_collection`).
 */
enum PaymentMethodCollection: string
{
    /** Always (Stripe's default). */
    case Always = 'always';

    /** Only when something is due now — so a free trial starts without one. */
    case IfRequired = 'if_required';
}
