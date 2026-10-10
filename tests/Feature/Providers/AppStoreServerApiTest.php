<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Algorithm;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\InvalidSignatureException;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Crypto\Testing\TestKeys;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Apple\AppStoreServerApi;
use RoundlyConsulting\Purchases\Providers\Apple\Auth\AppStoreJwtFactory;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;

function ecKey(): string
{
    static $key = null;

    $key ??= TestKeys::ec()->privatePem();

    return $key;
}

function fakeJws(array $claims): JwsManager
{
    return new class($claims) extends JwsManager
    {
        /** @param array<string, mixed> $claims */
        public function __construct(private array $claims) {}

        public function verify(string|DecodedToken $payload): void {}

        public function parse(string $payload): DecodedToken
        {
            return new DecodedToken(['alg' => 'ES256'], $this->claims, $payload);
        }
    };
}

function configureAppleApi(bool $sandbox = true): void
{
    config()->set('purchases.settings.apple', [
        'sandbox' => $sandbox,
        'bundle_id' => 'com.example.app',
        'api' => [
            'key_id' => 'KEY123',
            'issuer_id' => 'issuer-1',
            'private_key' => ecKey(),
            'url' => [
                'live' => 'https://api.storekit.itunes.apple.com',
                'sandbox' => 'https://api.storekit-sandbox.itunes.apple.com',
            ],
        ],
    ]);
}

it('builds a verifiable es256 app store token', function (): void {
    $token = (new AppStoreJwtFactory)->create('KEY123', 'issuer-1', 'com.example.app', ecKey());

    // Verify exactly as Apple would: ES256, pinned, against the public half of
    // the App Store Server API key. The signature must be the raw r‖s form JOSE
    // mandates — a DER blob here would not verify.
    $key = EcKey::private(ecKey());
    $claims = (new Jws)->verify($token, new Es(EcKey::public($key->publicPem())), Algorithm::ES256);

    [$header, , $signature] = explode('.', $token);

    expect(json_decode(Base64Url::decode($header), true))
        ->toMatchArray(['alg' => 'ES256', 'kid' => 'KEY123', 'typ' => 'JWT'])
        ->and(strlen(Base64Url::decode($signature)))->toBe(64)
        ->and($claims->string('iss'))->toBe('issuer-1')
        ->and($claims->string('aud'))->toBe('appstoreconnect-v1')
        ->and($claims->string('bid'))->toBe('com.example.app')
        ->and($claims->int('exp') - $claims->int('iat'))->toBe(1200);
});

it('rejects an app store token verified against an unrelated key', function (): void {
    $token = (new AppStoreJwtFactory)->create('KEY123', 'issuer-1', 'com.example.app', ecKey());

    $other = TestKeys::ec();

    (new Jws)->verify($token, new Es(EcKey::public($other->publicPem())), Algorithm::ES256);
})->throws(InvalidSignatureException::class);

it('throws when signing with an invalid key', function (): void {
    (new AppStoreJwtFactory)->create('KEY', 'iss', 'bid', 'not-a-key');
})->throws(VerificationException::class, 'Invalid App Store Server API private key.');

it('throws when signing with an rsa key instead of an ec key', function (): void {
    [$rsaPrivate] = rsaKeyPair();

    (new AppStoreJwtFactory)->create('KEY', 'iss', 'bid', $rsaPrivate);
})->throws(VerificationException::class, 'Invalid App Store Server API private key.');

it('looks up a transaction and decodes the signed payload', function (): void {
    configureAppleApi();

    Http::fake([
        '*/inApps/v1/transactions/txn-1' => Http::response([
            'signedTransactionInfo' => 'signed.jws',
        ]),
    ]);

    $jws = fakeJws([
        'bundleId' => 'com.example.app',
        'environment' => 'Sandbox',
        'transactionId' => 'txn-1',
        'productId' => 'pro.monthly',
    ]);

    $info = (new AppStoreServerApi($jws))->transaction('txn-1');

    expect($info->transactionId)->toBe('txn-1')
        ->and($info->productId)->toBe('pro.monthly');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.storekit-sandbox.itunes.apple.com'));
});

it('refuses a looked-up transaction of another app or environment', function (array $transaction, string $message): void {
    configureAppleApi();
    Http::fake(['*/inApps/v1/transactions/*' => Http::response(['signedTransactionInfo' => 'signed.jws'])]);

    expect(fn () => (new AppStoreServerApi(fakeJws($transaction + ['transactionId' => 'txn-x'])))->transaction('txn-x'))
        ->toThrow(VerificationException::class, $message);
})->with([
    'another app' => [['bundleId' => 'com.other.app', 'environment' => 'Sandbox'], 'Apple transaction is for another app.'],
    'another environment' => [['bundleId' => 'com.example.app', 'environment' => 'Production'], 'Apple transaction is for another environment.'],
]);

