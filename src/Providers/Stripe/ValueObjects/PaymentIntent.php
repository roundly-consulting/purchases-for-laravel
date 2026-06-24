<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Providers\Stripe\Enums\PaymentIntentStatus;
use RoundlyConsulting\Purchases\Support\DataSet;
use RoundlyConsulting\Purchases\ValueObjects\Money;

final class PaymentIntent implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?PaymentIntentStatus $status,
        public readonly ?Money $amount,
        public readonly ?string $customer,
        public readonly ?string $description,
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
            status: $dataset->enum('status', PaymentIntentStatus::class),
            amount: StripeMoney::fromDataSet($dataset, 'amount', 'currency'),
            customer: $dataset->value('customer'),
            description: $dataset->value('description'),
            raw: $raw,
        );
    }
}
