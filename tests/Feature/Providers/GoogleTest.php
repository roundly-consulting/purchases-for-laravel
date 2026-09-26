<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Testing\TestKeys;
use RoundlyConsulting\Purchases\Enum\ResultType;
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Google\Auth\AccessTokenFactory;
use RoundlyConsulting\Purchases\Providers\Google\Auth\ServiceAccountCredentials;
use RoundlyConsulting\Purchases\Providers\Google\Enums\NotificationType;
use RoundlyConsulting\Purchases\Providers\Google\Enums\PurchaseState;
use RoundlyConsulting\Purchases\Providers\Google\Enums\SubscriptionState;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Google\GoogleClient;
use RoundlyConsulting\Purchases\Testing\PayloadFactory;

function googleProvider(bool $acknowledge = true): Google
{
    Cache::flush();

    config()->set('purchases.settings.google', [
        'package_name' => 'com.example.app',
        'service_account' => [
            'client_email' => 'svc@example.iam.gserviceaccount.com',
            'private_key' => testRsaKey(),
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ],
        'base_url' => 'https://androidpublisher.googleapis.com',
        'acknowledge' => $acknowledge,
    ]);

    return new Google(new GoogleClient(
        credentials: new ServiceAccountCredentials('svc@example.iam.gserviceaccount.com', testRsaKey()),
        baseUrl: 'https://androidpublisher.googleapis.com',
        tokens: new class extends AccessTokenFactory
        {
            public function token(ServiceAccountCredentials $credentials): string
            {
                return 'fake-access-token';
            }
        },
    ));
}

function testRsaKey(): string
{
    static $key = null;

    $key ??= TestKeys::rsa()->privatePem();

    return $key;
}

it('verifies a purchased one-time product', function (): void {
    Http::fake([
        '*/purchases/products/*' => Http::response([
            'purchaseState' => 0,
            'consumptionState' => 0,
            'acknowledgementState' => 1,
            'orderId' => 'GPA.1234',
            'productId' => 'coins.100',
            'regionCode' => 'US',
            'purchaseTimeMillis' => '1700000000000',
        ]),
    ]);

    $purchase = googleProvider()->product('coins.100', 'token-1');

    expect($purchase->purchaseState)->toBe(PurchaseState::Purchased)
        ->and($purchase->orderId)->toBe('GPA.1234')
        ->and($purchase->purchaseTime)->not->toBeNull();
});

it('throws when a product purchase is canceled', function (): void {
    Http::fake([
        '*/purchases/products/*' => Http::response(['purchaseState' => 1, 'productId' => 'coins.100']),
    ]);

    googleProvider()->product('coins.100', 'token-1');
})->throws(VerificationException::class);

it('throws when a product purchase is pending', function (): void {
    Http::fake([
        '*/purchases/products/*' => Http::response(['purchaseState' => 2, 'productId' => 'coins.100']),
    ]);

    googleProvider()->product('coins.100', 'token-1');
})->throws(VerificationException::class);

it('acknowledges an unacknowledged product when enabled', function (): void {
    Http::fake([
        '*tokens/token-1:acknowledge' => Http::response([], 200),
        '*/purchases/products/*' => Http::response([
            'purchaseState' => 0,
            'acknowledgementState' => 0,
            'orderId' => 'GPA.1',
            'productId' => 'coins.100',
        ]),
    ]);

    googleProvider()->product('coins.100', 'token-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), ':acknowledge'));
});

it('does not acknowledge a product when disabled', function (): void {
    Http::fake([
        '*/purchases/products/*' => Http::response([
            'purchaseState' => 0,
            'acknowledgementState' => 0,
            'orderId' => 'GPA.1',
            'productId' => 'coins.100',
        ]),
    ]);

    googleProvider(acknowledge: false)->product('coins.100', 'token-1');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), ':acknowledge'));
});

