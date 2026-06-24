<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\EventType;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A decoded Stripe webhook event envelope.
 *
 * @link https://docs.stripe.com/api/events/object
 */
final class StripeEvent implements FromRaw
{
    /**
     * @param  array<string, mixed>  $object  the event's data.object payload
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly EventType $type,
        public readonly array $object,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        $type = $dataset->value('type');
        $object = $dataset->value('data.object', []);

        return new self(
            id: $dataset->value('id'),
            type: EventType::fromName(is_string($type) ? $type : 'unknown'),
            object: is_array($object) ? $object : [],
            raw: $raw,
        );
    }
}
