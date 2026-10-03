<?php

declare(strict_types=1);
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Models\PurchaseRefund;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

/*
 | Every on/off switch below is read from the environment as a string, so each is
 | read strictly: 1/true/on/yes are on, 0/false/off/no are off, unset or blank
 | (KEY=) keeps the documented default (for push authentication that is ON —
 | fail-closed), and anything else throws an InvalidConfigurationException naming
 | the key. Every other setting is strict too: blank means not set, so the default
 | applies, while a duration such as the Stripe tolerance must be a whole number
 | and URLs and queue names must be strings.
 */
return [
    'models' => [
        'purchase' => Purchase::class,
        'purchase-item' => PurchaseItem::class,
        'purchase-refund' => PurchaseRefund::class,
        'purchase-notification' => PurchaseNotification::class,
        'subscription' => Subscription::class,
        'subscription-item' => SubscriptionItem::class,
    ],

    'providers' => [
        Apple::class,
        Google::class,
        Stripe::class,
    ],

    /*
     | The key type used for the polymorphic owner column on purchases and
     | subscriptions. Use "uuid" or "ulid" when the models that own a purchase use
     | UUID/ULID primary keys, otherwise leave it as "bigint". Anything else throws
     | an InvalidConfigurationException naming the key. Your owner models must
     | share one key type.
     |
     | Supported: "bigint", "uuid", "ulid"
     */
    'key_type' => env('PURCHASES_KEY_TYPE', 'bigint'),

    /*
     | Persist every verified raw notification payload before it is reduced to
     | model state, giving an auditable log you can inspect or replay.
     */
    'audit' => [
        'enabled' => env('PURCHASES_AUDIT_ENABLED', true),
    ],

    /*
     | Process verified notifications on a queue. When enabled, handle() verifies
     | synchronously and the webhook returns 204 immediately, while persistence
     | happens on the configured queue connection.
     */
    'queue' => [
        'enabled' => env('PURCHASES_QUEUE_ENABLED', false),
        'connection' => env('PURCHASES_QUEUE_CONNECTION'),
        'queue' => env('PURCHASES_QUEUE_NAME'),
    ],

    /*
     | Opt-in HTTP webhook routes. Disabled by default; when enabled the package
     | registers POST {prefix}/webhooks/{provider} routes that verify and persist.
     */
    'routes' => [
        'enabled' => env('PURCHASES_ROUTES_ENABLED', false),
        'prefix' => env('PURCHASES_ROUTES_PREFIX', 'purchases'),
        'middleware' => ['api'],
    ],

    'settings' => [
        'apple' => [
            /*
             | The App Store app this host serves. Apple signs every app's notifications
             | with the same certificate chain, so a notification — and a transaction the
             | App Store Server API returns — is accepted only when it names this bundle
             | id and the environment below; in production its appAppleId must also equal
             | app_apple_id. The bundle id also signs App Store Server API requests.
             */
            'bundle_id' => env('PURCHASES_APPLE_BUNDLE_ID'),
            'app_apple_id' => env('PURCHASES_APPLE_APP_APPLE_ID'),

            /*
             | Production unless switched on: the App Store environment notifications must
             | come from, and which verifyReceipt / App Store Server API host is called.
             */
            'sandbox' => env('PURCHASES_APPLE_SANDBOX', false),
            'url' => [
                'live' => env('PURCHASES_APPLE_LIVE_URL', 'https://buy.itunes.apple.com'),
                'sandbox' => env('PURCHASES_APPLE_SANDBOX_URL', 'https://sandbox.itunes.apple.com'),
            ],
            'password' => env('PURCHASES_APPLE_PASSWORD'),

            /*
             | Clock-skew tolerance, in SECONDS, applied to both ends of every
             | certificate's validity window when an App Store notification's
             | signing chain is checked. It absorbs a slightly fast or slow host
             | clock — it is not a grace period for expired certificates.
             |
             | Must be between 0 and 3600; anything else is a misconfiguration and
             | is rejected loudly, so a fat-fingered value cannot silently disable
             | the expiry check. Blank (KEY=) is not set, so 60 applies.
             */
            'certificate_clock_skew' => env('PURCHASES_APPLE_CERTIFICATE_CLOCK_SKEW', 60),

            /*
             | App Store Server API (the modern replacement for verifyReceipt). Provide
             | the credentials from App Store Connect to enable transaction lookups.
             */
            'api' => [
                'key_id' => env('PURCHASES_APPLE_KEY_ID'),
                'issuer_id' => env('PURCHASES_APPLE_ISSUER_ID'),
                'private_key' => env('PURCHASES_APPLE_PRIVATE_KEY'),
                'url' => [
                    'live' => env('PURCHASES_APPLE_API_LIVE_URL', 'https://api.storekit.itunes.apple.com'),
                    'sandbox' => env('PURCHASES_APPLE_API_SANDBOX_URL', 'https://api.storekit-sandbox.itunes.apple.com'),
                ],
            ],
        ],

        'google' => [
            'package_name' => env('PURCHASES_GOOGLE_PACKAGE_NAME'),
            'service_account' => [
                'client_email' => env('PURCHASES_GOOGLE_CLIENT_EMAIL'),
                'private_key' => env('PURCHASES_GOOGLE_PRIVATE_KEY'),
                'token_uri' => env('PURCHASES_GOOGLE_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
            ],
            'base_url' => env('PURCHASES_GOOGLE_BASE_URL', 'https://androidpublisher.googleapis.com'),
            'acknowledge' => env('PURCHASES_GOOGLE_ACKNOWLEDGE', true),

            /*
             | Real-time Developer Notifications arrive as Cloud Pub/Sub pushes, which
             | anyone could forge. Every push is authenticated before it is read, and
             | this is FAIL-CLOSED: with nothing configured, every push is rejected.
             |
             | OIDC (recommended): on the push subscription, enable authentication
             | with a service account and an audience, then set both here. Pub/Sub
             | signs each push with a Google OIDC token; its signature (Google's JWKS,
             | cached), issuer, audience, service-account email and expiry are checked.
             |
             | URL token (optional, alone or on top of OIDC): append ?token=<secret>
             | to the push endpoint URL and set the same secret here.
             |
             | Set "authenticate" to false ONLY when something upstream has already
             | authenticated the message — e.g. your own pull subscriber hands it to
             | Purchases::handle().
             */
            'push' => [
                'authenticate' => env('PURCHASES_GOOGLE_PUSH_AUTHENTICATE', true),
                'audience' => env('PURCHASES_GOOGLE_PUSH_AUDIENCE'),
                'service_account_email' => env('PURCHASES_GOOGLE_PUSH_SERVICE_ACCOUNT'),
                'token' => env('PURCHASES_GOOGLE_PUSH_TOKEN'),
                'jwks_url' => env('PURCHASES_GOOGLE_PUSH_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
                'jwks_cache_ttl' => env('PURCHASES_GOOGLE_PUSH_JWKS_CACHE_TTL', 3600),
            ],
        ],

        'stripe' => [
            'secret' => env('PURCHASES_STRIPE_SECRET'),
            'webhook_secret' => env('PURCHASES_STRIPE_WEBHOOK_SECRET'),
            'api_version' => env('PURCHASES_STRIPE_API_VERSION', '2026-05-27.dahlia'),
            'base_url' => env('PURCHASES_STRIPE_BASE_URL', 'https://api.stripe.com/v1'),
            'tolerance' => env('PURCHASES_STRIPE_TOLERANCE', 300),
        ],
    ],
];