it('verifies an active subscription with line items', function (): void {
    Http::fake([
        '*/purchases/subscriptionsv2/*' => Http::response([
            'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            'latestOrderId' => 'GPA.SUB.1',
            'startTime' => '2026-01-01T00:00:00Z',
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            'lineItems' => [
                [
                    'productId' => 'pro.monthly',
                    'expiryTime' => '2026-02-01T00:00:00Z',
                    'offerDetails' => ['offerId' => 'intro', 'basePlanId' => 'monthly'],
                ],
            ],
        ]),
    ]);

    $purchase = googleProvider()->subscription('sub-token');

    expect($purchase->subscriptionState)->toBe(SubscriptionState::Active)
        ->and($purchase->productId())->toBe('pro.monthly')
        ->and($purchase->expiryTime())->not->toBeNull()
        ->and($purchase->lineItems[0]->offerId)->toBe('intro');
});

it('throws for an expired subscription', function (): void {
    Http::fake([
        '*/purchases/subscriptionsv2/*' => Http::response([
            'subscriptionState' => 'SUBSCRIPTION_STATE_EXPIRED',
            'lineItems' => [],
        ]),
    ]);

    googleProvider()->subscription('sub-token');
})->throws(VerificationException::class);

it('maps a subscription to a unified result', function (): void {
    Http::fake([
        '*/purchases/subscriptionsv2/*' => Http::response([
            'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            'latestOrderId' => 'GPA.SUB.9',
            'startTime' => '2026-01-01T00:00:00Z',
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            'lineItems' => [
                [
                    'productId' => 'pro.monthly',
                    'expiryTime' => '2026-02-01T00:00:00Z',
                    'autoRenewingPlan' => [
                        'autoRenewEnabled' => true,
                        'recurringPrice' => ['currencyCode' => 'USD', 'units' => '12', 'nanos' => 990000000],
                    ],
                ],
            ],
        ]),
    ]);

    $result = googleProvider()->result(new Request(['purchaseToken' => 'sub-token']));

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->providerId())->toBe('GPA.SUB.9')
        ->and($result->endsAt())->not->toBeNull()
        ->and($result->price()?->minor())->toBe('1299')
        ->and($result->price()?->currency()->code)->toBe('USD');
});

it('maps a product to a unified purchase result', function (): void {
    Http::fake([
        '*/purchases/products/*' => Http::response([
            'purchaseState' => 0,
            'acknowledgementState' => 1,
            'orderId' => 'GPA.P.1',
            'productId' => 'coins.100',
            'purchaseTimeMillis' => '1700000000000',
        ]),
    ]);

    $result = googleProvider()->result(new Request(['purchaseToken' => 'tok', 'productId' => 'coins.100']));

    // The one-time products resource carries no price.
    expect($result->type())->toBe(ResultType::Purchase)
        ->and($result->status())->toBe(Status::Completed)
        ->and($result->providerId())->toBe('GPA.P.1')
        ->and($result->price())->toBeNull();
});

it('throws when verifying a callback without a token', function (): void {
    googleProvider()->callback(new Request);
})->throws(VerificationException::class);

it('decodes an RTDN subscription notification', function (): void {
    $payload = base64_encode((string) json_encode([
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'eventTimeMillis' => '1700000000000',
        'subscriptionNotification' => [
            'version' => '1.0',
            'notificationType' => 2,
            'purchaseToken' => 'tok',
            'subscriptionId' => 'pro.monthly',
        ],
    ]));

    $notification = googleProvider()->notification(new Request(['message' => ['data' => $payload]]));

    expect($notification->packageName)->toBe('com.example.app')
        ->and($notification->subscriptionNotification?->notificationType)->toBe(NotificationType::Renewed)
        ->and($notification->isTest)->toBeFalse();
});

it('decodes an RTDN one-time and voided and test notification', function (): void {
    $payload = base64_encode((string) json_encode([
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'oneTimeProductNotification' => ['notificationType' => 1, 'purchaseToken' => 't', 'sku' => 'coins'],
        'voidedPurchaseNotification' => ['purchaseToken' => 't', 'orderId' => 'o', 'productType' => 1, 'refundType' => 1],
        'testNotification' => ['version' => '1.0'],
    ]));

    $notification = googleProvider()->notification(new Request(['message' => ['data' => $payload]]));

    expect($notification->oneTimeProductNotification?->sku)->toBe('coins')
        ->and($notification->voidedPurchaseNotification?->orderId)->toBe('o')
        ->and($notification->isTest)->toBeTrue();
});

