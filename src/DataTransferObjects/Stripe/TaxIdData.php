<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Exceptions\BillingException;

/**
 * A tax id to give a Stripe customer: Stripe's type (`eu_vat`, `gb_vat`, `us_ein`, …) and the
 * number, e.g. `new TaxIdData('eu_vat', 'DE123456789')`.
 *
 * @link https://docs.stripe.com/api/customer_tax_ids/create
 */
final readonly class TaxIdData
{
    public function __construct(
        public string $type,
        public string $value,
    ) {
        if (trim($type) === '' || trim($value) === '') {
            throw BillingException::invalid('A tax id needs a type and a value.');
        }
    }

    /**
     * Whether a tax id Stripe holds is this one. Stripe stores the number as given; spaces and
     * letter case do not make it another number.
     */
    public function matches(string $type, string $value): bool
    {
        return $type === $this->type && self::normalized($value) === self::normalized($this->value);
    }

    private static function normalized(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
