<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Contracts;

use Carbon\CarbonInterface;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;

/**
 * A provider-agnostic view over a verified purchase, subscription, or notification.
 *
 * Every provider maps its native payload into a ProviderResult so host applications
 * can write code once and have it work across Apple, Google, and Stripe.
 */
interface ProviderResult
{
    /**
     * The provider this result originated from (e.g. "apple", "google", "stripe").
     */
    public function provider(): string;

    /**
     * Whether this result represents a purchase, subscription, or a notification.
     */
    public function type(): ResultType;

    /**
     * The provider-side identifier this result should be keyed on when persisted.
     */
    public function providerId(): string;

    /**
     * The underlying transaction identifier, when one is available.
     */
    public function transactionId(): ?string;

    /**
     * The normalized status across providers.
     */
    public function status(): Status;

    /**
     * A human-readable name for the purchased product or subscription, when available.
     */
    public function name(): ?string;

    /**
     * The product / SKU identifier, when available.
     */
    public function productId(): ?string;

    /**
     * The amount paid, when the provider exposes it.
     */
    public function price(): ?Money;

    /**
     * When a subscription became (or becomes) active, when applicable.
     */
    public function activeFrom(): ?CarbonInterface;

    /**
     * When a subscription's trial ends, when applicable.
     */
    public function trialEndsAt(): ?CarbonInterface;

    /**
     * When a subscription ends / expires, when applicable.
     */
    public function endsAt(): ?CarbonInterface;

    /**
     * The individual line items that make up this result.
     *
     * @return list<ResultItem>
     */
    public function items(): array;

    /**
     * The reason a refund/chargeback was issued, when this result is a refund.
     */
    public function refundReason(): ?string;

    /**
     * Whether a refund result represents a chargeback / dispute rather than a
     * voluntary store or merchant refund.
     */
    public function isChargeback(): bool;

    /**
     * The raw decoded payload for power users who need provider-specific fields.
     *
     * @return array<string, mixed>
     */
    public function raw(): array;
}
