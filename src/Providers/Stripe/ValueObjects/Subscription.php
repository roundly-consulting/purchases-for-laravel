<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\SubscriptionStatus;
use RoundlyConsulting\Purchases\Support\DataSet;

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
            currentPeriodStart: self::epoch($dataset->value('current_period_start')),
            currentPeriodEnd: self::epoch($dataset->value('current_period_end')),
            trialEnd: self::epoch($dataset->value('trial_end')),
            canceledAt: self::epoch($dataset->value('canceled_at')),
            raw: $raw,
        );
    }

    private static function epoch(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value);
    }
}
