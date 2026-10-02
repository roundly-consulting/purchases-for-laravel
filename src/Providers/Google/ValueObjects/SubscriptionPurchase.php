<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Money\Exceptions\MoneyException;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Providers\Google\Enums\AcknowledgementState;
use RoundlyConsulting\Purchases\Providers\Google\Enums\SubscriptionState;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A subscriptionsv2 purchase from the Play Developer API.
 *
 * @link https://developer.android.com/reference/com/google/android/gms/wallet/SubscriptionPurchaseV2
 */
final class SubscriptionPurchase implements FromRaw
{
    /**
     * @param  list<SubscriptionLineItem>  $lineItems
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?SubscriptionState $subscriptionState,
        public readonly ?string $latestOrderId,
        public readonly ?Carbon $startTime,
        public readonly ?AcknowledgementState $acknowledgementState,
        public readonly ?string $regionCode,
        public readonly array $lineItems,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        $acknowledgementState = match ($dataset->value('acknowledgementState')) {
            'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED' => AcknowledgementState::Acknowledged,
            'ACKNOWLEDGEMENT_STATE_PENDING' => AcknowledgementState::YetToBeAcknowledged,
            default => null,
        };

        return new self(
            subscriptionState: $dataset->enum('subscriptionState', SubscriptionState::class),
            latestOrderId: $dataset->value('latestOrderId'),
            startTime: $dataset->timestamp('startTime'),
            acknowledgementState: $acknowledgementState,
            regionCode: $dataset->value('regionCode'),
            lineItems: $dataset->arrayOf('lineItems', SubscriptionLineItem::class),
            raw: $raw,
        );
    }

    /**
     * Whether the customer is still entitled to the subscription now: active, in its grace
     * period, or canceled with paid time left — SUBSCRIPTION_STATE_CANCELED means auto-renew
     * is off, and access lasts until the expiry.
     */
    public function isEntitled(): bool
    {
        return match ($this->subscriptionState) {
            SubscriptionState::Active, SubscriptionState::InGracePeriod => true,
            SubscriptionState::Canceled => $this->expiryTime()?->isFuture() ?? false,
            default => false,
        };
    }

    /**
     * The normalized status: the state's own, except that a canceled subscription with paid
     * time left stays Completed until it expires (its `endsAt` ends it).
     */
    public function status(): Status
    {
        if ($this->subscriptionState === SubscriptionState::Canceled && $this->isEntitled()) {
            return Status::Completed;
        }

        return $this->subscriptionState?->status() ?? Status::Processing;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledgementState?->isAcknowledged() ?? false;
    }

    public function expiryTime(): ?Carbon
    {
        $latest = null;

        foreach ($this->lineItems as $item) {
            if ($item->expiryTime === null) {
                continue;
            }

            if ($latest === null || $item->expiryTime->greaterThan($latest)) {
                $latest = $item->expiryTime;
            }
        }

        return $latest;
    }

    /**
     * The subscription's recurring price: the sum of its line items' prices, or
     * null when none carries one (prepaid plans) or they disagree on currency.
     */
    public function price(): ?Money
    {
        $prices = [];

        foreach ($this->lineItems as $item) {
            if ($item->recurringPrice !== null) {
                $prices[] = $item->recurringPrice;
            }
        }

        if ($prices === []) {
            return null;
        }

        try {
            return Money::sum($prices);
        } catch (MoneyException) {
            return null;
        }
    }

    public function productId(): ?string
    {
        return $this->lineItems[0]->productId ?? null;
    }
}
