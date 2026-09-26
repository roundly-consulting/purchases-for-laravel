<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Purchases\Actions\SyncProviderResultAction;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Events\PurchaseCompleted;
use RoundlyConsulting\Purchases\Events\SubscriptionExpired;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\Environment;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationSubType;
use RoundlyConsulting\Purchases\Providers\Apple\Enums\NotificationType;
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

    // Billing retry, not expiry: access stops but Apple keeps retrying.
    expect($result->status())->toBe(Status::OnHold);
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

it('parses a notification that carries no subtype', function (string $type, ?string $minor): void {
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
        ->and($apple->result($request)->price()?->minor())->toBe($minor);
})->with([
    'DID_RENEW' => ['DID_RENEW', '199'],
    'REFUND' => ['REFUND', '199'],
    // A Family Sharing revocation moved no money (see the refund-amount tests below).
    'REVOKE' => ['REVOKE', null],
]);

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

/**
 * The refund amount a REFUND / REVOKE notification records for a transaction.
 *
 * @param  array<string, mixed>  $transaction
 */
function appleRefundPrice(string $type, array $transaction): ?Money
{
    $jws = fakeJwsMapping([
        'token' => [
            'notificationUUID' => 'n-refund-price',
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
            'transactionId' => 'txn-rp',
            'originalTransactionId' => 'orig-rp',
            'productId' => 'pro.yearly',
            'price' => 9990,
            'currency' => 'USD',
        ] + $transaction,
    ]);

    $result = (new Apple($jws))->result(new Request(['signedPayload' => 'token']));

    expect($result->type())->toBe(ResultType::Refund);

    return $result->price();
}

it('records only the refunded share of a prorated apple refund', function (): void {
    // 67.932 % of USD 9.99 = 6.7864068 → 6.79, rounded once.
    $price = appleRefundPrice('REFUND', ['revocationType' => 'REFUND_PRORATED', 'revocationPercentage' => 67932]);

    expect($price?->minor())->toBe('679')
        ->and($price?->currency()->code)->toBe('USD');
});

it('records the full price of a full apple refund', function (array $transaction): void {
    expect(appleRefundPrice('REFUND', $transaction)?->minor())->toBe('999');
})->with([
    'REFUND_FULL' => [['revocationType' => 'REFUND_FULL', 'revocationPercentage' => 100000]],
    'legacy payload without revocation fields' => [[]],
]);

it('records no refunded amount for a family sharing revocation', function (): void {
    // REVOKE: the family member never paid — no money moved on this transaction.
    expect(appleRefundPrice('REVOKE', ['revocationType' => 'FAMILY_REVOKE', 'revocationPercentage' => 100000]))->toBeNull();
});

it('keeps the revocation type and percentage on the value object', function (): void {
    $info = TransactionInfo::fromRaw([
        'environment' => 'Production',
        'revocationType' => 'REFUND_PRORATED',
        'revocationPercentage' => 67932,
    ]);

    expect($info->revocationType)->toBe('REFUND_PRORATED')
        ->and($info->revocationPercentage)->toBe(67932)
        ->and($info->raw)->toMatchArray(['revocationType' => 'REFUND_PRORATED', 'revocationPercentage' => 67932]);
});

it('records no refunded amount when the revocation percentage is out of range', function (int $percentage): void {
    expect(appleRefundPrice('REFUND', ['revocationType' => 'REFUND_PRORATED', 'revocationPercentage' => $percentage]))->toBeNull();
})->with(['negative' => -1, 'above 100 %' => 100001]);

/**
 * Apple's decoded notification: `notificationType`, an optional `subtype`, and a `data`
 * block carrying a signed transaction — or, for the types Apple sends without one, the
 * given top-level fields instead (`summary`, `externalPurchaseToken`, `appData`).
 *
 * @param  array<string, mixed>|null  $transaction  null: no `data` block at all
 * @param  array<string, mixed>  $extra
 */
