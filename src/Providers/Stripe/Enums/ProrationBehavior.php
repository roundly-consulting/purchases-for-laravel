<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * How Stripe bills a mid-period change of a subscription (`proration_behavior`).
 *
 * @link https://docs.stripe.com/api/subscriptions/update
 */
enum ProrationBehavior: string
{
    /** Prorate the change and add it to the next invoice (Stripe's default). */
    case CreateProrations = 'create_prorations';

    /** Prorate the change and invoice it right away. */
    case AlwaysInvoice = 'always_invoice';

    /** Bill nothing for the change. */
    case None = 'none';
}
