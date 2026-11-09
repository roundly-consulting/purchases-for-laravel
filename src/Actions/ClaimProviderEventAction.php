<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\DataTransferObjects\EventClaim;
use RoundlyConsulting\Purchases\Exceptions\EventInProgressException;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;

/**
 * Takes a provider event on for one delivery, under `purchases.audit.deduplicate`.
 *
 * The audit row is written with the event id, unique per provider — so of two deliveries of one
 * event racing each other, the database lets exactly one insert it. The other finds that row:
 *
 *  - processed (or deleted by the host): a repeat, skipped;
 *  - unprocessed and claimed less than `purchases.audit.claim_timeout` seconds ago: another
 *    delivery is on it — EventInProgressException, so the store delivers it again later;
 *  - unprocessed with a released or timed-out claim (that delivery failed or died): taken over
 *    by a conditional update only one delivery can win, and processed again.
 *
 * @internal building block of `Purchases::handle()` — reach it through the facade.
 */
final readonly class ClaimProviderEventAction
{
    /**
     * @throws EventInProgressException
     */
    public function execute(ProviderResult $result, string $eventId): EventClaim
    {
        $now = Carbon::now();

        /** @var PurchaseNotification $notification */
        $notification = PurchaseNotificationModel::query()->withTrashed()->createOrFirst(
            ['provider' => $result->provider(), 'event_id' => $eventId],
            [
                'type' => $result->type()->value,
                'signature_verified' => true,
                'payload' => NotificationResultFactory::snapshot($result),
                'processed_at' => null,
                'claimed_at' => $now,
            ],
        );

        if ($notification->wasRecentlyCreated) {
            return new EventClaim($notification, repeat: false);
        }

        if ($notification->trashed() || $notification->processed_at !== null) {
            return new EventClaim($notification, repeat: true);
        }

        $timeout = PurchasesConfig::integer(config('purchases.audit.claim_timeout'), 'purchases.audit.claim_timeout', 300, min: 1);

        $taken = PurchaseNotificationModel::query()
            ->whereKey($notification->getKey())
            ->whereNull('processed_at')
            ->where(static function (Builder $query) use ($now, $timeout): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $now->copy()->subSeconds($timeout));
            })
            ->update(['claimed_at' => $now]);

        $notification->refresh();

        if ($taken === 1) {
            return new EventClaim($notification, repeat: false);
        }

        // Lost the takeover: the winner may already be done.
        if ($notification->processed_at !== null) {
            return new EventClaim($notification, repeat: true);
        }

        throw EventInProgressException::for($result->provider(), $eventId);
    }
}
