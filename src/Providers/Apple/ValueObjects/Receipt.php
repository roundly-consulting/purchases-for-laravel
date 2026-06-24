<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\ReceiptType;
use RoundlyConsulting\Purchases\Support\DataSet;

final class Receipt extends BaseValueObject implements FromRaw
{
    /**
     * @param  list<LatestReceiptInfo>  $latestReceiptInfo
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $adamId,
        public readonly ?string $appItemId,
        public readonly ?string $applicationVersion,
        public readonly ?string $bundleId,
        public readonly ?string $downloadId,
        public readonly ?Carbon $expirationDate,
        public readonly array $latestReceiptInfo,
        public readonly ?Carbon $originalPurchaseDate,
        public readonly ?Carbon $receiptCreationDate,
        public readonly ?ReceiptType $receiptType,
        public readonly ?Carbon $requestDate,
        public readonly ?int $versionExternalIdentifier,
        public readonly ?string $originalApplicationVersion,
        public readonly ?Carbon $preOrderDate,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            adamId: $dataset->value('adam_id'),
            appItemId: $dataset->value('app_item_id'),
            applicationVersion: $dataset->value('application_version'),
            bundleId: $dataset->value('bundle_id'),
            downloadId: $dataset->value('download_id'),
            expirationDate: $dataset->datetime('expiration_date_ms'),
            latestReceiptInfo: $dataset->arrayOf('in_app', LatestReceiptInfo::class),
            originalPurchaseDate: $dataset->datetime('original_purchase_date_ms'),
            receiptCreationDate: $dataset->datetime('receipt_creation_date_ms'),
            receiptType: $dataset->enum('receipt_type', ReceiptType::class),
            requestDate: $dataset->datetime('request_date_ms'),
            versionExternalIdentifier: $dataset->int('version_external_identifier'),
            originalApplicationVersion: $dataset->value('original_application_version'),
            preOrderDate: $dataset->datetime('preorder_date_ms'),
            raw: $dataset->retrieved(),
        );
    }
}
