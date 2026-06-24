<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\Enums\OfferType;
use RoundlyConsulting\Purchases\Support\DataSet;

final class Offer extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $offerIdentifier,
        public readonly ?OfferType $offerType,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            offerIdentifier: $dataset->value('offerIdentifier'),
            offerType: $dataset->enum('offerType', OfferType::class),
            raw: $dataset->retrieved(),
        );
    }
}
