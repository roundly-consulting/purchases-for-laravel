<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationSubType;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsManager;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ReceiptResponse;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\ServerNotificationDecodedPayload;
use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\TransactionInfo;

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
                compact: $payload,
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
        'subtype' => 'INITIAL_BUY',
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
            'subtype' => 'BILLING_RECOVERY',
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

it('maps a renewal notification into a unified subscription result', function (): void {
    $jws = fakeJwsMapping([
        'token' => [
            'notificationUUID' => 'n-3',
            'notificationType' => 'DID_RENEW',
            'subtype' => 'BILLING_RECOVERY',
            'data' => [
                'appAppleId' => '1',
                'bundleId' => 'com.example.app',
                'bundleVersion' => '1.0',
                'environment' => 'Production',
                'signedTransactionInfo' => 'transaction.jws',
            ],
        ],
        'transaction.jws' => [
            'environment' => 'Production',
            'transactionId' => 'txn-9',
            'originalTransactionId' => 'orig-9',
            'productId' => 'pro.monthly',
            'purchaseDate' => 1700000000000,
            'expiresDate' => 1800000000000,
        ],
    ]);

    $result = (new Apple($jws))->result(new Request(['signedPayload' => 'token']));

    expect($result->provider())->toBe('apple')
        ->and($result->type())->toBe(ResultType::Subscription)
        ->and($result->providerId())->toBe('orig-9')
        ->and($result->transactionId())->toBe('txn-9')
        ->and($result->productId())->toBe('pro.monthly')
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->endsAt())->not->toBeNull();
});

it('maps a notification without transaction info into a notification result', function (): void {
    $jws = fakeJwsReturning([
        'notificationUUID' => 'n-4',
        'notificationType' => 'TEST',
        'subtype' => 'INITIAL_BUY',
        'data' => [
            'appAppleId' => '1',
            'bundleId' => 'com.example.app',
            'bundleVersion' => '1.0',
            'environment' => 'Sandbox',
        ],
    ]);

    $result = (new Apple($jws))->result(new Request(['signedPayload' => 'token']));

    expect($result->type())->toBe(ResultType::Notification)
        ->and($result->providerId())->toBe('n-4')
        ->and($result->status())->toBe(Status::Processing);
});

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

it('maps a refund notification into a refund result', function (): void {
    $jws = fakeJwsMapping([
        'token' => [
            'notificationUUID' => 'n-refund',
            'notificationType' => 'REFUND',
            'subtype' => 'INITIAL_BUY',
            'data' => [
                'appAppleId' => '1',
                'bundleId' => 'com.example.app',
                'bundleVersion' => '1.0',
                'environment' => 'Production',
                'signedTransactionInfo' => 'transaction.jws',
            ],
        ],
        'transaction.jws' => [
            'environment' => 'Production',
            'transactionId' => 'txn-r',
            'originalTransactionId' => 'orig-r',
            'productId' => 'pro.monthly',
        ],
    ]);

    $result = (new Apple($jws))->result(new Request(['signedPayload' => 'token']));

    expect($result->type())->toBe(ResultType::Refund)
        ->and($result->status())->toBe(Status::Refunded)
        ->and($result->refundReason())->toBe('REFUND')
        ->and($result->isChargeback())->toBeFalse();
});

it('maps a grace-period renewal failure into a grace-period result', function (): void {
    $jws = fakeJwsMapping([
        'token' => [
            'notificationUUID' => 'n-grace',
            'notificationType' => 'DID_FAIL_TO_RENEW',
            'subtype' => 'GRACE_PERIOD',
            'data' => [
                'appAppleId' => '1',
                'bundleId' => 'com.example.app',
                'bundleVersion' => '1.0',
                'environment' => 'Production',
                'signedTransactionInfo' => 'transaction.jws',
            ],
        ],
        'transaction.jws' => [
            'environment' => 'Production',
            'transactionId' => 'txn-g',
            'originalTransactionId' => 'orig-g',
            'productId' => 'pro.monthly',
        ],
    ]);

    $result = (new Apple($jws))->result(new Request(['signedPayload' => 'token']));

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result->status())->toBe(Status::InGracePeriod);
});

it('maps a renewal failure without grace period into a failed result', function (): void {
    $jws = fakeJwsMapping([
        'token' => [
            'notificationUUID' => 'n-fail',
            'notificationType' => 'DID_FAIL_TO_RENEW',
            'subtype' => 'BILLING_RETRY',
            'data' => [
                'appAppleId' => '1',
                'bundleId' => 'com.example.app',
                'bundleVersion' => '1.0',
                'environment' => 'Production',
                'signedTransactionInfo' => 'transaction.jws',
            ],
        ],
        'transaction.jws' => [
            'environment' => 'Production',
            'transactionId' => 'txn-f',
            'originalTransactionId' => 'orig-f',
            'productId' => 'pro.monthly',
        ],
    ]);

    $result = (new Apple($jws))->result(new Request(['signedPayload' => 'token']));

    expect($result->status())->toBe(Status::Failed);
});

/**
 * A DID_RENEW notification whose signed transaction carries the given claims.
 *
 * @param  array<string, mixed>  $transaction
 */
