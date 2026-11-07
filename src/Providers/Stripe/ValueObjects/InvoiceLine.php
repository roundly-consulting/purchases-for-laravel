<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * One line of an invoice preview: what it bills, for how much, and whether it is a
 * proration of a mid-period change.
 *
 * Since API version 2025-03-31 the price lives at `pricing.price_details.price` and the
 * proration flag at `parent.subscription_item_details.proration`; the older top-level
 * `price` and `proration` are read too.
 */
final readonly class InvoiceLine
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $description,
        public ?Money $amount,
        public ?int $quantity,
        public ?string $priceId,
        public ?string $subscriptionItem,
        public bool $proration,
        public ?Carbon $periodStart,
        public ?Carbon $periodEnd,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);
        $description = $dataset->value('description');
        $quantity = $dataset->value('quantity');
        $proration = $dataset->value('parent.subscription_item_details.proration') ?? $dataset->value('proration');

        return new self(
            description: is_string($description) ? $description : null,
            amount: StripeMoney::fromDataSet($dataset, 'amount', 'currency'),
            quantity: is_int($quantity) ? $quantity : null,
            priceId: self::id($dataset->value('pricing.price_details.price') ?? $dataset->value('price')),
            subscriptionItem: self::id($dataset->value('parent.subscription_item_details.subscription_item') ?? $dataset->value('subscription_item')),
            proration: $proration === true,
            periodStart: self::epoch($dataset->value('period.start')),
            periodEnd: self::epoch($dataset->value('period.end')),
            raw: $raw,
        );
    }

    private static function id(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function epoch(mixed $value): ?Carbon
    {
        return is_int($value) ? Carbon::createFromTimestamp($value) : null;
    }
}
