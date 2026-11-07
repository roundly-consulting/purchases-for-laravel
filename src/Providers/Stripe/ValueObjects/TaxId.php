<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

/**
 * A customer's tax id at Stripe (an EU VAT number: type `eu_vat`, value `DE123456789`).
 */
final readonly class TaxId
{
    public function __construct(
        public string $id,
        public string $type,
        public string $value,
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw
     */
    public static function tryFromRaw(array $raw): ?self
    {
        $id = $raw['id'] ?? null;
        $type = $raw['type'] ?? null;
        $value = $raw['value'] ?? null;

        if (! is_string($id) || ! is_string($type) || ! is_string($value)) {
            return null;
        }

        return new self($id, $type, $value);
    }
}
