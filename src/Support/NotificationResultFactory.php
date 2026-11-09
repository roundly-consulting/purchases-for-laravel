<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Money\Exceptions\MoneyException;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\DataTransferObjects\ResultItem;
use RoundlyConsulting\Purchases\Enum\NotificationOrigin;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Signal;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Results\GenericResult;

/**
 * Serializes a ProviderResult into a storable snapshot and rebuilds it again, so
 * audited notifications can be replayed through the recording pipeline.
 */
final class NotificationResultFactory
{
    /**
     * @return array<string, mixed>
     */
    public static function snapshot(ProviderResult $result, NotificationOrigin $origin = NotificationOrigin::Provider): array
    {
        $price = $result->price();

        return [
            'origin' => $origin->value,
            'provider' => $result->provider(),
            'type' => $result->type()->value,
            'provider_id' => $result->providerId(),
            'status' => $result->status()->value,
            'transaction_id' => $result->transactionId(),
            'name' => $result->name(),
            'product_id' => $result->productId(),
            'price' => $price?->toArray(),
            'active_from' => $result->activeFrom()?->toIso8601String(),
            'trial_ends_at' => $result->trialEndsAt()?->toIso8601String(),
            'ends_at' => $result->endsAt()?->toIso8601String(),
            'refund_reason' => $result->refundReason(),
            'chargeback' => $result->isChargeback(),
            'occurred_at' => $result->occurredAt()?->toIso8601String(),
            'customer_id' => $result instanceof GenericResult ? $result->customerId() : null,
            'cancel_at_period_end' => $result instanceof GenericResult ? $result->cancelAtPeriodEnd() : null,
            'event_id' => $result instanceof GenericResult ? $result->eventId() : null,
            'signal' => $result instanceof GenericResult ? $result->signal()?->value : null,
            'subscription_id' => $result instanceof GenericResult ? $result->subscriptionId() : null,
            'items' => array_map(static fn (ResultItem $item): array => [
                'name' => $item->name,
                'provider_id' => $item->providerId,
                'price' => $item->price?->toArray(),
                'quantity' => $item->quantity,
                'metered' => $item->metered,
                'price_id' => $item->priceId,
            ], $result->items()),
            'raw' => $result->raw(),
        ];
    }

    /**
     * An unsaved notification describing a result, for when no audit row does — auditing is
     * off and the result was queued or informational — so `handle()` still returns a Model.
     */
    public static function transient(ProviderResult $result): PurchaseNotification
    {
        $notification = PurchaseNotificationModel::new();

        $notification->fill([
            'provider' => $result->provider(),
            'type' => $result->type()->value,
            'signature_verified' => true,
            'payload' => $result->raw(),
        ]);

        return $notification;
    }

    public static function fromNotification(PurchaseNotification $notification): ?GenericResult
    {
        $data = $notification->payload->all();

        $type = isset($data['type']) && is_string($data['type'])
            ? ResultType::tryFrom($data['type'])
            : null;

        $status = isset($data['status']) && is_string($data['status'])
            ? Status::tryFrom($data['status'])
            : null;

        if ($type === null || $status === null || ! isset($data['provider_id'])) {
            return null;
        }

        /** @var array<string, mixed> $raw */
        $raw = is_array($data['raw'] ?? null) ? $data['raw'] : [];

        // A price money refuses makes the whole snapshot unparseable: replaying it
        // without the price would record a purchase that silently lost its amount.
        try {
            $price = self::money($data['price'] ?? null);
            $items = self::items($data['items'] ?? null);
        } catch (MoneyException) {
            return null;
        }

        return new GenericResult(
            provider: (string) ($data['provider'] ?? $notification->provider),
            type: $type,
            providerId: (string) $data['provider_id'],
            status: $status,
            transactionId: self::string($data['transaction_id'] ?? null),
            name: self::string($data['name'] ?? null),
            productId: self::string($data['product_id'] ?? null),
            price: $price,
            activeFrom: self::date($data['active_from'] ?? null),
            trialEndsAt: self::date($data['trial_ends_at'] ?? null),
            endsAt: self::date($data['ends_at'] ?? null),
            items: $items,
            raw: $raw,
            refundReason: self::string($data['refund_reason'] ?? null),
            chargeback: (bool) ($data['chargeback'] ?? false),
            occurredAt: self::date($data['occurred_at'] ?? null),
            customerId: self::string($data['customer_id'] ?? null),
            cancelAtPeriodEnd: is_bool($data['cancel_at_period_end'] ?? null) ? $data['cancel_at_period_end'] : null,
            eventId: self::string($data['event_id'] ?? null),
            // A snapshot written before signals were stored has none; an unknown one is dropped.
            signal: is_string($data['signal'] ?? null) ? Signal::tryFrom($data['signal']) : null,
            subscriptionId: self::string($data['subscription_id'] ?? null),
        );
    }

    /**
     * The snapshot's line items. A snapshot written before items were stored has none, and
     * replays as before: an item-less result leaves the stored items alone.
     *
     * @return list<ResultItem>
     *
     * @throws MoneyException for an item price money refuses
     */
    private static function items(mixed $value): array
    {
        $items = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                continue;
            }

            $items[] = new ResultItem(
                name: $item['name'],
                providerId: self::string($item['provider_id'] ?? null),
                price: self::money($item['price'] ?? null),
                quantity: is_int($item['quantity'] ?? null) ? $item['quantity'] : 1,
                metered: ($item['metered'] ?? false) === true,
                priceId: self::string($item['price_id'] ?? null),
            );
        }

        return $items;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * Rebuild the snapshot's `{minor, decimal, currency}` price; anything that is
     * not an array reads as "no price".
     *
     * @throws MoneyException for an array money refuses (a float `minor`, a
     *                        `decimal` that disagrees with it, an unknown currency)
     */
    private static function money(mixed $value): ?Money
    {
        return is_array($value) ? Money::fromArray($value) : null;
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
