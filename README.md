<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/purchases-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=purchases-for-laravel">
    <img src="art/hero.png" alt="Purchases for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

# Purchases for Laravel

A unified in-app-purchase and payments toolkit for Laravel: one API for **Apple App Store**,
**Google Play**, and **Stripe** purchases and subscriptions. The package ships Eloquent models
for purchases, purchase items, subscriptions, and subscription items, a pluggable provider
abstraction with a shared result contract, persistence actions, lifecycle events, and native,
dependency-free verification for every provider — built only on Laravel's HTTP client and
`ext-openssl` (no `stripe/stripe-php`, no `google/apiclient`, no third-party SDKs).

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- The `openssl` PHP extension (used to verify signed payloads and mint provider tokens)

## Installation

```bash
composer require roundly-consulting/purchases-for-laravel
```

Publish and run the migrations:

```bash
php artisan vendor:publish --tag="purchases-migrations"
php artisan migrate
```

Optionally publish the config file (and, if you use the bundled webhook routes, the routes
file):

```bash
php artisan vendor:publish --tag="purchases-config"
php artisan vendor:publish --tag="purchases-routes"
```

Or run the install command, which publishes the config and migrations and offers to migrate:

```bash
php artisan purchases:install
```

## Configuration

The published `config/purchases.php` registers the models, the providers, the optional webhook
routes, and per-provider settings (all backed by env vars):

```php
return [
    'models' => [
        'purchase' => \RoundlyConsulting\Purchases\Models\Purchase::class,
        'purchase-item' => \RoundlyConsulting\Purchases\Models\PurchaseItem::class,
        'subscription' => \RoundlyConsulting\Purchases\Models\Subscription::class,
        'subscription-item' => \RoundlyConsulting\Purchases\Models\SubscriptionItem::class,
    ],

    'providers' => [
        \RoundlyConsulting\Purchases\Providers\Apple\Apple::class,
        \RoundlyConsulting\Purchases\Providers\Google\Google::class,
        \RoundlyConsulting\Purchases\Providers\Stripe\Stripe::class,
    ],

    'routes' => [
        'enabled' => env('PURCHASES_ROUTES_ENABLED', false),
        'prefix' => env('PURCHASES_ROUTES_PREFIX', 'purchases'),
        'middleware' => ['api'],
    ],

    'settings' => [
        'apple' => [ /* sandbox, verifyReceipt urls, password, api { … } */ ],
        'google' => [ /* package_name, service_account { … }, base_url, acknowledge */ ],
        'stripe' => [ /* secret, webhook_secret, api_version, base_url, tolerance */ ],
    ],
];
```

### Environment variables

| Variable | Backs |
|---|---|
| `PURCHASES_APPLE_SANDBOX` | Apple sandbox toggle |
| `PURCHASES_APPLE_LIVE_URL` / `PURCHASES_APPLE_SANDBOX_URL` | `verifyReceipt` base URLs |
| `PURCHASES_APPLE_PASSWORD` | Apple shared secret (legacy receipt validation) |
| `PURCHASES_APPLE_KEY_ID` / `PURCHASES_APPLE_ISSUER_ID` / `PURCHASES_APPLE_BUNDLE_ID` / `PURCHASES_APPLE_PRIVATE_KEY` | App Store Server API credentials |
| `PURCHASES_GOOGLE_PACKAGE_NAME` | Android package name |
| `PURCHASES_GOOGLE_CLIENT_EMAIL` / `PURCHASES_GOOGLE_PRIVATE_KEY` | Google service-account credentials |
| `PURCHASES_GOOGLE_TOKEN_URI` | Google OAuth2 token endpoint |
| `PURCHASES_GOOGLE_ACKNOWLEDGE` | Auto-acknowledge purchases (default `true`) |
| `PURCHASES_STRIPE_SECRET` | Stripe secret/restricted key |
| `PURCHASES_STRIPE_WEBHOOK_SECRET` | Stripe webhook signing secret |
| `PURCHASES_STRIPE_API_VERSION` | Pinned Stripe API version |
| `PURCHASES_STRIPE_TOLERANCE` | Webhook timestamp tolerance (seconds) |
| `PURCHASES_ROUTES_ENABLED` / `PURCHASES_ROUTES_PREFIX` | Bundled webhook routes |

> Provider secrets are read only from config/env and are marked `#[SensitiveParameter]` so they
> never leak into stack traces. They are never logged.

## Usage

### The `Purchases` facade

The fastest path is the `Purchases` facade. `result()` verifies and decodes a request into a
provider-agnostic `ProviderResult`; `handle()` does the same and **persists** it (records the
model and dispatches events).

