<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\AppStoreServerApi;
use RoundlyConsulting\Purchases\Providers\Apple\Auth\AppStoreJwtFactory;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Support\Base64Url;
use RoundlyConsulting\Purchases\Support\EcdsaSignature;

function ecKey(): string
{
    static $key = null;

    if ($key === null) {
        $resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'private_key_bits' => 384,
        ]);
        openssl_pkey_export($resource, $key);
    }

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
            return new DecodedToken(['alg' => 'ES256'], $this->claims, 'in', 'sig');
        }
    };
}

function configureAppleApi(bool $sandbox = true): void
{
    config()->set('purchases.settings.apple', [
        'sandbox' => $sandbox,
        'api' => [
            'key_id' => 'KEY123',
            'issuer_id' => 'issuer-1',
            'bundle_id' => 'com.example.app',
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

    [$header, $claims, $signature] = explode('.', $token);

    $der = EcdsaSignature::toDer(Base64Url::decode($signature));
    $details = openssl_pkey_get_details(openssl_pkey_get_private(ecKey()));

    $valid = openssl_verify("{$header}.{$claims}", $der, $details['key'], OPENSSL_ALGO_SHA256);
    $decodedClaims = json_decode(Base64Url::decode($claims), true);

    expect($valid)->toBe(1)
        ->and($decodedClaims['iss'])->toBe('issuer-1')
        ->and($decodedClaims['aud'])->toBe('appstoreconnect-v1')
        ->and($decodedClaims['bid'])->toBe('com.example.app');
});

it('throws when signing with an invalid key', function (): void {
    (new AppStoreJwtFactory)->create('KEY', 'iss', 'bid', 'not-a-key');
})->throws(VerificationException::class);

it('looks up a transaction and decodes the signed payload', function (): void {
    configureAppleApi();

    Http::fake([
        '*/inApps/v1/transactions/txn-1' => Http::response([
            'signedTransactionInfo' => 'signed.jws',
        ]),
    ]);

    $jws = fakeJws([
        'environment' => 'Sandbox',
        'transactionId' => 'txn-1',
        'productId' => 'pro.monthly',
    ]);

    $info = (new AppStoreServerApi($jws))->transaction('txn-1');

    expect($info->transactionId)->toBe('txn-1')
        ->and($info->productId)->toBe('pro.monthly');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.storekit-sandbox.itunes.apple.com'));
});

it('targets the live url outside sandbox', function (): void {
    configureAppleApi(sandbox: false);

    Http::fake([
        '*/inApps/v1/transactions/*' => Http::response(['signedTransactionInfo' => 'signed.jws']),
    ]);

    (new AppStoreServerApi(fakeJws(['environment' => 'Production', 'transactionId' => 'txn-2'])))->transaction('txn-2');

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
        'api' => [
            'key_id' => 'KEY123',
            'issuer_id' => 'issuer-1',
            'bundle_id' => 'com.example.app',
            'private_key' => ecKey(),
        ],
    ]);

    Http::fake(['*/inApps/v1/transactions/*' => Http::response(['signedTransactionInfo' => 'signed.jws'])]);

    (new AppStoreServerApi(fakeJws(['environment' => 'Sandbox', 'transactionId' => 'txn-3'])))->transaction('txn-3');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.storekit-sandbox.itunes.apple.com'));
});
