<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Google\GoogleMoney;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\SubscriptionLineItem;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\SubscriptionPurchase;

it('converts a play money object without int arithmetic', function (array $money, string $minor, string $currency): void {
    $price = GoogleMoney::fromMoney($money);

    expect($price?->minor())->toBe($minor)
        ->and($price?->currency()->code)->toBe($currency);
})->with([
    'USD 12.99' => [['currencyCode' => 'USD', 'units' => '12', 'nanos' => 990000000], '1299', 'USD'],
    'units only' => [['currencyCode' => 'EUR', 'units' => '5'], '500', 'EUR'],
    'nanos only' => [['currencyCode' => 'USD', 'nanos' => 750000000], '75', 'USD'],
    'nanos round half away from zero' => [['currencyCode' => 'USD', 'units' => '1', 'nanos' => 995000000], '200', 'USD'],
    'negative -1.75' => [['currencyCode' => 'USD', 'units' => '-1', 'nanos' => -750000000], '-175', 'USD'],
    'JPY drops sub-yen nanos' => [['currencyCode' => 'JPY', 'units' => '300', 'nanos' => 400000000], '300', 'JPY'],
    'BHD keeps three decimals' => [['currencyCode' => 'BHD', 'units' => '1', 'nanos' => 995000000], '1995', 'BHD'],
    'int units' => [['currencyCode' => 'USD', 'units' => 3], '300', 'USD'],
    'units beyond 64 bits' => [['currencyCode' => 'USD', 'units' => '123456789012345678901234'], '12345678901234567890123400', 'USD'],
    'zero (all fields omitted)' => [['currencyCode' => 'USD'], '0', 'USD'],
]);

it('returns null for a malformed play money object', function (mixed $money): void {
    expect(GoogleMoney::fromMoney($money))->toBeNull();
})->with([
    'not an array' => ['12.99 USD'],
    'no currency' => [['units' => '12']],
    'empty currency' => [['currencyCode' => '', 'units' => '12']],
    'unknown currency' => [['currencyCode' => 'ZZZ', 'units' => '12']],
    'fractional units' => [['currencyCode' => 'USD', 'units' => '12.5']],
    'float units' => [['currencyCode' => 'USD', 'units' => 12.5]],
    'string nanos' => [['currencyCode' => 'USD', 'units' => '12', 'nanos' => '990000000']],
    'nanos out of range' => [['currencyCode' => 'USD', 'units' => '12', 'nanos' => 1000000000]],
]);

it('reads the recurring price of an auto-renewing line item', function (): void {
    $item = SubscriptionLineItem::fromRaw([
        'productId' => 'pro.monthly',
        'autoRenewingPlan' => ['recurringPrice' => ['currencyCode' => 'USD', 'units' => '4', 'nanos' => 990000000]],
    ]);

    expect($item->recurringPrice?->minor())->toBe('499');
});

it('leaves a prepaid line item without a price', function (): void {
    expect(SubscriptionLineItem::fromRaw(['productId' => 'pass', 'prepaidPlan' => []])->recurringPrice)->toBeNull();
});

it('sums the line items into the subscription price', function (): void {
    $purchase = SubscriptionPurchase::fromRaw([
        'lineItems' => [
            ['productId' => 'base', 'autoRenewingPlan' => ['recurringPrice' => ['currencyCode' => 'EUR', 'units' => '9', 'nanos' => 990000000]]],
            ['productId' => 'prepaid', 'prepaidPlan' => []],
            ['productId' => 'addon', 'autoRenewingPlan' => ['recurringPrice' => ['currencyCode' => 'EUR', 'units' => '2']]],
        ],
    ]);

    expect($purchase->price()?->minor())->toBe('1199')
        ->and($purchase->price()?->currency()->code)->toBe('EUR');
});

it('has no subscription price without priced line items or across currencies', function (array $lineItems): void {
    expect(SubscriptionPurchase::fromRaw(['lineItems' => $lineItems])->price())->toBeNull();
})->with([
    'no line items' => [[]],
    'prepaid only' => [[['productId' => 'pass', 'prepaidPlan' => []]]],
    'mixed currencies' => [[
        ['productId' => 'a', 'autoRenewingPlan' => ['recurringPrice' => ['currencyCode' => 'EUR', 'units' => '1']]],
        ['productId' => 'b', 'autoRenewingPlan' => ['recurringPrice' => ['currencyCode' => 'USD', 'units' => '1']]],
    ]],
]);
