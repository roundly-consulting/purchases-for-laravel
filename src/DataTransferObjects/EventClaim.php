<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use RoundlyConsulting\Purchases\Models\PurchaseNotification;

/**
 * What claiming a provider event found: the audit row that holds it, and whether the event was
 * already processed — a repeat to skip — or is this delivery's to process.
 */
final readonly class EventClaim
{
    public function __construct(
        public PurchaseNotification $notification,
        public bool $repeat,
    ) {}
}
