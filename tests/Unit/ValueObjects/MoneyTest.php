<?php

declare(strict_types=1);

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