function appleNotificationFor(string $type, ?string $subtype = null, ?array $transaction = [], array $extra = []): Apple
{
    $claims = ['notificationUUID' => 'n-'.strtolower($type), 'notificationType' => $type, 'version' => '2.0'] + $extra;

    if ($subtype !== null) {
        $claims['subtype'] = $subtype;
    }

    if ($transaction !== null) {
        $claims['data'] = [
            'appAppleId' => 1234567890,
            'bundleId' => 'com.example.app',
            'bundleVersion' => '1.0',
            'environment' => 'Production',
            'signedTransactionInfo' => 'transaction.jws',
        ];
    }

    return new Apple(fakeJwsMapping([
        'token' => $claims,
        'transaction.jws' => ($transaction ?? []) + [
            'environment' => 'Production',
            'transactionId' => 'txn-'.strtolower($type),
            'originalTransactionId' => 'orig-'.strtolower($type),
            'productId' => 'coins.100',
            'type' => 'Consumable',
            'inAppOwnershipType' => 'PURCHASED',
            'price' => 4990,
            'currency' => 'USD',
        ],
    ]));
}

function appleSignedRequest(): Request
{
    return new Request(['signedPayload' => 'token']);
}

it('parses every documented notification type without crashing', function (string $type, NotificationType $expected): void {
    $payload = appleNotificationFor($type)->notification(appleSignedRequest());

    expect($payload->type)->toBe($expected);
})->with([
    ['ONE_TIME_CHARGE', NotificationType::TypeOneTimeCharge],
    ['REFUND_REVERSED', NotificationType::TypeRefundReversed],
    ['RENEWAL_EXTENSION', NotificationType::TypeRenewalExtension],
    ['EXTERNAL_PURCHASE_TOKEN', NotificationType::TypeExternalPurchaseToken],
    ['METADATA_UPDATE', NotificationType::TypeMetadataUpdate],
    ['MIGRATION', NotificationType::TypeMigration],
    ['PRICE_CHANGE', NotificationType::TypePriceChange],
    ['RESCIND_CONSENT', NotificationType::TypeRescindConsent],
    'a type this version does not know yet' => ['SOMETHING_NEW', NotificationType::Unknown],
]);

it('parses every documented subtype', function (string $subtype, NotificationSubType $expected): void {
    expect(appleNotificationFor('EXPIRED', $subtype)->notification(appleSignedRequest())->subType)->toBe($expected);
})->with([
    ['PRODUCT_NOT_FOR_SALE', NotificationSubType::SubtypeProductNotForSale],
    ['FAILURE', NotificationSubType::SubtypeFailure],
    ['SUMMARY', NotificationSubType::SubtypeSummary],
    ['CREATED', NotificationSubType::SubtypeCreated],
    ['ACTIVE_TOKEN_REMINDER', NotificationSubType::SubtypeActiveTokenReminder],
    ['UNREPORTED', NotificationSubType::SubtypeUnreported],
]);

it('parses the notifications apple sends without a data block', function (string $type, ?string $subtype, array $extra, string $key): void {
    $apple = appleNotificationFor($type, $subtype, null, $extra);

    $payload = $apple->notification(appleSignedRequest());
    $result = $apple->result(appleSignedRequest());

    expect($payload->appMetadata)->toBeNull()
        ->and($payload->transactionInfo)->toBeNull()
        ->and($payload->toArray())->toHaveKey($key)
        ->and($result->type())->toBe(ResultType::Notification)
        ->and($result->providerId())->toBe('n-'.strtolower($type));
})->with([
    'renewal extension summary' => ['RENEWAL_EXTENSION', 'SUMMARY', ['summary' => ['requestIdentifier' => 'r-1', 'succeededCount' => 10, 'failedCount' => 0]], 'summary'],
    'external purchase token' => ['EXTERNAL_PURCHASE_TOKEN', 'CREATED', ['externalPurchaseToken' => ['externalPurchaseId' => 'x-1', 'tokenCreationDate' => 1700000000000]], 'externalPurchaseToken'],
    'rescinded consent' => ['RESCIND_CONSENT', null, ['appData' => ['appAppleId' => 1, 'bundleId' => 'com.example.app', 'environment' => 'Production']], 'appData'],
]);

