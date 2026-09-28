<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\Status;

final readonly class RecordPurchaseData
{
    /**
     * @param  list<ResultItem>  $items
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $provider,
        public string $providerId,
        public Status $status,
        public ?string $transactionId = null,
        public ?Money $price = null,
        public array $items = [],
        public array $meta = [],
        public ?CarbonInterface $occurredAt = null,
    ) {}

    public static function fromResult(ProviderResult $result): self
    {
        return new self(
            provider: $result->provider(),
            providerId: $result->providerId(),
            status: $result->status(),
            transactionId: $result->transactionId(),
            price: $result->price(),
            items: $result->items(),
            meta: $result->raw(),
            occurredAt: $result->occurredAt(),
        );
    }
}
