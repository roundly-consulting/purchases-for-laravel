<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\ExpirationIntent;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\PriceIncreaseConsent;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * @see https://developer.apple.com/documentation/appstoreservernotifications/jwsrenewalinfo
 */
final class RenewalInfo extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly AutoRenew $autoRenew,
        public readonly Environment $environment,
        public readonly ?ExpirationIntent $expirationIntent,
        public readonly ?Carbon $gracePeriodExpiresDate,
        public readonly ?Carbon $recentSubscriptionStartDate,
        public readonly ?bool $isInBillingRetryPeriod,
        public readonly Offer $offer,
        public readonly ?string $originalTransactionId,
        public readonly ?string $productId,
        public readonly ?PriceIncreaseConsent $priceIncreaseStatus,
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            autoRenew: AutoRenew::fromRaw($raw),
            environment: $dataset->enum('environment', Environment::class),
            expirationIntent: $dataset->enum('expirationIntent', ExpirationIntent::class),
            gracePeriodExpiresDate: $dataset->datetime('gracePeriodExpiresDate'),
            recentSubscriptionStartDate: $dataset->datetime('recentSubscriptionStartDate'),
            isInBillingRetryPeriod: $dataset->bool('isInBillingRetryPeriod'),
            offer: Offer::fromRaw($raw),
            originalTransactionId: $dataset->value('originalTransactionId'),
            productId: $dataset->value('productId'),
            priceIncreaseStatus: $dataset->enum('priceIncreaseStatus', PriceIncreaseConsent::class),
            raw: $dataset->retrieved(),
        );
    }
}
