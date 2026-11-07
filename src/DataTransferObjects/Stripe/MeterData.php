<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\MeterAggregation;

/**
 * A Stripe Billing Meter to create. Stripe allows no change to a meter but its display name
 * once it exists, so choose the aggregation with care.
 *
 * The payload keys default to Stripe's (`stripe_customer_id`, `value`).
 */
final readonly class MeterData
{
    public function __construct(
        public string $eventName,
        public string $displayName,
        public MeterAggregation $aggregation = MeterAggregation::Sum,
        public string $customerPayloadKey = 'stripe_customer_id',
        public string $valuePayloadKey = 'value',
    ) {
        if (trim($eventName) === '' || strlen($eventName) > 100) {
            throw BillingException::invalid('A meter event name must be 1 to 100 characters long.');
        }

        if (trim($displayName) === '' || strlen($displayName) > 250) {
            throw BillingException::invalid('A meter display name must be 1 to 250 characters long.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return [
            'display_name' => $this->displayName,
            'event_name' => $this->eventName,
            'default_aggregation' => ['formula' => $this->aggregation->value],
            'customer_mapping' => ['type' => 'by_id', 'event_payload_key' => $this->customerPayloadKey],
            // `count` reads no value, so a count meter is sent without one.
            'value_settings' => $this->aggregation === MeterAggregation::Count ? null : ['event_payload_key' => $this->valuePayloadKey],
        ];
    }
}
