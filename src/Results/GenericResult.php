<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Results;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Signal;
use RoundlyConsulting\Purchases\Enum\Status;

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
        private ?CarbonInterface $occurredAt = null,
        private ?string $customerId = null,
        private ?bool $cancelAtPeriodEnd = null,
        private ?string $eventId = null,
        private ?Signal $signal = null,
        private ?string $subscriptionId = null,
        private ?CarbonInterface $cancelsAt = null,
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

    public function occurredAt(): ?CarbonInterface
    {
        return $this->occurredAt;
    }

    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * The provider's customer the result belongs to (a Stripe `cus_…`), when it names one. A
     * billing customer linked to it makes its owner the owner of the recorded row.
     */
    public function customerId(): ?string
    {
        return $this->customerId;
    }

    /**
     * Whether a subscription is set to cancel at the end of its period, or null when the
     * provider does not say (Apple, Google).
     */
    public function cancelAtPeriodEnd(): ?bool
    {
        return $this->cancelAtPeriodEnd;
    }

    /**
     * The provider's id of the event this result came from — a Stripe event `evt_…`, an
     * Apple `notificationUUID`, a Google Pub/Sub `messageId` — or null when it came from no
     * event (a callback, a billing answer, a host-built result). A redelivery carries the same
     * id, which is what `purchases.audit.deduplicate` skips repeats by.
     */
    public function eventId(): ?string
    {
        return $this->eventId;
    }

    /**
     * What the event announces beyond the state it records — a trial ending soon, a payment
     * waiting for the customer — or null.
     */
    public function signal(): ?Signal
    {
        return $this->signal;
    }

    /**
     * The provider's id of the subscription a notification is about when the notification is
     * not the subscription itself (a Stripe invoice that needs the customer's action).
     */
    public function subscriptionId(): ?string
    {
        return $this->subscriptionId;
    }

    /**
     * When a subscription is set to cancel — a date chosen with `cancelAt()`, or the period end
     * `cancel_at_period_end` resolves to (Stripe `cancel_at`). Read it with cancelAtPeriodEnd():
     * a provider that reports one reports both, so while that is not null a null here means no
     * cancellation is scheduled. Apple and Google report neither.
     */
    public function cancelsAt(): ?CarbonInterface
    {
        return $this->cancelsAt;
    }
}
