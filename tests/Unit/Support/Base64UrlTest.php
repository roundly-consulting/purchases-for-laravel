<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Support\Base64Url;

it('round-trips arbitrary binary data', function (string $value): void {
    expect(Base64Url::decode(Base64Url::encode($value)))->toBe($value);
})->with([
    'empty' => '',
    'ascii' => 'hello world',
    'binary' => "\x00\xff\x10\x80\x7f",
    'json' => '{"alg":"ES256"}',
]);

it('produces url-safe output without padding', function (): void {
    $encoded = Base64Url::encode("\xfb\xff\xfe");

    expect($encoded)
        ->not->toContain('+')
        ->not->toContain('/')
        ->not->toContain('=');
});

it('decodes segments that lack padding', function (): void {
    expect(Base64Url::decode('aGVsbG8'))->toBe('hello');
});

it('throws on an invalid base64url segment', function (): void {
    Base64Url::decode('not valid base64!!');
})->throws(VerificationException::class);
