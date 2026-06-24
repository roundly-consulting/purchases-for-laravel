<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Results;

use Carbon\CarbonInterface;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\ValueObjects\Money;

/**
 * A concrete, provider-agnostic ProviderResult that every provider maps its native
 * payload into. The original typed value object remains reachable through raw().
 */
final readonly class GenericResult implements ProviderResult
{
    /**
     * @param  list<ResultItem>  $items
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        private string $provider,
        private ResultType $type,
        private string $providerId,
        private Status $status,
        private ?string $transactionId = null,
        private ?string $name = null,
        private ?string $productId = null,
        private ?Money $price = null,
        private ?CarbonInterface $activeFrom = null,
        private ?CarbonInterface $trialEndsAt = null,
        private ?CarbonInterface $endsAt = null,
        private array $items = [],
        private array $raw = [],
        private ?string $refundReason = null,
        private bool $chargeback = false,
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function type(): ResultType
    {
        return $this->type;
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }

    public function status(): Status
    {
        return $this->status;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function productId(): ?string
    {
        return $this->productId;
    }

    public function price(): ?Money
    {
        return $this->price;
    }

    public function activeFrom(): ?CarbonInterface
    {
        return $this->activeFrom;
    }

    public function trialEndsAt(): ?CarbonInterface
    {
        return $this->trialEndsAt;
    }

    public function endsAt(): ?CarbonInterface
    {
        return $this->endsAt;
    }

    public function items(): array
    {
        return $this->items;
    }

    public function refundReason(): ?string
    {
        return $this->refundReason;
    }

    public function isChargeback(): bool
    {
        return $this->chargeback;
    }

    public function raw(): array
    {
        return $this->raw;
    }
}
