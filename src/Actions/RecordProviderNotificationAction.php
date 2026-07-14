<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;

/**
 * Persists a verified notification snapshot to the audit log before it is reduced
 * to purchase / subscription / refund state. The snapshot stores both the raw
 * provider payload and the normalized result, so it can be inspected or replayed.
 */
final class RecordProviderNotificationAction
{
    public function execute(ProviderResult $result): ?PurchaseNotification
    {
        if (config('purchases.audit.enabled', true) !== true) {
            return null;
        }

        /** @var PurchaseNotification $notification */
        $notification = PurchaseNotificationModel::query()->create([
            'provider' => $result->provider(),
            'type' => $result->type()->value,
            'signature_verified' => true,
            'payload' => NotificationResultFactory::snapshot($result),
            'processed_at' => null,
        ]);

        return $notification;
    }
}
