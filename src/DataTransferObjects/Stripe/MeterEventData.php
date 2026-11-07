<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects\Stripe;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use RoundlyConsulting\Purchases\Exceptions\BillingException;

/**
 * One usage event for a meter: the meter's event name, the Stripe customer it bills, the
 * value, when it happened and an identifier.
 *
 * The identifier makes the report idempotent — Stripe drops a second event with the same
 * identifier for at least 24 hours — so it is generated here, once, and survives a queued
 * retry. Pass your own (`storage-{$company->id}-2026-11-07`) to make a whole re-run safe.
 *
 * The value is sent as a decimal string, so a float never loses digits on the way: Stripe
 * accepts decimal values. Stripe takes a timestamp up to 35 days in the past and 5 minutes
 * in the future.
 */
final readonly class MeterEventData
{
    public string $value;

    public string $identifier;

    public function __construct(
        public string $eventName,
        public string $customer,
        int|float|string $value,
        public ?CarbonInterface $timestamp = null,
        ?string $identifier = null,
        public string $customerPayloadKey = 'stripe_customer_id',
        public string $valuePayloadKey = 'value',
    ) {
        if (trim($eventName) === '' || trim($customer) === '') {
            throw BillingException::invalid('A meter event needs an event name and a Stripe customer.');
        }

        $identifier ??= (string) Str::uuid();

        if (trim($identifier) === '' || strlen($identifier) > 100) {
            throw BillingException::invalid('A meter event identifier must be 1 to 100 characters long.');
        }

        $this->value = self::decimal($value);
        $this->identifier = $identifier;
    }

    /**
     * @return array<string, mixed>
     */
    public function toParams(): array
    {
        return [
            'event_name' => $this->eventName,
            'payload' => [
                $this->customerPayloadKey => $this->customer,
                $this->valuePayloadKey => $this->value,
            ],
            'identifier' => $this->identifier,
            'timestamp' => $this->timestamp?->getTimestamp(),
        ];
    }

    private static function decimal(int|float|string $value): string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw BillingException::invalid('A meter event value must be a finite number.');
            }

            $value = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');
        }

        $value = (string) $value;

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw BillingException::invalid('A meter event value must be a number.');
        }

        return $value === '-0' ? '0' : $value;
    }
}
