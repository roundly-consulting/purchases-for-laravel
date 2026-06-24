<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/purchases-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=purchases-for-laravel">
    <img src="art/hero.png" alt="Purchases for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

# Purchases for Laravel

Handle payments and subscriptions from payment gateways and in-app purchases. The package
ships Eloquent models for purchases, purchase items, subscriptions, and subscription items, a
pluggable payment-provider abstraction, and a native, dependency-free verifier for Apple App
Store Server Notifications and receipts.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- The `openssl` PHP extension (used to verify Apple's signed payloads)

## Installation

```bash
composer require roundly-consulting/purchases-for-laravel
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="purchases-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="purchases-config"
```

## Configuration

The published `config/purchases.php` file looks like this:

```php
return [
    'models' => [
        'purchase' => \RoundlyConsulting\Purchases\Purchase::class,
        'purchase-item' => \RoundlyConsulting\Purchases\PurchaseItem::class,
        'subscription' => \RoundlyConsulting\Purchases\Subscription::class,
        'subscription-item' => \RoundlyConsulting\Purchases\SubscriptionItem::class,
    ],

    'providers' => [
        \RoundlyConsulting\Purchases\Providers\Apple\Apple::class,
    ],

    'settings' => [
        'apple' => [
            'sandbox' => env('PURCHASES_APPLE_SANDBOX', true),
            'url' => [
                'live' => env('PURCHASES_APPLE_LIVE_URL', 'https://buy.itunes.apple.com'),
                'sandbox' => env('PURCHASES_APPLE_SANDBOX_URL', 'https://sandbox.itunes.apple.com'),
            ],
            'password' => env('PURCHASES_APPLE_PASSWORD'),
        ],
    ],
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `models.purchase` | class-string | `Purchase::class` | Model used for purchases. Override to swap in your own subclass. |
| `models.purchase-item` | class-string | `PurchaseItem::class` | Model used for purchase line items. |
| `models.subscription` | class-string | `Subscription::class` | Model used for subscriptions. |
| `models.subscription-item` | class-string | `SubscriptionItem::class` | Model used for subscription line items. |
| `providers` | list of class-string | `[Apple::class]` | The payment providers the resolver exposes. |
| `settings.apple.sandbox` | bool | `true` | Whether to hit Apple's sandbox endpoint. |
| `settings.apple.url.live` | string | App Store URL | Live `verifyReceipt` base URL. |
| `settings.apple.url.sandbox` | string | Sandbox URL | Sandbox `verifyReceipt` base URL. |
| `settings.apple.password` | string\|null | `null` | Your app's shared secret for receipt validation. |

### Environment variables

| Variable | Backs |
|---|---|
| `PURCHASES_APPLE_SANDBOX` | `settings.apple.sandbox` |
| `PURCHASES_APPLE_LIVE_URL` | `settings.apple.url.live` |
| `PURCHASES_APPLE_SANDBOX_URL` | `settings.apple.url.sandbox` |
| `PURCHASES_APPLE_PASSWORD` | `settings.apple.password` |

## Usage

### Models and money

`Purchase`, `PurchaseItem`, `Subscription`, and `SubscriptionItem` are standard Eloquent
models with soft deletes and factories. The `price` attribute is exposed as a small,
dependency-free `Money` value object backed by an integer minor-unit `price` column and a
3-letter `price_currency` column.

```php
use RoundlyConsulting\Purchases\Enum\Status;
use RoundlyConsulting\Purchases\Purchase;
use RoundlyConsulting\Purchases\ValueObjects\Money;

$purchase = Purchase::create([
    'provider' => 'apple',
    'provider_id' => 'txn_12345',
    'status' => Status::Completed->value,
    'price' => 2599,            // $25.99 in cents
    'price_currency' => 'USD',
]);

$purchase->price;               // Money { amount: 2599, currency: "USD" }
$purchase->price->amount;       // 2599
$purchase->price->currency;     // "USD"

// Assign a Money to write both columns at once.
$purchase->price = new Money(4200, 'EUR');
$purchase->save();
```

Purchases and subscriptions can belong to any owner via a polymorphic relation and have
many line items:

```php
$purchase->owner;               // the morphed owner model, e.g. a User
$purchase->items;               // Collection<PurchaseItem>

$subscription->items;           // Collection<SubscriptionItem>
```

### Purchase status

`RoundlyConsulting\Purchases\Enum\Status` is a backed enum with the cases `New`, `Pending`,
`Processing`, `Completed`, `Failed`, and `Canceled`. It is cast automatically on the
`Purchase` model's `status` column.

### Providers and the resolver

Payment providers implement `RoundlyConsulting\Purchases\Providers\Provider` (extend
`BaseProvider` for sensible defaults). The `Resolver` resolves a configured provider by its
kebab-cased id:

```php
use RoundlyConsulting\Purchases\Providers\Resolver;

$resolver = app(Resolver::class);

$resolver->keys();              // Collection: ['apple', ...]
$provider = $resolver->resolve('apple');
```

`Apple` is fully implemented. `Google` and `Stripe` ship as functional stubs that keep the
provider abstraction intact — their gateway-specific logic is intentionally left for a future
release.

### Apple: server notifications

`Apple::notification()` natively verifies Apple's signed (JWS / ES256) server-notification
payload — validating the `x5c` certificate chain against Apple's certificate authorities and
the ES256 signature using only the `openssl` extension — then decodes it into a typed
`ServerNotificationDecodedPayload`:

```php
use Illuminate\Http\Request;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;

Route::post('/webhooks/apple', function (Request $request, Apple $apple) {
    $payload = $apple->notification($request);

    $payload->type;             // NotificationType enum
    $payload->subType;          // NotificationSubType enum
    $payload->appMetadata;      // bundle id, environment, ...
    $payload->renewalInfo;      // RenewalInfo|null
    $payload->transactionInfo;  // TransactionInfo|null

    return response()->noContent();
});
```

### Apple: receipt validation

`Apple::callback()` sends the request body to Apple's `verifyReceipt` endpoint (sandbox or
live, per config) and returns a typed `ReceiptResponse`, throwing a
`VerificationException` when the receipt status is invalid:

```php
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;

try {
    $receipt = app(Apple::class)->callback($request);

    $receipt->status->isValid();   // true
    $receipt->environment;         // Environment enum
} catch (VerificationException $e) {
    report($e);
}
```

### Exceptions

All package exceptions extend `RoundlyConsulting\Purchases\Exceptions\Exception` and expose a
`because()` factory, so you can catch them precisely:

- `VerificationException` — signature/receipt verification failed.
- `InvalidProviderNotificationException` — a provider received an unsupported notification.
- `InvalidMoneyException` — an invalid currency code was supplied to `Money`.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
