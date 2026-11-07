<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

/**
 * What a Stripe customer should hold. Every field is optional: a null field is left as it is
 * at Stripe. `taxIds` null leaves the customer's tax ids alone; a list makes them exactly
 * that list (an empty list removes them all). Metadata keys are merged into the customer's —
 * send a key with an empty string to remove it.
 */
final readonly class CustomerData
{
    /**
     * @param  list<TaxIdData>|null  $taxIds
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public ?string $name = null,
        public ?string $email = null,
        public ?AddressData $address = null,
        public ?array $taxIds = null,
        public array $metadata = [],
        public ?string $preferredLocale = null,
    ) {}

    /**
     * Stripe's customer parameters, without the tax ids.
     *
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'address' => $this->address?->toParams(),
            'metadata' => $this->metadata === [] ? null : $this->metadata,
            'preferred_locales' => $this->preferredLocale !== null ? [$this->preferredLocale] : null,
        ];
    }
}
