<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use Carbon\CarbonInterface;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\ValueObjects\Money;

final readonly class RecordSubscriptionData
{
    /**
     * @param  list<ResultItem>  $items
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $provider,
        public string $providerId,
        public Status $status,
        public string $name,
        public ?string $transactionId = null,
        public ?Money $price = null,
        public ?CarbonInterface $activeFrom = null,
        public ?CarbonInterface $trialEndsAt = null,
        public ?CarbonInterface $endsAt = null,
        public array $items = [],
        public array $meta = [],
    ) {}

    public static function fromResult(ProviderResult $result): self
    {
        return new self(
            provider: $result->provider(),
            providerId: $result->providerId(),
            status: $result->status(),
            name: $result->name() ?? $result->productId() ?? $result->providerId(),
            transactionId: $result->transactionId(),
            price: $result->price(),
            activeFrom: $result->activeFrom(),
            trialEndsAt: $result->trialEndsAt(),
            endsAt: $result->endsAt(),
            items: $result->items(),
            meta: $result->raw(),
        );
    }
}
