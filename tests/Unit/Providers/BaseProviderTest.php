<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Purchases\Exceptions\InvalidProviderNotificationException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\BaseProvider;

function bareProvider(): BaseProvider
{
    return new class extends BaseProvider {};
}

it('derives a kebab-case id from the class name', function (): void {
    expect(bareProvider()->id())->toBeString();
});

it('throws when notification handling is undefined', function (): void {
    bareProvider()->notification(new Request);
})->throws(InvalidProviderNotificationException::class);

it('throws when callback verification is undefined', function (): void {
    bareProvider()->callback(new Request);
})->throws(VerificationException::class);

it('throws when result mapping is undefined', function (): void {
    bareProvider()->result(new Request);
})->throws(VerificationException::class);
