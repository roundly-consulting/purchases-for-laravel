<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A decoded Real-time Developer Notification (RTDN) delivered via Cloud Pub/Sub.
 *
 * @link https://developer.android.com/google/play/billing/rtdn-reference
 */
final class DeveloperNotification implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $version,
        public readonly ?string $packageName,
        public readonly ?Carbon $eventTime,
        public readonly ?SubscriptionNotification $subscriptionNotification,
        public readonly ?OneTimeProductNotification $oneTimeProductNotification,
        public readonly ?VoidedPurchaseNotification $voidedPurchaseNotification,
        public readonly bool $isTest,
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
            packageName: $dataset->value('packageName'),
            eventTime: $dataset->datetime('eventTimeMillis'),
            subscriptionNotification: $dataset->fromRawTo('subscriptionNotification', SubscriptionNotification::class),
            oneTimeProductNotification: $dataset->fromRawTo('oneTimeProductNotification', OneTimeProductNotification::class),
            voidedPurchaseNotification: $dataset->fromRawTo('voidedPurchaseNotification', VoidedPurchaseNotification::class),
            isTest: $dataset->value('testNotification') !== null,
            raw: $raw,
        );
    }
}
