<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\OfferType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Ownership;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\ProductType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\RevocationReason;
use RoundlyConsulting\Purchases\Support\DataSet;

/**
 * `price` is in milli-units of `currency` (USD 1.99 arrives as `1990`).
 *
 * @link https://developer.apple.com/documentation/appstoreservernotifications/jwstransaction
 * @link https://developer.apple.com/documentation/appstoreserverapi/price
 */
final class TransactionInfo extends BaseValueObject implements FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $appAccountToken,
        public readonly ?string $bundleId,
        public readonly Environment $environment,
        public readonly ?Carbon $expiresDate,
        public readonly ?Carbon $purchaseDate,
        public readonly ?Carbon $originalPurchaseDate,
        public readonly ?Carbon $revocationDate,
        public readonly ?RevocationReason $revocationReason,
        public readonly ?Ownership $inAppOwnershipType,
        public readonly ?bool $isUpgraded,
        public readonly ?string $transactionId,
        public readonly ?string $originalTransactionId,
        public readonly ?string $productId,
        public readonly ?int $quantity,
        public readonly ?string $webOrderLineItemId,
        public readonly ?string $subscriptionGroupIdentifier,
        public readonly ?string $offerIdentifier,
        public readonly ?OfferType $offerType,
        public readonly ?ProductType $type,
        public readonly array $raw,
        public readonly ?int $price = null,
        public readonly ?string $currency = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $dataset = new DataSet($raw);

        return new self(
            appAccountToken: $dataset->value('appAccountToken'),
            bundleId: $dataset->value('bundleId'),
            environment: $dataset->enum('environment', Environment::class),
            expiresDate: $dataset->datetime('expiresDate'),
            purchaseDate: $dataset->datetime('purchaseDate'),
            originalPurchaseDate: $dataset->datetime('originalPurchaseDate'),
            revocationDate: $dataset->datetime('revocationDate'),
            revocationReason: $dataset->enum('revocationReason', RevocationReason::class),
            inAppOwnershipType: $dataset->enum('inAppOwnershipType', Ownership::class),
            isUpgraded: $dataset->bool('isUpgraded'),
            transactionId: $dataset->value('transactionId'),
            originalTransactionId: $dataset->value('originalTransactionId'),
            productId: $dataset->value('productId'),
            quantity: $dataset->int('quantity'),
            webOrderLineItemId: $dataset->value('webOrderLineItemId'),
            subscriptionGroupIdentifier: $dataset->value('subscriptionGroupIdentifier'),
            offerIdentifier: $dataset->value('offerIdentifier'),
            offerType: $dataset->enum('offerType', OfferType::class),
            type: $dataset->enum('type', ProductType::class),
            price: is_int($price = $dataset->value('price')) ? $price : null,
            currency: is_string($currency = $dataset->value('currency')) && $currency !== '' ? $currency : null,
            // Last: it snapshots only the keys read above.
            raw: $dataset->retrieved(),
        );
    }
}