it('maps a one-time charge to a completed purchase with its price', function (): void {
    Event::fake();

    $result = appleNotificationFor('ONE_TIME_CHARGE')->result(appleSignedRequest());

    expect($result->type())->toBe(ResultType::Purchase)
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->transactionId())->toBe('txn-one_time_charge')
        ->and($result->price()?->minor())->toBe('499');

    $purchase = (new SyncProviderResultAction)->execute($result);

    expect($purchase)->toBeInstanceOf(Purchase::class)
        ->and($purchase?->status)->toBe(Status::Completed)
        ->and($purchase?->price?->minor())->toBe('499');

    Event::assertDispatched(PurchaseCompleted::class);
});

it('records no price for a family-shared transaction', function (): void {
    $result = appleNotificationFor('ONE_TIME_CHARGE', null, ['inAppOwnershipType' => 'FAMILY_SHARED'])->result(appleSignedRequest());

    expect($result->type())->toBe(ResultType::Purchase)
        ->and($result->price())->toBeNull();
});

it('reinstates a purchase whose refund apple reversed', function (): void {
    $sync = new SyncProviderResultAction;

    $sync->execute(appleNotificationFor('ONE_TIME_CHARGE', null, ['originalTransactionId' => 'orig-1', 'transactionId' => 'txn-1'])->result(appleSignedRequest()));
    $sync->execute(appleNotificationFor('REFUND', null, ['originalTransactionId' => 'orig-1', 'transactionId' => 'txn-1'])->result(appleSignedRequest()));

    expect(Purchase::query()->sole()->status)->toBe(Status::Refunded);

    $reversed = appleNotificationFor('REFUND_REVERSED', null, ['originalTransactionId' => 'orig-1', 'transactionId' => 'txn-1'])->result(appleSignedRequest());
    $sync->execute($reversed);

    expect($reversed->type())->toBe(ResultType::Purchase)
        ->and($reversed->status())->toBe(Status::Completed)
        ->and(Purchase::query()->sole()->status)->toBe(Status::Completed);
});

it('reinstates a subscription whose refund apple reversed', function (): void {
    $result = appleNotificationFor('REFUND_REVERSED', null, ['type' => 'Auto-Renewable Subscription', 'productId' => 'pro.monthly'])->result(appleSignedRequest());

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result->status())->toBe(Status::Completed);
});

/** @return array<string, mixed> an auto-renewable subscription's signed transaction */
function appleSubscriptionTransaction(): array
{
    return [
        'originalTransactionId' => 'orig-sub',
        'transactionId' => 'txn-sub',
        'productId' => 'pro.monthly',
        'type' => 'Auto-Renewable Subscription',
        'expiresDate' => now()->addMonth()->getTimestampMs(),
        'price' => 9990,
        'currency' => 'USD',
    ];
}

/** @return array<string, array{0: string, 1: string|null}> */
function appleInformationalNotifications(): array
{
    return [
        'auto-renew turned off' => ['DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_DISABLED'],
        'auto-renew turned on' => ['DID_CHANGE_RENEWAL_STATUS', 'AUTO_RENEW_ENABLED'],
        'downgrade at next renewal' => ['DID_CHANGE_RENEWAL_PREF', 'DOWNGRADE'],
        'downgrade cancelled' => ['DID_CHANGE_RENEWAL_PREF', null],
        'price increase pending' => ['PRICE_INCREASE', 'PENDING'],
        'price increase accepted' => ['PRICE_INCREASE', 'ACCEPTED'],
        'consumption request' => ['CONSUMPTION_REQUEST', null],
        'refund declined' => ['REFUND_DECLINED', null],
        'renewal extension failed' => ['RENEWAL_EXTENSION', 'FAILURE'],
        'metadata update' => ['METADATA_UPDATE', null],
        'migration' => ['MIGRATION', null],
        'price change' => ['PRICE_CHANGE', null],
        'test' => ['TEST', null],
        'unknown type' => ['SOMETHING_NEW', null],
    ];
}

