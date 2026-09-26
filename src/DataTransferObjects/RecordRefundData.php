<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\Status;

final readonly class RecordRefundData
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $provider,
        public string $providerId,
        public bool $chargeback = false,
        public ?string $transactionId = null,
        public ?string $reason = null,
        public ?Money $price = null,
        public ?CarbonInterface $refundedAt = null,
        public array $meta = [],
        public Status $status = Status::Refunded,
    ) {}

    public static function fromResult(ProviderResult $result): self
    {
        return new self(
            provider: $result->provider(),
            providerId: $result->providerId(),
            chargeback: $result->isChargeback(),
            transactionId: $result->transactionId(),
            reason: $result->refundReason(),
            price: $result->price(),
            refundedAt: $result->endsAt() ?? $result->activeFrom(),
            meta: $result->raw(),
            status: $result->status(),
        );
    }
}
