<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Google\Auth\ServiceAccountCredentials;

it('builds from config', function (): void {
    $credentials = ServiceAccountCredentials::fromConfig([
        'client_email' => 'svc@example.com',
        'private_key' => 'KEY',
        'token_uri' => 'https://custom.example/token',
    ]);

    expect($credentials->clientEmail)->toBe('svc@example.com')
        ->and($credentials->privateKey)->toBe('KEY')
        ->and($credentials->tokenUri)->toBe('https://custom.example/token');
});

it('falls back to the default token uri', function (): void {
    $credentials = ServiceAccountCredentials::fromConfig([
        'client_email' => 'svc@example.com',
        'private_key' => 'KEY',
    ]);

    expect($credentials->tokenUri)->toBe('https://oauth2.googleapis.com/token');
});

it('throws when credentials are missing', function (): void {
    ServiceAccountCredentials::fromConfig(['client_email' => '', 'private_key' => '']);
})->throws(VerificationException::class);
