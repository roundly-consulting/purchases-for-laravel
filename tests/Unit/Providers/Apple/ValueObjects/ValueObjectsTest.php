<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\AutoRenewStatus;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\OfferType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Ownership;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\ProductType;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\AppMetadata;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\AutoRenew;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\LatestReceiptInfo;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\Offer;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\PendingRenewal;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\Receipt;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ReceiptStatus;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\RenewalInfo;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\TransactionInfo;

it('builds app metadata from raw claims', function (): void {
    $meta = AppMetadata::fromRaw([
        'appAppleId' => '999',
        'bundleId' => 'com.example',
        'bundleVersion' => '3.1',
        'environment' => 'Production',
    ]);

    expect($meta->appAppleId)->toBe('999')
        ->and($meta->environment)->toBe(Environment::Production)
        ->and($meta->toArray())->toMatchArray(['bundleId' => 'com.example']);
});

it('builds auto-renew details', function (): void {
    $autoRenew = AutoRenew::fromRaw([
        'autoRenewProductId' => 'product.1',
        'autoRenewStatus' => 1,
    ]);

    expect($autoRenew->autoRenewProductId)->toBe('product.1')
        ->and($autoRenew->autoRenewStatus)->toBe(AutoRenewStatus::Active);
});

it('builds an offer', function (): void {
    $offer = Offer::fromRaw([
        'offerIdentifier' => 'promo',
        'offerType' => 1,
    ]);

    expect($offer->offerIdentifier)->toBe('promo')
        ->and($offer->offerType)->toBe(OfferType::Introductory);
});

it('builds latest receipt info with date and enum mapping', function (): void {
    $info = LatestReceiptInfo::fromRaw([
        'original_transaction_id' => 'orig-1',
        'transaction_id' => 'txn-1',
        'product_id' => 'prod-1',
        'quantity' => '2',
        'purchase_date_ms' => 1700000000000,
        'in_app_ownership_type' => 'PURCHASED',
        'is_trial_period' => 'true',
    ]);

    expect($info->transactionId)->toBe('txn-1')
        ->and($info->quantity)->toBe(2)
        ->and($info->purchaseDate)->toBeInstanceOf(CarbonInterface::class)
        ->and($info->inAppOwnershipType)->toBe(Ownership::Purchased)
        ->and($info->isTrialPeriod)->toBeTrue();
});

it('builds pending renewal info', function (): void {
    $renewal = PendingRenewal::fromRaw([
        'auto_renew_product_id' => 'p',
        'original_transaction_id' => 'o',
        'product_id' => 'prod',
        'auto_renew_status' => 0,
    ]);

    expect($renewal->productId)->toBe('prod')
        ->and($renewal->autoRenewStatus)->toBe(AutoRenewStatus::Inactive);
});

it('builds transaction info with product type', function (): void {
    $info = TransactionInfo::fromRaw([
        'transactionId' => 'txn',
        'productId' => 'prod',
        'environment' => 'Sandbox',
        'type' => 'Auto-Renewable Subscription',
        'inAppOwnershipType' => 'FAMILY_SHARED',
    ]);

    expect($info->transactionId)->toBe('txn')
        ->and($info->environment)->toBe(Environment::Sandbox)
        ->and($info->type)->toBe(ProductType::AutoRenewableSubscription)
        ->and($info->inAppOwnershipType)->toBe(Ownership::FamilyShared);
});

it('builds renewal info including nested auto-renew and offer', function (): void {
    $renewal = RenewalInfo::fromRaw([
        'environment' => 'Production',
        'originalTransactionId' => 'orig',
        'productId' => 'prod',
        'autoRenewProductId' => 'auto',
        'autoRenewStatus' => 1,
        'offerIdentifier' => 'promo',
        'offerType' => 1,
    ]);

    expect($renewal->autoRenew)->toBeInstanceOf(AutoRenew::class)
        ->and($renewal->autoRenew->autoRenewStatus)->toBe(AutoRenewStatus::Active)
        ->and($renewal->offer)->toBeInstanceOf(Offer::class)
        ->and($renewal->offer->offerType)->toBe(OfferType::Introductory)
        ->and($renewal->environment)->toBe(Environment::Production);
});

it('builds a receipt with a nested list of latest receipt info', function (): void {
    $receipt = Receipt::fromRaw([
        'adam_id' => 'adam',
        'bundle_id' => 'com.example',
        'receipt_creation_date_ms' => 1700000000000,
        'in_app' => [
            ['transaction_id' => 't1', 'original_transaction_id' => 'o1', 'product_id' => 'p1'],
        ],
    ]);

    expect($receipt->adamId)->toBe('adam')
        ->and($receipt->latestReceiptInfo)->toHaveCount(1)
        ->and($receipt->latestReceiptInfo[0])->toBeInstanceOf(LatestReceiptInfo::class)
        ->and($receipt->receiptCreationDate)->toBeInstanceOf(CarbonInterface::class);
});

it('describes receipt statuses', function (): void {
    expect((new ReceiptStatus(0))->isValid())->toBeTrue()
        ->and((new ReceiptStatus(21006))->isValid())->toBeTrue()
        ->and((new ReceiptStatus(21003))->isValid())->toBeFalse()
        ->and((new ReceiptStatus(21000))->message())->toContain('HTTP POST')
        ->and((new ReceiptStatus(99999))->message())->toContain('Unknown receipt status');
});

it('maps every documented apple receipt status code to a message', function (int $code): void {
    expect((new ReceiptStatus($code))->message())
        ->toStartWith('['.$code.']')
        ->not->toContain('Unknown receipt status');
})->with([21000, 21001, 21002, 21003, 21004, 21005, 21006, 21007, 21008, 21009, 21010]);
