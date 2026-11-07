<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * How Stripe collects a subscription's invoices (`collection_method`).
 */
enum CollectionMethod: string
{
    /** Charge the customer's default payment method (Stripe's default). */
    case ChargeAutomatically = 'charge_automatically';

    /** Email the invoice, payable within `days_until_due`. */
    case SendInvoice = 'send_invoice';
}
