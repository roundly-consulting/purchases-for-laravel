<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationSubType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationType;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * `subtype` (Apple's key, lower-case t) is present only for some notification types —
 * REFUND, REVOKE, TEST and a plain DID_RENEW carry none — so it is nullable.
 *
 * `data`, `summary` (RENEWAL_EXTENSION / SUMMARY), `externalPurchaseToken`
 * (EXTERNAL_PURCHASE_TOKEN) and `appData` (RESCIND_CONSENT) are mutually exclusive, so
 * `appMetadata` is null for the three that carry no `data`; theirs is kept raw. A type
 * this version does not know parses as NotificationType::Unknown.
 *
 * @link https://developer.apple.com/documentation/appstoreservernotifications/responsebodyv2decodedpayload
 */
final class ServerNotificationDecodedPayload extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>|null  $summary
     * @param  array<string, mixed>|null  $externalPurchaseToken
     * @param  array<string, mixed>|null  $appData
     */
    public function __construct(
        public readonly string $uuid,
        public readonly NotificationType $type,
        public readonly ?NotificationSubType $subType,
        public readonly ?AppMetadata $appMetadata,
        public readonly ?RenewalInfo $renewalInfo,
        public readonly ?TransactionInfo $transactionInfo,
        public readonly array $raw,
        public readonly ?array $summary = null,
        public readonly ?array $externalPurchaseToken = null,
        public readonly ?array $appData = null,
        public readonly ?Carbon $signedDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            uuid: $dataset->value('notificationUUID'),
            type: $dataset->enum('notificationType', NotificationType::class, NotificationType::Unknown),
            subType: $dataset->enum('subtype', NotificationSubType::class),
            appMetadata: $dataset->fromRawTo('data', AppMetadata::class),
            renewalInfo: $dataset->fromRawTo('data.renewalInfo', RenewalInfo::class),
            transactionInfo: $dataset->fromRawTo('data.transactionInfo', TransactionInfo::class),
            summary: self::object($dataset->value('summary')),
            externalPurchaseToken: self::object($dataset->value('externalPurchaseToken')),
            appData: self::object($dataset->value('appData')),
            signedDate: $dataset->datetime('signedDate'),
            // Last: it snapshots only the keys read above.
            raw: $dataset->retrieved(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }
}
