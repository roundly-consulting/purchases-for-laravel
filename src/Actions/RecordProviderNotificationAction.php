<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Actions;

use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\NotificationOrigin;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Support\NotificationResultFactory;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;

/**
 * Persists a verified notification snapshot to the audit log before it is reduced
 * to purchase / subscription / refund state. The snapshot stores both the raw
 * provider payload and the normalized result, so it can be inspected or replayed.
 *
 * @internal building block of `Purchases::handle()` / `sync()` — reach it through the facade.
 */
final readonly class RecordProviderNotificationAction
{
    /**
     * A Provider-origin result was verified by the package; a Host-origin one was not, and is
     * recorded as `signature_verified = false` rather than claiming a check that never ran.
     */
    public function execute(ProviderResult $result, NotificationOrigin $origin = NotificationOrigin::Provider): ?PurchaseNotification
    {
        if (! Config::boolean('purchases.audit.enabled', true)) {
            return null;
        }

        /** @var PurchaseNotification $notification */
        $notification = PurchaseNotificationModel::query()->create([
            'provider' => $result->provider(),
            'type' => $result->type()->value,
            'signature_verified' => $origin === NotificationOrigin::Provider,
            'payload' => NotificationResultFactory::snapshot($result, $origin),
            'processed_at' => null,
        ]);

        return $notification;
    }
}
