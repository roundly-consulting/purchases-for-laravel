<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\AutoRenewStatus;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\ExpirationIntent;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\PriceIncreaseConsent;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\RetryPeriod;
use RoundlyConsulting\Purchases\Support\DataSet;

final class PendingRenewal extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $autoRenewProductId,
        public readonly string $originalTransationId,
        public readonly string $productId,
        public readonly ?AutoRenewStatus $autoRenewStatus,
        public readonly ?ExpirationIntent $expirationIntent,
        public readonly ?Carbon $gracePeriodExpiresDate,
        public readonly ?RetryPeriod $retryPeriod,
        public readonly ?string $offerCodeRefName,
        public readonly ?string $promotionalOfferId,
        public readonly ?PriceIncreaseConsent $priceConsent,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            autoRenewProductId: $dataset->value('auto_renew_product_id'),
            originalTransationId: $dataset->value('original_transaction_id'),
            productId: $dataset->value('product_id'),
            autoRenewStatus: $dataset->enum('auto_renew_status', AutoRenewStatus::class),
            expirationIntent: $dataset->enum('expiration_intent', ExpirationIntent::class),
            gracePeriodExpiresDate: $dataset->datetime('grace_period_expires_date_ms'),
            retryPeriod: $dataset->enum('is_in_billing_retry_period', RetryPeriod::class),
            offerCodeRefName: $dataset->value('offer_code_ref_name'),
            promotionalOfferId: $dataset->value('promotional_offer_id'),
            priceConsent: $dataset->enum('price_consent_status', PriceIncreaseConsent::class),
            raw: $dataset->retrieved(),
        );
    }
}
