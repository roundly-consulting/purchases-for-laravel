<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Jose\Jwk;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Hs;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Crypto\Signature\Rs;
use RoundlyConsulting\Crypto\Testing\TestKeys;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Google\Auth\PushAuthenticator;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Google\ValueObjects\DeveloperNotification;

/*
 * Google Cloud Pub/Sub push authentication. Everything is offline: Google's JWKS is an
 * Http::fake() of the configured URL, and the "Google" signing keys are test RSA keys.
 */

const PUSH_JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
const PUSH_AUDIENCE = 'https://app.test/purchases/webhooks/google';
const PUSH_SERVICE_ACCOUNT = 'rtdn-push@example-project.iam.gserviceaccount.com';

/** One stable "Google" signing key per name, so a JWKS and a token can share it. */
function pushSigningKey(string $name = 'google'): RsaKey
{
    static $keys = [];

    return $keys[$name] ??= TestKeys::rsa();
}

/**
 * The key as Google publishes it in its JWKS.
 *
 * @return array<string, string>
 */
function pushJwk(string $kid, string $name = 'google'): array
{
    return Jwk::fromPublicKey(RsaKey::public(pushSigningKey($name)->publicPem()))->jsonSerialize()
        + ['kid' => $kid, 'alg' => 'RS256', 'use' => 'sig'];
}

/**
 * A Pub/Sub push OIDC token: Google's claims, overridable per test.
 *
 * @param  array<string, mixed>  $claims
 */
function pushOidcToken(array $claims = [], string $kid = 'k1', string $key = 'google'): string
{
    $now = Carbon::now()->getTimestamp();

    return (new Jws)->sign(['kid' => $kid], $claims + [
        'iss' => 'https://accounts.google.com',
        'aud' => PUSH_AUDIENCE,
        'azp' => '112233445566778899',
        'sub' => '112233445566778899',
        'email' => PUSH_SERVICE_ACCOUNT,
        'email_verified' => true,
        'iat' => $now,
        'exp' => $now + 3600,
    ], new Rs(pushSigningKey($key)));
}

/** A Pub/Sub push carrying a test RTDN, optionally with a bearer token and a URL token. */
function pushRequest(?string $bearer = null, ?string $urlToken = null): Request
{
    $uri = '/purchases/webhooks/google'.($urlToken !== null ? '?token='.urlencode($urlToken) : '');

    $request = Request::create($uri, 'POST', ['message' => ['data' => Base64Url::encode((string) json_encode([
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'eventTimeMillis' => '1700000000000',
        'testNotification' => ['version' => '1.0'],
    ]))]]);

    if ($bearer !== null) {
        $request->headers->set('Authorization', 'Bearer '.$bearer);
    }

    return $request;
}

/**
 * @param  array<string, mixed>  $push
 */
function configurePush(array $push): void
{
    config()->set('purchases.settings.google', [
        'package_name' => 'com.example.app',
        'push' => $push + ['jwks_url' => PUSH_JWKS_URL, 'jwks_cache_ttl' => 3600],
    ]);
}

beforeEach(function (): void {
    Cache::flush();
    configurePush(['audience' => PUSH_AUDIENCE, 'service_account_email' => PUSH_SERVICE_ACCOUNT]);
    Http::fake([PUSH_JWKS_URL => Http::response(['keys' => [pushJwk('k1'), pushJwk('k0', 'old')]])]);
});

afterEach(fn () => Carbon::setTestNow());

it('accepts a push signed by google for the configured audience and service account', function (): void {
    expect((new Google)->notification(pushRequest(pushOidcToken())))->toBeInstanceOf(DeveloperNotification::class);
});

it('rejects a push without a bearer token', function (): void {
    (new Google)->notification(pushRequest());
})->throws(VerificationException::class, 'no OIDC bearer token');

it('rejects every push when authentication is on but not configured', function (): void {
    configurePush([]);

    (new Google)->notification(pushRequest(pushOidcToken()));
})->throws(VerificationException::class, 'not configured');

it('rejects a half-configured oidc setup', function (array $push): void {
    configurePush($push);

    (new Google)->notification(pushRequest(pushOidcToken()));
})->with([
    'audience only' => [['audience' => PUSH_AUDIENCE]],
    'service account only' => [['service_account_email' => PUSH_SERVICE_ACCOUNT]],
])->throws(VerificationException::class, 'needs both');

it('accepts an unauthenticated push only when authentication is explicitly off', function (): void {
    configurePush(['authenticate' => false]);

    expect((new Google)->notification(pushRequest()))->toBeInstanceOf(DeveloperNotification::class);
});

it('rejects a token google did not issue for this subscription', function (Closure $token, string $message): void {
    (new Google)->notification(pushRequest($token()));
})->with([
    'another audience' => [fn (): string => pushOidcToken(['aud' => 'https://evil.test/hook']), 'unexpected audience'],
    'another service account' => [fn (): string => pushOidcToken(['email' => 'someone@evil.iam.gserviceaccount.com']), 'configured service account'],
    'unverified email' => [fn (): string => pushOidcToken(['email_verified' => false]), 'unverified email'],
    'email_verified as a string' => [fn (): string => pushOidcToken(['email_verified' => 'true']), 'unverified email'],
    'another issuer' => [fn (): string => pushOidcToken(['iss' => 'https://evil.test']), 'not issued by Google'],
    'expired' => [fn (): string => pushOidcToken(['exp' => Carbon::now()->getTimestamp() - 120]), 'invalid'],
    'issued in the future' => [fn (): string => pushOidcToken(['iat' => Carbon::now()->getTimestamp() + 600]), 'invalid'],
    'signed by another key under a known kid' => [fn (): string => pushOidcToken([], 'k1', 'attacker'), 'invalid'],
    'unknown kid' => [fn (): string => pushOidcToken([], 'k9', 'attacker'), 'unknown key'],
    'an HS256 token' => [fn (): string => (new Jws)->sign(['kid' => 'k1'], ['aud' => PUSH_AUDIENCE], new Hs(TestKeys::hmacSecret())), 'invalid'],
    'no kid' => [fn (): string => (new Jws)->sign([], ['aud' => PUSH_AUDIENCE], new Rs(pushSigningKey())), 'names no signing key'],
    'garbage' => [fn (): string => 'not-a-jwt', 'malformed'],
])->throws(VerificationException::class);

