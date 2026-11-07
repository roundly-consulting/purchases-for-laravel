<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects;

use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

final class CheckoutSession implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $paymentStatus,
        public readonly ?Money $amountTotal,
        public readonly ?string $customer,
        public readonly ?string $subscription,
        public readonly ?string $paymentIntent,
        public readonly array $raw,
        public readonly ?string $url = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            id: $dataset->value('id'),
            paymentStatus: $dataset->value('payment_status'),
            amountTotal: StripeMoney::fromDataSet($dataset, 'amount_total', 'currency'),
            customer: $dataset->value('customer'),
            subscription: $dataset->value('subscription'),
            paymentIntent: $dataset->value('payment_intent'),
            raw: $raw,
            // Where to send the customer for a hosted Checkout page.
            url: $dataset->value('url'),
        );
    }
}
