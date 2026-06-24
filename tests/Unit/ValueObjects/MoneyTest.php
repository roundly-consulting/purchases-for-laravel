<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Exceptions\CurrencyMismatchException;
use RoundlyConsulting\Purchases\Exceptions\InvalidMoneyException;
use RoundlyConsulting\Purchases\ValueObjects\Money;

it('stores a minor-unit amount and an uppercased currency', function (): void {
    $money = new Money(1599, 'usd');

    expect($money->amount)->toBe(1599)
        ->and($money->currency)->toBe('USD');
});

it('exposes its array representation', function (): void {
    expect((new Money(500, 'EUR'))->toArray())->toBe([
        'amount' => 500,
        'currency' => 'EUR',
    ]);
});

it('compares two amounts for equality', function (): void {
    expect((new Money(100, 'USD'))->equals(new Money(100, 'USD')))->toBeTrue()
        ->and((new Money(100, 'USD'))->equals(new Money(100, 'EUR')))->toBeFalse()
        ->and((new Money(100, 'USD'))->equals(new Money(200, 'USD')))->toBeFalse();
});

it('reports whether two values share a currency', function (): void {
    expect((new Money(1, 'USD'))->isSameCurrency(new Money(999, 'USD')))->toBeTrue()
        ->and((new Money(1, 'USD'))->isSameCurrency(new Money(1, 'GBP')))->toBeFalse();
});

it('rejects an invalid currency code', function (string $currency): void {
    new Money(100, $currency);
})->throws(InvalidMoneyException::class)->with([
    'too short' => 'US',
    'too long' => 'USDD',
    'numeric' => '123',
    'empty' => '',
]);

it('builds zero and arbitrary amounts', function (): void {
    expect(Money::zero('USD')->amount)->toBe(0)
        ->and(Money::of(500, 'eur')->currency)->toBe('EUR');
});

it('adds, subtracts, and multiplies amounts immutably', function (): void {
    $a = new Money(1000, 'USD');
    $b = new Money(250, 'USD');

    expect($a->plus($b)->amount)->toBe(1250)
        ->and($a->minus($b)->amount)->toBe(750)
        ->and($a->times(3)->amount)->toBe(3000)
        ->and($a->amount)->toBe(1000);
});

it('reports sign and zero predicates', function (): void {
    expect(Money::zero('USD')->isZero())->toBeTrue()
        ->and((new Money(1, 'USD'))->isPositive())->toBeTrue()
        ->and((new Money(-1, 'USD'))->isNegative())->toBeTrue();
});

it('compares amounts in the same currency', function (): void {
    expect((new Money(200, 'USD'))->greaterThan(new Money(100, 'USD')))->toBeTrue()
        ->and((new Money(100, 'USD'))->lessThan(new Money(200, 'USD')))->toBeTrue();
});

it('throws when operating across currencies', function (string $method): void {
    (new Money(100, 'USD'))->{$method}(new Money(100, 'EUR'));
})->throws(CurrencyMismatchException::class)->with([
    'plus' => 'plus',
    'minus' => 'minus',
    'greaterThan' => 'greaterThan',
    'lessThan' => 'lessThan',
]);

it('formats an amount as a currency string', function (): void {
    $formatted = (new Money(1599, 'USD'))->format('en_US');

    expect($formatted)->toContain('15')->toContain('99');
});
