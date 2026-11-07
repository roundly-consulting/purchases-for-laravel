<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\Meter;

/**
 * Finds the active Stripe Billing Meter for an event name, or null. Stripe's meter list
 * takes no event-name filter, so the active meters are paged through (100 at a time).
 */
final readonly class FindStripeMeterAction
{
    public function __construct(
        private BillingApi $api,
    ) {}

    public function execute(string $eventName): ?Meter
    {
        $after = null;

        do {
            $page = $this->api->get('/billing/meters', ['status' => 'active', 'limit' => 100, 'starting_after' => $after])->body;
            $meters = is_array($page['data'] ?? null) ? $page['data'] : [];

            foreach ($meters as $meter) {
                if (! is_array($meter)) {
                    continue;
                }

                /** @var array<string, mixed> $meter */
                if (($meter['event_name'] ?? null) === $eventName) {
                    return Meter::fromRaw($meter);
                }

                $after = is_string($meter['id'] ?? null) ? $meter['id'] : $after;
            }
        } while (($page['has_more'] ?? false) === true && $meters !== []);

        return null;
    }
}
