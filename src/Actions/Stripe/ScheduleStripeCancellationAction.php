<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions\Stripe;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\BillingApi;
use RoundlyConsulting\Purchases\Providers\Stripe\Billing\SubscriptionLocator;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\CancelAt;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\ProrationBehavior;
use RoundlyConsulting\Purchases\Support\IdempotencyKey;

/**
 * Schedules a Stripe subscription to cancel at a chosen moment (`cancel_at`) — a date, or one
 * of Stripe's period helpers (CancelAt::MaxPeriodEnd ends a mixed-interval flexible subscription
 * only once every item has run out its paid period) — and records the result locally, the date
 * in `cancels_at`. It stays active until then and can still be resumed.
 *
 * A date before the current period ends shortens it: Stripe prorates the change as `proration`
 * says (`proration_behavior`), or by its default (`create_prorations`) when none is given. A date
 * that is not in the future is refused before Stripe is asked.
 */
final readonly class ScheduleStripeCancellationAction
{
    public function __construct(
        private BillingApi $api,
        private RecordStripeSubscriptionAction $record,
    ) {}

    public function execute(Subscription|string $subscription, CarbonInterface|CancelAt $at, ?ProrationBehavior $proration = null, ?string $idempotencyKey = null): Subscription
    {
        if ($at instanceof CarbonInterface && ! $at->greaterThan(Carbon::now())) {
            throw BillingException::invalid('A cancellation date must be in the future.');
        }

        $key = IdempotencyKey::resolve($idempotencyKey);

        return $this->record->execute($this->api->post(
            BillingApi::path('subscriptions', SubscriptionLocator::id($subscription)),
            [
                'cancel_at' => $at instanceof CancelAt ? $at->value : $at->getTimestamp(),
                'proration_behavior' => $proration?->value,
            ],
            $key,
        ));
    }
}
