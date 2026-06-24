<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ReceiptResponse;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ServerNotificationDecodedPayload;

/**
 * A JwsManager test double that maps each input payload to canned claims.
 *
 * @param  array<string, array<string, mixed>>  $map  payload string => claims
 */
function fakeJwsMapping(array $map): JwsManager
{
    return new class($map) extends JwsManager
    {
        /** @param array<string, array<string, mixed>> $map */
        public function __construct(private array $map) {}

        public function verify(string|DecodedToken $payload): void {}

        public function parse(string $payload): DecodedToken
        {
            return new DecodedToken(
                header: ['alg' => 'ES256'],
                claims: $this->map[$payload] ?? [],
                signingInput: 'input',
                signature: 'sig',
            );
        }
    };
}

/**
 * Convenience double that returns the same claims for the primary token only.
 *
 * @param  array<string, mixed>  $claims
 */
function fakeJwsReturning(array $claims): JwsManager
{
    return fakeJwsMapping(['token' => $claims]);
}

it('decodes a verified server notification into a payload', function (): void {
    $claims = [
        'notificationUUID' => 'n-1',
        'notificationType' => 'SUBSCRIBED',
        'subType' => 'INITIAL_BUY',
        'data' => [
            'appAppleId' => '123',
            'bundleId' => 'com.example.app',
            'bundleVersion' => '1.0',
            'environment' => 'Sandbox',
        ],
    ];

    $apple = new Apple(fakeJwsReturning($claims));

    $payload = $apple->notification(new Request(['signedPayload' => 'token']));

    expect($payload)->toBeInstanceOf(ServerNotificationDecodedPayload::class)
        ->and($payload->uuid)->toBe('n-1')
        ->and($payload->appMetadata->bundleId)->toBe('com.example.app')
        ->and($payload->appMetadata->environment)->toBe(Environment::Sandbox);
});

it('decodes nested signed renewal and transaction info', function (): void {
    $jws = fakeJwsMapping([
        'token' => [
            'notificationUUID' => 'n-2',
            'notificationType' => 'DID_RENEW',
            'subType' => 'BILLING_RECOVERY',
            'data' => [
                'appAppleId' => '1',
                'bundleId' => 'com.example.app',
                'bundleVersion' => '1.0',
                'environment' => 'Production',
                'signedRenewalInfo' => 'renewal.jws',
                'signedTransactionInfo' => 'transaction.jws',
            ],
        ],
        'renewal.jws' => [
            'environment' => 'Production',
            'originalTransactionId' => 'orig-1',
            'productId' => 'prod-1',
            'autoRenewStatus' => 1,
        ],
        'transaction.jws' => [
            'environment' => 'Production',
            'transactionId' => 'txn-1',
            'productId' => 'prod-1',
        ],
    ]);

    $payload = (new Apple($jws))->notification(new Request(['signedPayload' => 'token']));

    expect($payload->renewalInfo)->not->toBeNull()
        ->and($payload->renewalInfo->productId)->toBe('prod-1')
        ->and($payload->transactionInfo)->not->toBeNull()
        ->and($payload->transactionInfo->transactionId)->toBe('txn-1');
});

it('verifies a receipt callback and returns the response', function (): void {
    config()->set('purchases.settings.apple', [
        'sandbox' => true,
        'url' => [
            'live' => 'https://buy.itunes.apple.com',
            'sandbox' => 'https://sandbox.itunes.apple.com',
        ],
        'password' => 'secret',
    ]);

    Http::fake([
        '*/verifyReceipt' => Http::response([
            'status' => 0,
            'environment' => 'Sandbox',
            'latest_receipt' => 'base64-receipt',
        ]),
    ]);

    $response = (new Apple)->callback(Request::create('/callback', 'POST', content: 'receipt-data'));

    expect($response)->toBeInstanceOf(ReceiptResponse::class)
        ->and($response->status->isValid())->toBeTrue()
        ->and($response->environment)->toBe(Environment::Sandbox);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'sandbox.itunes.apple.com')
        && $request['password'] === 'secret');
});

it('throws when the receipt status is invalid', function (): void {
    config()->set('purchases.settings.apple', [
        'sandbox' => false,
        'url' => [
            'live' => 'https://buy.itunes.apple.com',
            'sandbox' => 'https://sandbox.itunes.apple.com',
        ],
        'password' => 'secret',
    ]);

    Http::fake([
        '*/verifyReceipt' => Http::response([
            'status' => 21003,
            'environment' => 'Production',
        ]),
    ]);

    (new Apple)->callback(Request::create('/callback', 'POST', content: 'receipt-data'));
})->throws(VerificationException::class);

it('targets the live url when not in sandbox mode', function (): void {
    config()->set('purchases.settings.apple', [
        'sandbox' => false,
        'url' => [
            'live' => 'https://buy.itunes.apple.com',
            'sandbox' => 'https://sandbox.itunes.apple.com',
        ],
        'password' => 'secret',
    ]);

    Http::fake([
        '*/verifyReceipt' => Http::response(['status' => 0, 'environment' => 'Production']),
    ]);

    (new Apple)->callback(Request::create('/callback', 'POST', content: 'receipt-data'));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'buy.itunes.apple.com'));
});
