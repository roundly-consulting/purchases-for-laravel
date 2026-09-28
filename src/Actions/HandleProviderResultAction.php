<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;

/**
 * What `Purchases::handle()` does with a request once the provider has verified it.
 *
 * The result is logged to the audit table first. When `purchases.queue.enabled` is on it is
 * recorded on a queue and the audit notification is returned; otherwise it is recorded now
 * and the persisted model is returned. An informational result records nothing, so its
 * audit notification — transient when auditing is off — is returned instead: the caller
 * always receives a Model.
 */
final readonly class HandleProviderResultAction
{
    public function __construct(
        private RecordProviderNotificationAction $audit,
        private RecordProviderResultAction $record,
    ) {}

    public function execute(ProviderResult $result): Model
    {
        $notification = $this->audit->execute($result);

        if (Config::boolean('purchases.queue.enabled')) {
            ProcessProviderNotification::dispatch($result, $notification?->getKey());

            return $notification ?? NotificationResultFactory::transient($result);
        }

        $model = $this->record->execute($result);

        $notification?->update(['processed_at' => Carbon::now()]);

        return $model ?? $notification ?? NotificationResultFactory::transient($result);
    }
}
