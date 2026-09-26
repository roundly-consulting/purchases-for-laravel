<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Purchases\Providers\Stripe\StripeAmount;
use RoundlyConsulting\Purchases\Providers\Stripe\ValueObjects\StripeMoney;
use RoundlyConsulting\Purchases\Support\DataSet;

it('pins the documented stripe scale exceptions', function (): void {
    expect(StripeAmount::SCALE_EXCEPTIONS)->toBe(['ISK' => 2, 'MGA' => 0, 'UGX' => 2]);
});

it('reads iso minor units for ordinary currencies', function (int|string $amount, string $currency, string $minor, string $decimal): void {
    $money = StripeAmount::toMoney($amount, $currency);

    expect($money->minor())->toBe($minor)
        ->and($money->toDecimal())->toBe($decimal)
        ->and($money->currency()->code)->toBe(strtoupper($currency));
})->with([
    'usd' => [1999, 'usd', '1999', '19.99'],
    'jpy (zero-decimal)' => [500, 'jpy', '500', '500'],
    'bhd (three-decimal)' => [1990, 'bhd', '1990', '1.990'],
    'huf (two-decimal for charges)' => [1045, 'huf', '1045', '10.45'],
    'twd (two-decimal for charges)' => [80045, 'twd', '80045', '800.45'],
    'integer string' => ['4200', 'eur', '4200', '42.00'],
    'beyond 64 bits' => ['123456789012345678901234', 'usd', '123456789012345678901234', '1234567890123456789012.34'],
]);

it('re-scales each stripe exception to its iso exponent', function (int $amount, string $currency, string $minor, string $decimal): void {
    $money = StripeAmount::toMoney($amount, $currency);

    expect($money->minor())->toBe($minor)
        ->and($money->toDecimal())->toBe($decimal);
})->with([
    'isk: 5 ISK arrives as 500' => [500, 'isk', '5', '5'],
    'ugx: 5 UGX arrives as 500' => [500, 'ugx', '5', '5'],
    'mga: 500 MGA arrives as 500' => [500, 'mga', '50000', '500.00'],
]);

it('refuses an exception amount that does not re-scale exactly', function (): void {
    StripeAmount::toMoney(550, 'isk');
})->throws(RoundingNecessary::class);

it('builds money from a stripe object', function (): void {
    $money = StripeMoney::fromDataSet(new DataSet(['amount' => 500, 'currency' => 'isk']), 'amount', 'currency');

    expect($money?->minor())->toBe('5')
        ->and($money?->currency()->code)->toBe('ISK');
});

it('returns null instead of truncating a non-integer amount', function (mixed $amount): void {
    expect(StripeMoney::fromDataSet(new DataSet(['amount' => $amount, 'currency' => 'usd']), 'amount', 'currency'))->toBeNull();
})->with([
    'float' => [12.5],
    'decimal string' => ['12.5'],
    'exponent string' => ['1e3'],
    'empty string' => [''],
    'missing' => [null],
    'boolean' => [true],
]);

it('returns null for a missing, empty or unknown currency', function (mixed $currency): void {
    expect(StripeMoney::fromDataSet(new DataSet(['amount' => 100, 'currency' => $currency]), 'amount', 'currency'))->toBeNull();
})->with([
    'missing' => [null],
    'empty' => [''],
    'unknown' => ['zzz'],
]);

it('returns null for an exception amount that does not re-scale exactly', function (): void {
    expect(StripeMoney::fromDataSet(new DataSet(['amount' => 550, 'currency' => 'isk']), 'amount', 'currency'))->toBeNull();
});
