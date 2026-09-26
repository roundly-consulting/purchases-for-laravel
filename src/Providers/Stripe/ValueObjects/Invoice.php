<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

final class Invoice implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $status,
        public readonly ?Money $amountPaid,
        public readonly ?string $customer,
        public readonly ?string $subscription,
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
            status: $dataset->value('status'),
            amountPaid: StripeMoney::fromDataSet($dataset, 'amount_paid', 'currency'),
            customer: $dataset->value('customer'),
            // `parent.subscription_details.subscription` since API version 2025-03-31.
            subscription: $dataset->value('parent.subscription_details.subscription') ?? $dataset->value('subscription'),
            raw: $raw,
        );
    }
}
