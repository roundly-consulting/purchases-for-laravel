<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A usage event Stripe accepted for a meter. Stripe aggregates it asynchronously: the
 * answer confirms receipt, not the billed total.
 *
 * @link https://docs.stripe.com/api/billing/meter-event/object
 */
final readonly class MeterEvent implements FromRaw
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $eventName,
        public ?string $identifier,
        public array $payload,
        public ?Carbon $timestamp,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $identifier = $dataset->value('identifier');
        $timestamp = $dataset->value('timestamp');
        /** @var array<string, mixed> $payload */
        $payload = (array) $dataset->value('payload', []);

        return new self(
            eventName: (string) $dataset->value('event_name'),
            identifier: is_string($identifier) ? $identifier : null,
            payload: $payload,
            timestamp: is_int($timestamp) ? Carbon::createFromTimestamp($timestamp) : null,
            raw: $raw,
        );
    }
}
