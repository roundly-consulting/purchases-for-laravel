<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Money\Exceptions\MoneyException;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Contracts\ProviderResult;
use RoundlyConsulting\Purchases\Enum\ResultType;
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
    public static function snapshot(ProviderResult $result): array
    {
        $price = $result->price();

        return [
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
            'raw' => $result->raw(),
        ];
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
            items: [],
            raw: $raw,
            refundReason: self::string($data['refund_reason'] ?? null),
            chargeback: (bool) ($data['chargeback'] ?? false),
        );
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
