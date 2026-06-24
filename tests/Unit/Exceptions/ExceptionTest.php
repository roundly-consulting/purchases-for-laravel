<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Exceptions\Exception;
use RoundlyConsulting\Purchases\Exceptions\InvalidMoneyException;
use RoundlyConsulting\Purchases\Exceptions\InvalidProviderNotificationException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;

it('builds a base exception with a message and code', function (): void {
    $exception = Exception::because('boom', 7);

    expect($exception)->toBeInstanceOf(Exception::class)
        ->and($exception->getMessage())->toBe('boom')
        ->and($exception->getCode())->toBe(7);
});

it('returns the concrete subclass from the because factory', function (string $class): void {
    expect($class::because('nope'))->toBeInstanceOf($class);
})->with([
    VerificationException::class,
    InvalidProviderNotificationException::class,
    InvalidMoneyException::class,
]);
