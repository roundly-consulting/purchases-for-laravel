<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Support\DataSet;

final class ReceiptResponse extends BaseValueObject implements FromRaw
{
    /**
     * @param  list<LatestReceiptInfo>  $latestReceiptInfo
     * @param  list<PendingRenewal>  $pendingRenewalInfo
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ReceiptStatus $status,
        public readonly Environment $environment,
        public readonly bool $isRetryable,
        public readonly ?string $latestReceipt,
        public readonly array $latestReceiptInfo,
        public readonly array $pendingRenewalInfo,
        public readonly ?Receipt $receipt,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            status: $dataset->valueOf('status', ReceiptStatus::class),
            environment: $dataset->enum('environment', Environment::class),
            isRetryable: $dataset->bool('is-retryable'),
            latestReceipt: $dataset->value('latest_receipt'),
            latestReceiptInfo: $dataset->arrayOf('latest_receipt_info', LatestReceiptInfo::class),
            pendingRenewalInfo: $dataset->arrayOf('pending_renewal_info', PendingRenewal::class),
            receipt: $dataset->fromRawTo('receipt', Receipt::class),
            raw: $dataset->retrieved(),
        );
    }
}
