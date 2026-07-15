<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Algorithm;
use RoundlyConsulting\Crypto\Signature\InvalidSignatureException;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Crypto\Signature\Rs;
use RoundlyConsulting\Crypto\Testing\TestKeys;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Google\Auth\AccessTokenFactory;
use RoundlyConsulting\Purchases\Providers\Google\Auth\ServiceAccountCredentials;

/**
 * @return array{0: string, 1: string} private and public PEM
 */
function rsaKeyPair(int $bits = 2048): array
{
    $key = TestKeys::rsa($bits);

    return [$key->privatePem(), $key->publicPem()];
}

afterEach(function (): void {
    Cache::flush();
    Carbon::setTestNow();
});

it('mints an rs256 assertion google would accept', function (): void {
    [$private, $public] = rsaKeyPair();
    $credentials = new ServiceAccountCredentials('svc@example.com', $private);

    $assertion = (new AccessTokenFactory)->assertion($credentials);

    // Verify exactly as Google's token endpoint would: RS256, pinned, against
    // the service account's public key.
    $claims = (new Jws)->verify($assertion, new Rs(RsaKey::public($public)), Algorithm::RS256);

    [$header] = explode('.', $assertion);

    expect(json_decode(Base64Url::decode($header), true)['alg'])->toBe('RS256')
        ->and($claims->string('iss'))->toBe('svc@example.com')
        ->and($claims->string('scope'))->toBe('https://www.googleapis.com/auth/androidpublisher')
        ->and($claims->string('aud'))->toBe('https://oauth2.googleapis.com/token')
        ->and($claims->int('exp') - $claims->int('iat'))->toBe(3600);
});

it('rejects an assertion verified against an unrelated key', function (): void {
    [$private] = rsaKeyPair();
    [, $otherPublic] = rsaKeyPair();

    $assertion = (new AccessTokenFactory)->assertion(new ServiceAccountCredentials('svc@example.com', $private));

    (new Jws)->verify($assertion, new Rs(RsaKey::public($otherPublic)), Algorithm::RS256);
})->throws(InvalidSignatureException::class);

it('throws on an invalid private key', function (): void {
    $credentials = new ServiceAccountCredentials('svc@example.com', 'not-a-key');

    (new AccessTokenFactory)->assertion($credentials);
})->throws(VerificationException::class, 'Invalid Google service-account private key.');

it('rejects a service-account key that is too weak to sign with', function (): void {
    // A committed 1024-bit key: crypto refuses to *generate* one, and this
    // package must refuse to *sign* with one.
    $private = (string) file_get_contents(__DIR__.'/../../../Fixtures/google/weak-rsa-1024.pem');

    (new AccessTokenFactory)->assertion(new ServiceAccountCredentials('svc@example.com', $private));
})->throws(VerificationException::class, 'Invalid Google service-account private key.');

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