it('maps an informational notification to no state change', function (string $type, ?string $subtype): void {
    $result = appleNotificationFor($type, $subtype, appleSubscriptionTransaction())->result(appleSignedRequest());

    expect($result->type())->toBe(ResultType::Notification)
        ->and($result->status())->not->toBe(Status::Failed);
})->with(appleInformationalNotifications());

it('keeps an active subscription untouched by an informational notification', function (string $type, ?string $subtype): void {
    $sync = new SyncProviderResultAction;
    $sync->execute(appleNotificationFor('SUBSCRIBED', 'INITIAL_BUY', appleSubscriptionTransaction())->result(appleSignedRequest()));

    Event::fake();

    $model = $sync->execute(appleNotificationFor($type, $subtype, appleSubscriptionTransaction())->result(appleSignedRequest()));

    Event::assertNothingDispatched();

    $subscription = Subscription::query()->sole();

    expect($model)->toBeNull()
        ->and($subscription->status)->toBe(Status::Completed)
        ->and($subscription->status->isActive())->toBeTrue();
})->with(appleInformationalNotifications());

it('records an immediate upgrade as the active plan', function (): void {
    $result = appleNotificationFor('DID_CHANGE_RENEWAL_PREF', 'UPGRADE', ['productId' => 'pro.yearly'] + appleSubscriptionTransaction())->result(appleSignedRequest());

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->productId())->toBe('pro.yearly');
});

it('holds a subscription in billing retry instead of expiring it', function (string $type, ?string $subtype): void {
    $sync = new SyncProviderResultAction;
    $sync->execute(appleNotificationFor('SUBSCRIBED', 'INITIAL_BUY', appleSubscriptionTransaction())->result(appleSignedRequest()));

    Event::fake();

    $result = appleNotificationFor($type, $subtype, appleSubscriptionTransaction())->result(appleSignedRequest());
    $sync->execute($result);

    // Apple keeps retrying billing for 60 days: access stops, but the subscription has
    // not expired and a DID_RENEW / BILLING_RECOVERY brings it back.
    expect($result->status())->toBe(Status::OnHold)
        ->and(Subscription::query()->sole()->status)->toBe(Status::OnHold)
        ->and(Subscription::query()->sole()->status->isActive())->toBeFalse();

    Event::assertNotDispatched(SubscriptionExpired::class);
})->with([
    'renewal failed, no grace period' => ['DID_FAIL_TO_RENEW', null],
    'grace period ran out' => ['GRACE_PERIOD_EXPIRED', null],
]);

it('keeps the grace period and a real expiry as they were', function (string $type, ?string $subtype, Status $expected): void {
    expect(appleNotificationFor($type, $subtype, appleSubscriptionTransaction())->result(appleSignedRequest())->status())->toBe($expected);
})->with([
    'grace period' => ['DID_FAIL_TO_RENEW', 'GRACE_PERIOD', Status::InGracePeriod],
    'expired after billing retry' => ['EXPIRED', 'BILLING_RETRY', Status::Failed],
]);

it('does not switch the plan for an offer that downgrades at the next renewal', function (): void {
    $result = appleNotificationFor('OFFER_REDEEMED', 'DOWNGRADE', ['productId' => 'basic.monthly'] + appleSubscriptionTransaction())->result(appleSignedRequest());

    expect($result->type())->toBe(ResultType::Notification);
});

it('applies an offer that upgrades immediately', function (?string $subtype): void {
    $result = appleNotificationFor('OFFER_REDEEMED', $subtype, appleSubscriptionTransaction())->result(appleSignedRequest());

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result->status())->toBe(Status::Completed);
})->with(['upgrade' => 'UPGRADE', 'offer on the active subscription' => null]);
