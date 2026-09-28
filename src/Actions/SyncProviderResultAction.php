<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;

/**
 * Persists a result you already hold — a receipt verified on the device side, a
 * `GenericResult` built for a backfill — exactly the way a webhook is persisted: the
 * snapshot goes to the audit log, the result is reduced to purchase / subscription /
 * refund state with its lifecycle events, and the audit row is marked processed.
 *
 * Always synchronous: `purchases.queue.enabled` governs `handle()` only. It trusts its
 * input — nothing is re-verified here, so never feed it an unverified client payload.
 * Returns the persisted Purchase, Subscription or PurchaseRefund, or null for an
 * informational result (which records nothing but is still audited).
 */
final readonly class SyncProviderResultAction
{
    public function __construct(
        private RecordProviderNotificationAction $audit,
        private RecordProviderResultAction $record,
    ) {}

    public function execute(ProviderResult $result): ?Model
    {
        $notification = $this->audit->execute($result);

        $model = $this->record->execute($result);

        $notification?->update(['processed_at' => Carbon::now()]);

        return $model;
    }
}
