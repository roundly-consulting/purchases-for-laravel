<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\DataTransferObjects\Stripe\MeterData;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Meter;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Creates a Stripe Billing Meter. Attach it to a metered price (in the Dashboard or the
 * Prices API, `recurring[meter]`), then add that price to a subscription.
 */
final readonly class CreateStripeMeterAction
{
    public function __construct(
        private BillingApi $api,
    ) {}

    public function execute(MeterData $data, ?string $idempotencyKey = null): Meter
    {
        return Meter::fromRaw($this->api->post('/billing/meters', $data->toParams(), IdempotencyKey::resolve($idempotencyKey))->body);
    }
}
