<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\MeterAggregation;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A Stripe Billing Meter: the event name usage is reported under, how its events are
 * aggregated over a billing period, and which payload keys carry the customer and the value.
 *
 * @link https://docs.stripe.com/api/billing/meter/object
 */
final readonly class Meter implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $id,
        public string $eventName,
        public ?string $displayName,
        public ?MeterAggregation $aggregation,
        public bool $active,
        public string $customerPayloadKey,
        public string $valuePayloadKey,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $displayName = $dataset->value('display_name');
        $customerKey = $dataset->value('customer_mapping.event_payload_key');
        $valueKey = $dataset->value('value_settings.event_payload_key');
        $formula = $dataset->value('default_aggregation.formula');

        return new self(
            id: (string) $dataset->value('id'),
            eventName: (string) $dataset->value('event_name'),
            displayName: is_string($displayName) ? $displayName : null,
            aggregation: is_string($formula) ? MeterAggregation::tryFrom($formula) : null,
            active: $dataset->value('status') === 'active',
            customerPayloadKey: is_string($customerKey) && $customerKey !== '' ? $customerKey : 'stripe_customer_id',
            valuePayloadKey: is_string($valueKey) && $valueKey !== '' ? $valueKey : 'value',
            raw: $raw,
        );
    }
}