it('reports why a token was refused', function (): void {
    expect(fn () => (new Google)->notification(pushRequest(pushOidcToken(['aud' => 'https://evil.test/hook']))))
        ->toThrow(VerificationException::class, 'unexpected audience');
});

it('checks a configured url token in constant time', function (?string $given, bool $accepted): void {
    configurePush(['token' => 's3cret-push-token']);

    $attempt = fn () => (new Google)->notification(pushRequest(null, $given));

    $accepted
        ? expect($attempt())->toBeInstanceOf(DeveloperNotification::class)
        : expect($attempt)->toThrow(VerificationException::class, 'token is missing or does not match');
})->with([
    'matching' => ['s3cret-push-token', true],
    'wrong' => ['guess', false],
    'missing' => [null, false],
]);

it('requires both the url token and the oidc token when both are configured', function (): void {
    configurePush(['audience' => PUSH_AUDIENCE, 'service_account_email' => PUSH_SERVICE_ACCOUNT, 'token' => 's3cret']);

    expect((new Google)->notification(pushRequest(pushOidcToken(), 's3cret')))->toBeInstanceOf(DeveloperNotification::class)
        ->and(fn () => (new Google)->notification(pushRequest(pushOidcToken())))->toThrow(VerificationException::class)
        ->and(fn () => (new Google)->notification(pushRequest(null, 's3cret')))->toThrow(VerificationException::class);
});

it('caches google\'s signing keys between pushes', function (): void {
    (new Google)->notification(pushRequest(pushOidcToken()));
    (new Google)->notification(pushRequest(pushOidcToken()));

    Http::assertSentCount(1);
});

it('re-fetches the keys once for a rotated kid, but not more than once a minute', function (): void {
    configurePush(['audience' => PUSH_AUDIENCE, 'service_account_email' => PUSH_SERVICE_ACCOUNT, 'jwks_url' => 'https://keys.test/rotating']);
    Http::fake(['https://keys.test/rotating' => Http::sequence()
        ->push(['keys' => [pushJwk('k1')]])
        ->push(['keys' => [pushJwk('k1'), pushJwk('k2', 'rotated')]])]);

    (new Google)->notification(pushRequest(pushOidcToken()));

    // Within the refresh interval an unknown kid is refused without a fetch.
    expect(fn () => (new Google)->notification(pushRequest(pushOidcToken([], 'k2', 'rotated'))))
        ->toThrow(VerificationException::class, 'unknown key');

    Carbon::setTestNow(Carbon::now()->addSeconds(PushAuthenticator::MIN_REFRESH_SECONDS + 1));

    expect((new Google)->notification(pushRequest(pushOidcToken([], 'k2', 'rotated'))))->toBeInstanceOf(DeveloperNotification::class);

    Http::assertSentCount(2);
});

it('fails closed when google\'s keys cannot be fetched', function (): void {
    configurePush(['audience' => PUSH_AUDIENCE, 'service_account_email' => PUSH_SERVICE_ACCOUNT, 'jwks_url' => 'https://keys.test/down']);
    Http::fake(['https://keys.test/down' => Http::response('down', 503)]);

    (new Google)->notification(pushRequest(pushOidcToken()));
})->throws(VerificationException::class, 'Could not fetch');

it('answers 400 to an unauthenticated push on the webhook route', function (): void {
    config()->set('purchases.routes', ['enabled' => true, 'prefix' => 'purchases', 'middleware' => []]);
    config()->set('purchases.providers', [Google::class]);

    require __DIR__.'/../../../routes/purchases.php';

    $body = ['message' => ['data' => Base64Url::encode('{"version":"1.0","testNotification":{"version":"1.0"}}')]];

    $this->postJson('/purchases/webhooks/google', $body)->assertStatus(400);
    $this->postJson('/purchases/webhooks/google', $body, ['Authorization' => 'Bearer '.pushOidcToken()])->assertNoContent();
});

it('refuses a key set google could not have published', function (array $keys, string $message): void {
    configurePush(['audience' => PUSH_AUDIENCE, 'service_account_email' => PUSH_SERVICE_ACCOUNT, 'jwks_url' => 'https://keys.test/odd']);
    Http::fake(['https://keys.test/odd' => Http::response(['keys' => $keys])]);

    expect(fn () => (new Google)->notification(pushRequest(pushOidcToken())))->toThrow(VerificationException::class, $message);
})->with([
    'no keys' => [[], 'empty or malformed'],
    'a private member on the key' => [[pushJwk('k1') + ['d' => 'AQAB']], 'malformed signing key'],
    'an EC key under the kid' => [[Jwk::fromPublicKey(TestKeys::ec())->jsonSerialize() + ['kid' => 'k1']], 'non-RSA'],
]);
