<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Enum\NotificationOrigin;
use RoundlyConsulting\Purchases\Exceptions\InvalidProviderNotificationException;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;

/**
 * Re-runs one stored audit notification through the recording pipeline — recovery from a
 * downstream failure without asking the store to redeliver.
 *
 * The result is rebuilt from the notification's snapshot, recorded (idempotently, so a
 * repeat changes nothing and fires nothing), and the notification is marked processed. No
 * new audit row is written. Returns the persisted model, or null for an informational
 * notification.
 *
 * Only a stored notification replays, and only one somebody vouched for: a provider
 * notification the package verified, or a host-origin row written by `Purchases::sync()`
 * (unverified by the package — the host vouched for it when it synced it). A provider-origin
 * row that failed verification is refused, as are transient and soft-deleted rows and a
 * snapshot that no longer rebuilds.
 */
final readonly class ReplayProviderNotificationAction
{
    public function __construct(
        private RecordProviderResultAction $record,
    ) {}

    /**
     * @throws ModelNotFoundException<PurchaseNotification> for an id with no stored notification
     * @throws InvalidProviderNotificationException for a notification that must not or cannot replay
     */
    public function execute(PurchaseNotification|int $notification): ?Model
    {
        if (is_int($notification)) {
            $notification = PurchaseNotificationModel::query()->findOrFail($notification);
        }

        $label = 'Notification #'.(is_scalar($key = $notification->getKey()) ? (string) $key : '?');

        if (! $notification->exists || $notification->trashed()) {
            throw InvalidProviderNotificationException::because("{$label} is not stored; only an audited notification can be replayed.");
        }

        if (! $notification->signature_verified && $notification->origin() !== NotificationOrigin::Host) {
            throw InvalidProviderNotificationException::because("{$label} was never signature-verified; refusing to replay it.");
        }

        $result = NotificationResultFactory::fromNotification($notification)
            ?? throw InvalidProviderNotificationException::because("{$label} could not be rebuilt into a result.");

        $model = $this->record->execute($result);

        $notification->update(['processed_at' => Carbon::now()]);

        return $model;
    }
}