it('decodes pub/sub data carrying the standard base64 alphabet', function (): void {
    // Cloud Pub/Sub delivers padded standard base64 — `+` and `/` and all. This
    // is wire format, not a signature, and must keep decoding after the crypto
    // retrofit (a strict base64url-only decoder would reject it).
    $notification = [
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'subscriptionNotification' => [
            'version' => '1.0',
            'notificationType' => 2,
            'purchaseToken' => 'a+b/c??>>>~~~',
            'subscriptionId' => 'pro.monthly',
        ],
    ];

    $payload = base64_encode((string) json_encode($notification));

    expect($payload)->toContain('+')
        ->and($payload)->toContain('/')
        ->and($payload)->toEndWith('=');

    $decoded = googleProvider()->notification(new Request(['message' => ['data' => $payload]]));

    expect($decoded->subscriptionNotification?->purchaseToken)->toBe('a+b/c??>>>~~~');
});

it('decodes pub/sub data carrying the url-safe base64 alphabet', function (): void {
    $envelope = PayloadFactory::googleEnvelope(PayloadFactory::googleSubscriptionNotification(2, 'tok-url'));

    $decoded = googleProvider()->notification(new Request($envelope));

    expect($decoded->subscriptionNotification?->purchaseToken)->toBe('tok-url');
});

it('throws on a missing pub/sub message', function (): void {
    googleProvider()->notification(new Request);
})->throws(VerificationException::class);

it('throws on pub/sub data that is not base64 at all', function (): void {
    googleProvider()->notification(new Request(['message' => ['data' => 'not base64 %%%']]));
})->throws(VerificationException::class, 'Malformed Google developer notification payload.');

it('throws on a malformed pub/sub payload', function (): void {
    $payload = base64_encode('"not an object"');

    googleProvider()->notification(new Request(['message' => ['data' => $payload]]));
})->throws(VerificationException::class);

it('acknowledges a pending subscription with a mixed line-item set', function (): void {
    Http::fake([
        '*tokens/sub-token:acknowledge' => Http::response([], 200),
        '*/purchases/subscriptionsv2/*' => Http::response([
            'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            'latestOrderId' => 'GPA.SUB.2',
            'acknowledgementState' => 'ACKNOWLEDGEMENT_STATE_PENDING',
            'lineItems' => [
                ['productId' => 'pro.monthly'],
                ['productId' => 'pro.monthly', 'expiryTime' => '2026-02-01T00:00:00Z'],
            ],
        ]),
    ]);

    $purchase = googleProvider()->subscription('sub-token');

    expect($purchase->isAcknowledged())->toBeFalse()
        ->and($purchase->expiryTime())->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'subscriptionsv2/tokens/sub-token:acknowledge'));
});

it('acknowledges a subscription by explicit subscription id', function (): void {
    Http::fake(['*acknowledge' => Http::response([], 200)]);

    googleProvider()->acknowledgeSubscription('sub-token', 'pro.monthly');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'subscriptions/pro.monthly/tokens/sub-token:acknowledge'));
});

it('throws when the package name is not configured', function (): void {
    config()->set('purchases.settings.google', ['acknowledge' => false]);

    $provider = new Google(new GoogleClient(
        credentials: new ServiceAccountCredentials('svc@example.com', testRsaKey()),
        baseUrl: 'https://androidpublisher.googleapis.com',
        tokens: new class extends AccessTokenFactory
        {
            public function token(ServiceAccountCredentials $credentials): string
            {
                return 'fake';
            }
        },
    ));

    $provider->subscription('sub-token');
})->throws(VerificationException::class);

