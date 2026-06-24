<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Ownership;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\RevocationReason;
use RoundlyConsulting\Purchases\Support\DataSet;

final class LatestReceiptInfo extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $originalTransactionId,
        public readonly string $transactionId,
        public readonly string $productId,
        public readonly int $quantity,
        public readonly ?Carbon $expiresDate,
        public readonly ?Carbon $cancellationDate,
        public readonly ?Carbon $originalPurchaseDate,
        public readonly ?Carbon $purchaseDate,
        public readonly ?RevocationReason $cancellationReason,
        public readonly ?Ownership $inAppOwnershipType,
        public readonly bool $isInIntroOfferPeriod,
        public readonly bool $isTrialPeriod,
        public readonly bool $isUpgraded,
        public readonly ?string $offerCodeRefName,
        public readonly ?string $promotionalOfferId,
        public readonly ?string $subscriptionGroupIdentifier,
        public readonly ?string $webOrderLineItemId,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            originalTransactionId: $dataset->value('original_transaction_id'),
            transactionId: $dataset->value('transaction_id'),
            productId: $dataset->value('product_id'),
            quantity: $dataset->int('quantity', 1),
            expiresDate: $dataset->datetime('expires_date_ms'),
            cancellationDate: $dataset->datetime('cancellation_date_ms'),
            originalPurchaseDate: $dataset->datetime('original_purchase_date_ms'),
            purchaseDate: $dataset->datetime('purchase_date_ms'),
            cancellationReason: $dataset->enum('cancellation_reason', RevocationReason::class),
            inAppOwnershipType: $dataset->enum('in_app_ownership_type', Ownership::class),
            isInIntroOfferPeriod: $dataset->bool('is_in_intro_offer_period'),
            isTrialPeriod: $dataset->bool('is_trial_period'),
            isUpgraded: $dataset->bool('is_upgraded'),
            offerCodeRefName: $dataset->value('offer_code_ref_name'),
            promotionalOfferId: $dataset->value('promotional_offer_id'),
            subscriptionGroupIdentifier: $dataset->value('subscription_group_identifier'),
            webOrderLineItemId: $dataset->value('web_order_line_item_id'),
            raw: $dataset->retrieved(),
        );
    }
}
