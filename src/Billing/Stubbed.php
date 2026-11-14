<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Billing;

/**
 * A canned answer `Purchases::fake()` gives a Stripe read instead of asking Stripe — null
 * included, which is why the answer travels wrapped.
 *
 * @internal
 */
final readonly class Stubbed
{
    public function __construct(
        public mixed $value,
    ) {}
}