it('escapes the transaction id into the request path', function (): void {
    configureAppleApi();
    Http::fake(['*' => Http::response(['signedTransactionInfo' => 'signed.jws'])]);

    (new AppStoreServerApi(fakeJws(['bundleId' => 'com.example.app', 'environment' => 'Sandbox'])))->transaction('../../v1/notifications/test');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/inApps/v1/transactions/..%2F..%2Fv1%2Fnotifications%2Ftest'));
});

/*
 * No escaping keeps `.` or `..` a segment of its own: the HTTP client resolves it, so
 * `transaction('..')` would ask `/inApps/v1/` instead of one transaction. An empty id lands
 * on `/inApps/v1/transactions/`.
 */
it('refuses a transaction id that cannot stay one path segment, before asking apple', function (string $id): void {
    configureAppleApi();
    Http::fake();

    expect(fn () => (new AppStoreServerApi(fakeJws(['bundleId' => 'com.example.app', 'environment' => 'Sandbox'])))->transaction($id))
        ->toThrow(VerificationException::class, 'Malformed Apple transaction id.');

    Http::assertNothingSent();
})->with(['empty' => [''], 'a dot' => ['.'], 'two dots' => ['..']]);

it('sends a valid transaction id unchanged', function (): void {
    configureAppleApi();
    Http::fake(['*' => Http::response(['signedTransactionInfo' => 'signed.jws'])]);

    (new AppStoreServerApi(fakeJws(['bundleId' => 'com.example.app', 'environment' => 'Sandbox'])))->transaction('2000000123456789');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.storekit-sandbox.itunes.apple.com/inApps/v1/transactions/2000000123456789');
});

it('leaves an apple 4xx from a direct transaction lookup as the http client\'s exception', function (int $status): void {
    configureAppleApi();
    Http::fake(['*' => Http::response(['errorCode' => 4040010, 'errorMessage' => 'Transaction id not found.'], $status)]);

    expect(fn () => (new AppStoreServerApi(fakeJws([])))->transaction('2000000000000000'))
        ->toThrow(fn (RequestException $e) => expect($e->response->status())->toBe($status));
})->with(['invalid' => [400], 'not found' => [404]]);

it('targets the live url outside sandbox', function (): void {
    configureAppleApi(sandbox: false);

    Http::fake([
        '*/inApps/v1/transactions/*' => Http::response(['signedTransactionInfo' => 'signed.jws']),
    ]);

    (new AppStoreServerApi(fakeJws(['bundleId' => 'com.example.app', 'environment' => 'Production', 'transactionId' => 'txn-2'])))->transaction('txn-2');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.storekit.itunes.apple.com')
        && ! str_contains($request->url(), 'sandbox'));
});

it('throws when the api response has no signed transaction info', function (): void {
    configureAppleApi();

    Http::fake(['*/inApps/v1/transactions/*' => Http::response([])]);

    (new AppStoreServerApi(fakeJws([])))->transaction('txn-1');
})->throws(VerificationException::class);

it('throws when the api credentials are missing', function (): void {
    config()->set('purchases.settings.apple', ['sandbox' => true, 'api' => []]);

    Http::fake(['*' => Http::response(['signedTransactionInfo' => 'x'])]);

    (new AppStoreServerApi(fakeJws([])))->transaction('txn-1');
})->throws(VerificationException::class);

it('falls back to default urls when not configured', function (): void {
    config()->set('purchases.settings.apple', [
        'sandbox' => true,
        'bundle_id' => 'com.example.app',
        'api' => [
            'key_id' => 'KEY123',
            'issuer_id' => 'issuer-1',
            'private_key' => ecKey(),
        ],
    ]);

    Http::fake(['*/inApps/v1/transactions/*' => Http::response(['signedTransactionInfo' => 'signed.jws'])]);

    (new AppStoreServerApi(fakeJws(['bundleId' => 'com.example.app', 'environment' => 'Sandbox', 'transactionId' => 'txn-3'])))->transaction('txn-3');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.storekit-sandbox.itunes.apple.com'));
});

it('requests a test notification token', function (): void {
    configureAppleApi();
    Http::fake(['*/inApps/v1/notifications/test' => Http::response(['testNotificationToken' => 'tok-123'])]);

    expect((new AppStoreServerApi)->requestTestNotification())->toBe('tok-123');
});

it('throws when the test notification token is missing', function (): void {
    configureAppleApi();
    Http::fake(['*/inApps/v1/notifications/test' => Http::response([])]);

    (new AppStoreServerApi)->requestTestNotification();
})->throws(VerificationException::class);

it('verifies apple connectivity via the test notification endpoint', function (): void {
    configureAppleApi();
    Http::fake(['*/inApps/v1/notifications/test' => Http::response(['testNotificationToken' => 'tok-ok'])]);

    expect((new Apple)->verifyConnectivity()->ok)->toBeTrue();
});

it('reports failed apple connectivity gracefully', function (): void {
    config()->set('purchases.settings.apple', ['sandbox' => true, 'api' => []]);

    expect((new Apple)->verifyConnectivity()->ok)->toBeFalse();
});
