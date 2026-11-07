<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Billing;

use Carbon\CarbonImmutable;

/**
 * A successful Stripe API answer: the decoded object, and when Stripe produced it.
 *
 * @internal
 */
final readonly class BillingResponse
{
    /**
     * @param  array<string, mixed>  $body
     * @param  CarbonImmutable|null  $date  Stripe's `Date` header — Stripe's clock, the one its
     *                                      webhook events are stamped with
     */
    public function __construct(
        public array $body,
        public ?CarbonImmutable $date = null,
    ) {}
}
