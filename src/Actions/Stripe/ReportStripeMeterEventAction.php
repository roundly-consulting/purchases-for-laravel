<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\MeterEventData;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\MeterEvent;

/**
 * Reports one usage event to a Stripe Billing Meter (`POST /v1/billing/meter_events`). The
 * event's identifier is also its idempotency key, so a retry — of the call or of the queued
 * job — is never counted twice.
 */
final readonly class ReportStripeMeterEventAction
{
    public function __construct(
        private BillingApi $api,
    ) {}

    public function execute(MeterEventData $event): MeterEvent
    {
        return MeterEvent::fromRaw($this->api->post('/billing/meter_events', $event->toParams(), $event->identifier)->body);
    }
}
