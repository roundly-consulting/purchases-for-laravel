<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\SubscriptionStatus;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * Since API version 2025-03-31 the billing period lives on each subscription item
 * (`items.data[].current_period_start/end`), not on the subscription; both shapes are
 * read — the item periods as the earliest start and the latest end. `productId` is the
 * first item's price's product.
 */
final class Subscription implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?SubscriptionStatus $status,
        public readonly ?string $customer,
        public readonly ?Carbon $currentPeriodStart,
        public readonly ?Carbon $currentPeriodEnd,
        public readonly ?Carbon $trialEnd,
        public readonly ?Carbon $canceledAt,
        public readonly array $raw,
        public readonly ?string $productId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            id: $dataset->value('id'),
            status: $dataset->enum('status', SubscriptionStatus::class),
            customer: $dataset->value('customer'),
            currentPeriodStart: self::epoch($dataset->value('current_period_start')) ?? self::itemPeriod($dataset, 'current_period_start', earliest: true),
            currentPeriodEnd: self::epoch($dataset->value('current_period_end')) ?? self::itemPeriod($dataset, 'current_period_end', earliest: false),
            trialEnd: self::epoch($dataset->value('trial_end')),
            canceledAt: self::epoch($dataset->value('canceled_at')),
            raw: $raw,
            productId: self::product($dataset),
        );
    }

    /**
     * The product of the first item's price — its id, or the id of an expanded product.
     */
    private static function product(DataSet $dataset): ?string
    {
        $product = $dataset->value('items.data.0.price.product');

        if (is_array($product)) {
            $product = $product['id'] ?? null;
        }

        return is_string($product) && $product !== '' ? $product : null;
    }

    private static function itemPeriod(DataSet $dataset, string $key, bool $earliest): ?Carbon
    {
        $items = $dataset->value('items.data');
        $picked = null;

        foreach (is_array($items) ? $items : [] as $item) {
            $value = is_array($item) ? ($item[$key] ?? null) : null;

            if (! is_int($value)) {
                continue;
            }

            if ($picked === null || ($earliest ? $value < $picked : $value > $picked)) {
                $picked = $value;
            }
        }

        return self::epoch($picked);
    }

    private static function epoch(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value);
    }
}
