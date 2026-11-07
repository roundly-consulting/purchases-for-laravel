<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A Stripe customer, as `Purchases::stripe()->customers()->sync()` left it.
 *
 * @link https://docs.stripe.com/api/customers/object
 */
final readonly class Customer implements FromRaw
{
    /**
     * @param  list<TaxId>  $taxIds
     * @param  array<string, string>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $email,
        public array $taxIds,
        public array $metadata,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $taxIds = [];

        foreach ((array) $dataset->value('tax_ids.data', []) as $taxId) {
            $taxId = is_array($taxId) ? TaxId::tryFromRaw($taxId) : null;

            if ($taxId !== null) {
                $taxIds[] = $taxId;
            }
        }

        return new self(
            id: (string) $dataset->value('id'),
            name: self::string($dataset->value('name')),
            email: self::string($dataset->value('email')),
            taxIds: $taxIds,
            metadata: array_filter((array) $dataset->value('metadata', []), is_string(...)),
            raw: $raw,
        );
    }

    /**
     * The same customer holding these tax ids.
     *
     * @param  list<TaxId>  $taxIds
     */
    public function withTaxIds(array $taxIds): self
    {
        return new self($this->id, $this->name, $this->email, $taxIds, $this->metadata, $this->raw);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
