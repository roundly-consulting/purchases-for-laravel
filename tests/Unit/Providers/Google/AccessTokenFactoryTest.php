<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Google\Auth\AccessTokenFactory;
use RoundlyConsulting\Purchases\Providers\Google\Auth\ServiceAccountCredentials;
use RoundlyConsulting\Purchases\Support\Base64Url;

function rsaKeyPair(): array
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($resource, $private);
    $details = openssl_pkey_get_details($resource);

    return [$private, $details['key']];
}

afterEach(function (): void {
    Cache::flush();
    Carbon::setTestNow();
});

it('builds and signs a verifiable jwt-bearer assertion', function (): void {
    [$private, $public] = rsaKeyPair();
    $credentials = new ServiceAccountCredentials('svc@example.com', $private);

    $assertion = (new AccessTokenFactory)->assertion($credentials);

    [$header, $claims, $signature] = explode('.', $assertion);

    $valid = openssl_verify(
        "{$header}.{$claims}",
        Base64Url::decode($signature),
        $public,
        OPENSSL_ALGO_SHA256,
    );

    $decodedClaims = json_decode(Base64Url::decode($claims), true);

    expect($valid)->toBe(1)
        ->and($decodedClaims['iss'])->toBe('svc@example.com')
        ->and($decodedClaims['scope'])->toBe('https://www.googleapis.com/auth/androidpublisher');
});

it('throws on an invalid private key', function (): void {
    $credentials = new ServiceAccountCredentials('svc@example.com', 'not-a-key');

    (new AccessTokenFactory)->assertion($credentials);
})->throws(VerificationException::class);

it('caches the access token across calls', function (): void {
    [$private] = rsaKeyPair();
    $credentials = new ServiceAccountCredentials('svc@example.com', $private);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::sequence()
            ->push(['access_token' => 'token-A', 'expires_in' => 3600])
            ->push(['access_token' => 'token-B', 'expires_in' => 3600]),
    ]);

    $factory = new AccessTokenFactory;

    expect($factory->token($credentials))->toBe('token-A')
        ->and($factory->token($credentials))->toBe('token-A');

    Http::assertSentCount(1);
});

it('refreshes the token after the cache expires', function (): void {
    [$private] = rsaKeyPair();
    $credentials = new ServiceAccountCredentials('svc@example.com', $private);

    Http::fake([
        'oauth2.googleapis.com/*' => Http::sequence()
            ->push(['access_token' => 'token-A', 'expires_in' => 3600])
            ->push(['access_token' => 'token-B', 'expires_in' => 3600]),
    ]);

    $factory = new AccessTokenFactory;

    Carbon::setTestNow('2026-01-01 12:00:00');
    expect($factory->token($credentials))->toBe('token-A');

    Carbon::setTestNow('2026-01-01 13:00:00');
    expect($factory->token($credentials))->toBe('token-B');
});

it('throws when the token endpoint returns no access token', function (): void {
    [$private] = rsaKeyPair();
    $credentials = new ServiceAccountCredentials('svc@example.com', $private);

    Http::fake(['oauth2.googleapis.com/*' => Http::response([])]);

    (new AccessTokenFactory)->token($credentials);
})->throws(VerificationException::class);
