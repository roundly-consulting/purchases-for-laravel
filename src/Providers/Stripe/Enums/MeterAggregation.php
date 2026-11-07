<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Enums;

/**
 * How a Stripe Billing Meter aggregates its events over a billing period
 * (`default_aggregation.formula`). These are the three formulas Stripe supports; there is no
 * average — see `describe()` for billing one.
 *
 * @link https://docs.stripe.com/api/billing/meter/create
 */
enum MeterAggregation: string
{
    /** Bill the number of events. */
    case Count = 'count';

    /** Bill the sum of the events' values. */
    case Sum = 'sum';

    /** Bill the last event's value in the period. */
    case Last = 'last';

    /**
     * What the customer is billed for, in words.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Count => 'the number of events in the billing period',
            self::Sum => 'the sum of the event values in the billing period',
            self::Last => 'the value of the last event in the billing period',
        };
    }
}
