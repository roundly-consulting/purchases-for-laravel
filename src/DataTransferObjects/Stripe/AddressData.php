<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

/**
 * A customer's billing address. Stripe Tax needs at least the country (and the postal code in
 * some countries, e.g. the US and Canada) to calculate tax for the customer.
 */
final readonly class AddressData
{
    /**
     * @param  string|null  $country  two-letter ISO 3166-1 code, e.g. `DE`
     */
    public function __construct(
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
    ) {}

    /**
     * Stripe's `address[…]` parameters. A field left null is not sent, so an update leaves it
     * as it is.
     *
     * @return array<string, string|null>
     */
    public function toParams(): array
    {
        return [
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postalCode,
            'country' => $this->country !== null ? strtoupper($this->country) : null,
        ];
    }
}