function applePricedResult(array $transaction): ?Money
{
    $jws = fakeJwsMapping([
        'token' => [
            'notificationUUID' => 'n-price',
            'notificationType' => 'DID_RENEW',
            'subtype' => 'BILLING_RECOVERY',
            'data' => [
                'appAppleId' => '1',
                'bundleId' => 'com.example.app',
                'bundleVersion' => '1.0',
                'environment' => 'Production',
                'signedTransactionInfo' => 'transaction.jws',
            ],
        ],
        'transaction.jws' => [
            'environment' => 'Production',
            'transactionId' => 'txn-p',
            'originalTransactionId' => 'orig-p',
            'productId' => 'pro.monthly',
        ] + $transaction,
    ]);

    return (new Apple($jws))->result(new Request(['signedPayload' => 'token']))->price();
}

it('maps the transaction price from milli-units', function (int $milli, string $currency, string $minor, string $decimal): void {
    $price = applePricedResult(['price' => $milli, 'currency' => $currency]);

    expect($price?->minor())->toBe($minor)
        ->and($price?->toDecimal())->toBe($decimal)
        ->and($price?->currency()->code)->toBe($currency);
})->with([
    'USD 1.99' => [1990, 'USD', '199', '1.99'],
    'USD 0.999 rounds half away from zero' => [999, 'USD', '100', '1.00'],
    'USD 0.995 rounds half away from zero' => [995, 'USD', '100', '1.00'],
    'USD 0.994 rounds down' => [994, 'USD', '99', '0.99'],
    'JPY 300' => [300000, 'JPY', '300', '300'],
    'KRW 3300' => [3300000, 'KRW', '3300', '3300'],
    'BHD 1.995 (exact)' => [1995, 'BHD', '1995', '1.995'],
]);

it('keeps the transaction price and currency on the value object', function (): void {
    $info = TransactionInfo::fromRaw(['environment' => 'Production', 'price' => 1990, 'currency' => 'USD']);

    expect($info->price)->toBe(1990)
        ->and($info->currency)->toBe('USD')
        ->and($info->raw)->toMatchArray(['price' => 1990, 'currency' => 'USD']);
});

it('leaves the price null when the transaction lacks a usable price', function (array $transaction): void {
    expect(applePricedResult($transaction))->toBeNull();
})->with([
    'no price fields' => [[]],
    'price without currency' => [['price' => 1990]],
    'currency without price' => [['currency' => 'USD']],
    'string price' => [['price' => '1990', 'currency' => 'USD']],
    'empty currency' => [['price' => 1990, 'currency' => '']],
    'unknown currency' => [['price' => 1990, 'currency' => 'ZZZ']],
]);

it('parses a notification that carries no subtype', function (string $type): void {
    // Apple sends `subtype` only for some types (REFUND, REVOKE, TEST, a plain DID_RENEW never carry one).
    $jws = fakeJwsMapping([
        'token' => [
            'notificationUUID' => 'n-no-subtype',
            'notificationType' => $type,
            'data' => [
                'appAppleId' => '1',
                'bundleId' => 'com.example.app',
                'bundleVersion' => '1.0',
                'environment' => 'Production',
                'signedTransactionInfo' => 'transaction.jws',
            ],
        ],
        'transaction.jws' => [
            'environment' => 'Production',
            'transactionId' => 'txn-ns',
            'originalTransactionId' => 'orig-ns',
            'productId' => 'pro.monthly',
            'price' => 1990,
            'currency' => 'USD',
        ],
    ]);

    $apple = new Apple($jws);
    $request = new Request(['signedPayload' => 'token']);

    expect($apple->notification($request)->subType)->toBeNull()
        ->and($apple->result($request)->price()?->minor())->toBe('199');
})->with(['DID_RENEW', 'REFUND', 'REVOKE']);

it('reads the subtype from the key apple sends', function (): void {
    $claims = ['notificationUUID' => 'n-st', 'notificationType' => 'DID_FAIL_TO_RENEW', 'subtype' => 'GRACE_PERIOD', 'data' => [
        'bundleId' => 'com.example.app',
        'bundleVersion' => '1.0',
        'environment' => 'Sandbox',
    ]];

    $payload = (new Apple(fakeJwsReturning($claims)))->notification(new Request(['signedPayload' => 'token']));

    expect($payload->subType)->toBe(NotificationSubType::SubtypeGracePeriod);
});

it('reads the int64 app apple id and tolerates its absence in the sandbox', function (array $data, ?string $expected): void {
    $claims = ['notificationUUID' => 'n-app', 'notificationType' => 'DID_RENEW', 'data' => $data + [
        'bundleId' => 'com.example.app',
        'bundleVersion' => '1.0',
        'environment' => 'Production',
    ]];

    $payload = (new Apple(fakeJwsReturning($claims)))->notification(new Request(['signedPayload' => 'token']));

    expect($payload->appMetadata->appAppleId)->toBe($expected);
})->with([
    'production: an int64' => [['appAppleId' => 1234567890], '1234567890'],
    'sandbox: absent' => [[], null],
]);
