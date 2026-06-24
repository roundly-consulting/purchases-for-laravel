<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A single line item within a subscriptionsv2 purchase.
 */
final class SubscriptionLineItem implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $productId,
        public readonly ?Carbon $expiryTime,
        public readonly ?string $offerId,
        public readonly ?string $basePlanId,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            productId: $dataset->value('productId'),
            expiryTime: $dataset->timestamp('expiryTime'),
            offerId: $dataset->value('offerDetails.offerId'),
            basePlanId: $dataset->value('offerDetails.basePlanId'),
            raw: $raw,
        );
    }
}
