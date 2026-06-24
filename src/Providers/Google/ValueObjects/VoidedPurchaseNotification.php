<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\ValueObjects;

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Support\DataSet;

final class VoidedPurchaseNotification implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $purchaseToken,
        public readonly ?string $orderId,
        public readonly ?int $productType,
        public readonly ?int $refundType,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            purchaseToken: $dataset->value('purchaseToken'),
            orderId: $dataset->value('orderId'),
            productType: $dataset->int('productType'),
            refundType: $dataset->int('refundType'),
            raw: $raw,
        );
    }
}
