<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Providers\Google\Enums\NotificationType;
use RoundlyConsulting\Purchases\Support\DataSet;

final class SubscriptionNotification implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $version,
        public readonly ?NotificationType $notificationType,
        public readonly ?string $purchaseToken,
        public readonly ?string $subscriptionId,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            version: $dataset->value('version'),
            notificationType: $dataset->enum('notificationType', NotificationType::class),
            purchaseToken: $dataset->value('purchaseToken'),
            subscriptionId: $dataset->value('subscriptionId'),
            raw: $raw,
        );
    }
}