```php
use RoundlyConsulting\Purchases\Facades\Purchases;

$result = Purchases::result('stripe', $request);   // verify + decode, no writes
$result->type();        // ResultType::Subscription
$result->status();      // Status enum
$result->providerId();  // provider-side id

$model = Purchases::handle('stripe', $request);     // verify + decode + persist + events

Purchases::provider('google');   // Provider (throws UnknownProviderException if absent)
Purchases::has('apple');         // bool
Purchases::ids();                // ['apple', 'google', 'stripe']
```

### The unified result contract

Every provider maps its native payload onto `RoundlyConsulting\Purchases\Contracts\ProviderResult`,
so host code is provider-agnostic: `provider()`, `type()`, `providerId()`, `transactionId()`,
`status()`, `name()`, `productId()`, `price()`, `activeFrom()`, `trialEndsAt()`, `endsAt()`,
`items()`, and `raw()` (the original decoded payload).

### Events

`Purchases::handle()` (and the persistence actions) dispatch package events you can listen for:
`PurchaseRecorded`, `PurchaseCompleted`, `PurchaseFailed`, `SubscriptionStarted`,
`SubscriptionRenewed`, `SubscriptionCanceled`, and `SubscriptionExpired` — each carrying the
persisted model and the originating `ProviderResult`.

### Models and money

`Purchase`, `PurchaseItem`, `Subscription`, and `SubscriptionItem` (under
`RoundlyConsulting\Purchases\Models`) are standard Eloquent models with soft deletes and
factories. The `price` attribute is a dependency-free `Money` value object backed by an integer
minor-unit column and a 3-letter currency column.

```php
use RoundlyConsulting\Purchases\ValueObjects\Money;

$price = Money::of(2599, 'USD');        // $25.99
$price->plus(Money::of(100, 'USD'));    // Money(2699, USD)
$price->times(2);                       // Money(5198, USD)
$price->greaterThan(Money::zero('USD'));// true
$price->format('en_US');                // "$25.99" (uses ext-intl when present)
```

Mixing currencies throws `CurrencyMismatchException`.

### Apple

```php
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Apple\AppStoreServerApi;

// Verify a signed App Store server notification (ES256 JWS, native).
$payload = app(Apple::class)->notification($request);

// Modern App Store Server API (preferred over the deprecated verifyReceipt path).
$transaction = app(AppStoreServerApi::class)->transaction('2000000000000001');
$transaction->productId;
```

`Apple::callback()` still calls Apple's deprecated `verifyReceipt` endpoint for legacy receipts.

### Google Play

Verifies one-time products and **subscriptionsv2** purchases against the Play Developer API,
authenticating with a service account via a native OAuth2 JWT-bearer grant. Real-time Developer
Notifications (delivered through Pub/Sub) decode into a typed `DeveloperNotification`.

```php
use RoundlyConsulting\Purchases\Providers\Google\Google;

$google = app(Google::class);
$google->product('coins.100', $purchaseToken);   // ProductPurchase
$google->subscription($purchaseToken);           // SubscriptionPurchase (acknowledged by default)
$google->notification($request);                 // DeveloperNotification (RTDN)
```

Auto-acknowledgement is on by default; set `PURCHASES_GOOGLE_ACKNOWLEDGE=false` to opt out.

### Stripe

Verifies webhook signatures natively (HMAC-SHA256 over `t.payload`, constant-time comparison,
configurable timestamp tolerance) and reads REST objects with the pinned API version.

```php
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

$stripe = app(Stripe::class);
$event = $stripe->notification($request);        // verifies signature, returns StripeEvent
$stripe->paymentIntent('pi_123');                // PaymentIntent
$stripe->subscription('sub_123');                // Subscription
$stripe->session('cs_123');                      // CheckoutSession
$stripe->invoice('in_123');                      // Invoice
```

### Optional webhook routes

Disabled by default. Set `PURCHASES_ROUTES_ENABLED=true` to register
`POST /{prefix}/webhooks/{provider}`, which verifies, persists, fires events, and returns `204`
(invalid signature → `400`, unknown provider → `404`).

### Commands

- `php artisan purchases:install` — publish config + migrations (and optionally migrate).
- `php artisan purchases:providers` — list configured providers and flag missing config.

### Exceptions

All package exceptions extend `RoundlyConsulting\Purchases\Exceptions\Exception` with a
`because()` factory: `VerificationException`, `InvalidProviderNotificationException`,
`InvalidMoneyException`, `CurrencyMismatchException`, and `UnknownProviderException`.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
