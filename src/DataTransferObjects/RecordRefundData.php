<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Support\AppTime;

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
        public ?CarbonInterface $occurredAt = null,
    ) {}

    /**
     * Provider dates arrive in UTC; they are moved into the application's timezone, the one
     * Eloquent reads them back in, so they keep their instant.
     */
    public static function fromResult(ProviderResult $result): self
    {
        return new self(
            provider: $result->provider(),
            providerId: $result->providerId(),
            chargeback: $result->isChargeback(),
            transactionId: $result->transactionId(),
            reason: $result->refundReason(),
            price: $result->price(),
            // Apple names the refund's date; Stripe and Google say only when the event happened.
            refundedAt: AppTime::of($result->endsAt() ?? $result->activeFrom() ?? $result->occurredAt()),
            meta: $result->raw(),
            status: $result->status(),
            occurredAt: AppTime::of($result->occurredAt()),
        );
    }
}
