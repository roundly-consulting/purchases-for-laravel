<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationSubType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationType;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * `subtype` (Apple's key, lower-case t) is present only for some notification types —
 * REFUND, REVOKE, TEST and a plain DID_RENEW carry none — so it is nullable.
 *
 * @link https://developer.apple.com/documentation/appstoreservernotifications/responsebodyv2decodedpayload
 */
final class ServerNotificationDecodedPayload extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $uuid,
        public readonly NotificationType $type,
        public readonly ?NotificationSubType $subType,
        public readonly AppMetadata $appMetadata,
        public readonly ?RenewalInfo $renewalInfo,
        public readonly ?TransactionInfo $transactionInfo,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            uuid: $dataset->value('notificationUUID'),
            type: $dataset->enum('notificationType', NotificationType::class),
            subType: $dataset->enum('subtype', NotificationSubType::class),
            appMetadata: $dataset->fromRawTo('data', AppMetadata::class),
            renewalInfo: $dataset->fromRawTo('data.renewalInfo', RenewalInfo::class),
            transactionInfo: $dataset->fromRawTo('data.transactionInfo', TransactionInfo::class),
            raw: $dataset->retrieved(),
        );
    }
}
