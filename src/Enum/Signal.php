<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Enum;

use RoundlyConsulting\Enums\Helpers;

/**
 * A store event that announces something without being a state change of its own — it rides
 * on a result next to whatever that result records, and fires its own package event.
 */
enum Signal: string
{
    use Helpers;

    /** A trial ends soon (Stripe `customer.subscription.trial_will_end`): SubscriptionTrialWillEnd. */
    case TrialWillEnd = 'trial_will_end';

    /** A payment waits for the customer, e.g. 3-D Secure (Stripe `invoice.payment_action_required`): PaymentActionRequired. */
    case PaymentActionRequired = 'payment_action_required';
}