it('builds a real client from configuration', function (): void {
    config()->set('purchases.settings.google', [
        'package_name' => 'com.example.app',
        'service_account' => [
            'client_email' => 'svc@example.com',
            'private_key' => testRsaKey(),
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ],
        'base_url' => 'https://androidpublisher.googleapis.com',
        'acknowledge' => false,
    ]);

    Cache::flush();

    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        '*/purchases/subscriptionsv2/*' => Http::response([
            'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE',
            'latestOrderId' => 'GPA.SUB.3',
            'lineItems' => [['productId' => 'pro.monthly', 'expiryTime' => '2026-02-01T00:00:00Z']],
        ]),
    ]);

    $purchase = (new Google)->subscription('sub-token');

    expect($purchase->latestOrderId)->toBe('GPA.SUB.3');
});

it('maps a voided purchase RTDN into a refund result', function (): void {
    $payload = base64_encode((string) json_encode([
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'eventTimeMillis' => '1700000000000',
        'voidedPurchaseNotification' => [
            'purchaseToken' => 'tok-void',
            'orderId' => 'GPA.void',
            'productType' => 1,
            'refundType' => 1,
        ],
    ]));

    $result = googleProvider()->result(new Request(['message' => ['data' => $payload]]));

    expect($result->type())->toBe(ResultType::Refund)
        ->and($result->status())->toBe(Status::Refunded)
        ->and($result->providerId())->toBe('GPA.void')
        ->and($result->transactionId())->toBe('GPA.void');
});

it('maps a grace-period subscription RTDN into a grace-period result', function (): void {
    $payload = base64_encode((string) json_encode([
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'eventTimeMillis' => '1700000000000',
        'subscriptionNotification' => [
            'notificationType' => NotificationType::InGracePeriod->value,
            'purchaseToken' => 'tok-grace',
            'subscriptionId' => 'com.example.pro',
        ],
    ]));

    $result = googleProvider()->result(new Request(['message' => ['data' => $payload]]));

    expect($result->type())->toBe(ResultType::Subscription)
        ->and($result->status())->toBe(Status::InGracePeriod)
        ->and($result->providerId())->toBe('tok-grace');
});

it('maps an account-hold subscription RTDN into an on-hold result', function (): void {
    $payload = base64_encode((string) json_encode([
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'eventTimeMillis' => '1700000000000',
        'subscriptionNotification' => [
            'notificationType' => NotificationType::OnHold->value,
            'purchaseToken' => 'tok-hold',
            'subscriptionId' => 'com.example.pro',
        ],
    ]));

    $result = googleProvider()->result(new Request(['message' => ['data' => $payload]]));

    expect($result->status())->toBe(Status::OnHold);
});

it('maps a revoked subscription RTDN into a refund result', function (): void {
    $payload = base64_encode((string) json_encode([
        'version' => '1.0',
        'packageName' => 'com.example.app',
        'eventTimeMillis' => '1700000000000',
        'subscriptionNotification' => [
            'notificationType' => NotificationType::Revoked->value,
            'purchaseToken' => 'tok-revoked',
            'subscriptionId' => 'com.example.pro',
        ],
    ]));

    $result = googleProvider()->result(new Request(['message' => ['data' => $payload]]));

    expect($result->type())->toBe(ResultType::Refund)
        ->and($result->status())->toBe(Status::Refunded);
});

it('verifies google connectivity via token exchange', function (): void {
    $result = googleProvider()->verifyConnectivity();

    expect($result->ok)->toBeTrue();
});

it('reports failed google connectivity gracefully', function (): void {
    $provider = new Google(new GoogleClient(
        credentials: new ServiceAccountCredentials('svc@example.iam.gserviceaccount.com', testRsaKey()),
        baseUrl: 'https://androidpublisher.googleapis.com',
        tokens: new class extends AccessTokenFactory
        {
            public function token(ServiceAccountCredentials $credentials): string
            {
                throw VerificationException::because('bad credentials');
            }
        },
    ));

    expect($provider->verifyConnectivity()->ok)->toBeFalse();
});
