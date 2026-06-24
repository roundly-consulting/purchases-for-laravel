<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\FromRaw;
use RoundlyConsulting\Purchases\Providers\Google\Enums\AcknowledgementState;
use RoundlyConsulting\Purchases\Providers\Google\Enums\ConsumptionState;
use RoundlyConsulting\Purchases\Providers\Google\Enums\PurchaseState;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * A one-time in-app product purchase from the Play Developer API.
 *
 * @link https://developer.android.com/google/play/billing/rtdn-reference
 */
final class ProductPurchase implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $purchaseTimeMillis,
        public readonly ?Carbon $purchaseTime,
        public readonly ?PurchaseState $purchaseState,
        public readonly ?ConsumptionState $consumptionState,
        public readonly ?AcknowledgementState $acknowledgementState,
        public readonly ?string $orderId,
        public readonly ?string $productId,
        public readonly ?string $regionCode,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            purchaseTimeMillis: $dataset->int('purchaseTimeMillis'),
            purchaseTime: $dataset->datetime('purchaseTimeMillis'),
            purchaseState: $dataset->enum('purchaseState', PurchaseState::class),
            consumptionState: $dataset->enum('consumptionState', ConsumptionState::class),
            acknowledgementState: $dataset->enum('acknowledgementState', AcknowledgementState::class),
            orderId: $dataset->value('orderId'),
            productId: $dataset->value('productId'),
            regionCode: $dataset->value('regionCode'),
            raw: $raw,
        );
    }

    public function isPurchased(): bool
    {
        return $this->purchaseState?->isPurchased() ?? false;
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledgementState?->isAcknowledged() ?? false;
    }
}
