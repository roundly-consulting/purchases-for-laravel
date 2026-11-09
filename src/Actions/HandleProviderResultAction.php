<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Exceptions\EventInProgressException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Results\GenericResult;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;
use Throwable;

/**
 * What `Purchases::handle()` does with a request once the provider has verified it.
 *
 * The result is logged to the audit table first. When `purchases.queue.enabled` is on it is
 * recorded on a queue and the audit notification is returned; otherwise it is recorded now
 * and the persisted model is returned. An informational result records nothing, so its
 * audit notification — transient when auditing is off — is returned instead: the caller
 * always receives a Model.
 *
 * With `purchases.audit.deduplicate` on, a result that names its provider event is processed
 * once per event: a repeat returns the audit notification of the first delivery and records
 * and fires nothing, and a redelivery that races one still in progress throws
 * EventInProgressException (see ClaimProviderEventAction).
 */
final readonly class HandleProviderResultAction
{
    public function __construct(
        private RecordProviderNotificationAction $audit,
        private RecordProviderResultAction $record,
        private ClaimProviderEventAction $claim,
    ) {}

    /**
     * @throws EventInProgressException
     */
    public function execute(ProviderResult $result): Model
    {
        $eventId = $result instanceof GenericResult ? $result->eventId() : null;

        if ($eventId !== null && Config::boolean('purchases.audit.deduplicate')) {
            return $this->once($result, $eventId);
        }

        $notification = $this->audit->execute($result);

        if (Config::boolean('purchases.queue.enabled')) {
            ProcessProviderNotification::dispatch($result, $notification?->getKey());

            return $notification ?? NotificationResultFactory::transient($result);
        }

        $model = $this->record->execute($result);

        $notification?->update(['processed_at' => Carbon::now()]);

        return $model ?? $notification ?? NotificationResultFactory::transient($result);
    }

    private function once(ProviderResult $result, string $eventId): Model
    {
        // The event id lives on the audit row: without the log there is nothing to skip by.
        if (! Config::boolean('purchases.audit.enabled', true)) {
            throw new InvalidConfigurationException('[purchases.audit.deduplicate] needs the audit log, but [purchases.audit.enabled] is off.');
        }

        $claim = $this->claim->execute($result, $eventId);
        $notification = $claim->notification;

        if ($claim->repeat) {
            return $notification;
        }

        if (Config::boolean('purchases.queue.enabled')) {
            ProcessProviderNotification::dispatch($result, $notification->getKey());

            return $notification;
        }

        try {
            $model = $this->record->execute($result);
        } catch (Throwable $e) {
            // Let the store's next delivery take it straight over rather than wait out the claim.
            self::release($notification);

            throw $e;
        }

        $notification->update(['processed_at' => Carbon::now()]);

        return $model ?? $notification;
    }

    private static function release(PurchaseNotification $notification): void
    {
        PurchaseNotificationModel::query()
            ->whereKey($notification->getKey())
            ->whereNull('processed_at')
            ->update(['claimed_at' => null]);
    }
}
