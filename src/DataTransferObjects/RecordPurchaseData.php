<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\DataTransferObjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Support\AppTime;

final readonly class RecordPurchaseData
{
    /**
     * `owner` links a purchase that has no owner yet to that model (the owner of the billing
     * customer the result names); an owner already linked is never replaced.
     *
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
        public ?Model $owner = null,
    ) {}

    /**
     * Provider dates arrive in UTC; they are moved into the application's timezone, the one
     * Eloquent reads them back in, so they keep their instant.
     */
    public static function fromResult(ProviderResult $result, ?Model $owner = null): self
    {
        return new self(
            provider: $result->provider(),
            providerId: $result->providerId(),
            status: $result->status(),
            transactionId: $result->transactionId(),
            price: $result->price(),
            items: $result->items(),
            meta: $result->raw(),
            occurredAt: AppTime::of($result->occurredAt()),
            owner: $owner,
        );
    }
}
