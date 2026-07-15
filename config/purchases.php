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
            'sandbox' => env('PURCHASES_APPLE_SANDBOX', true),
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
             | the expiry check.
             */
            'certificate_clock_skew' => env('PURCHASES_APPLE_CERTIFICATE_CLOCK_SKEW', 60),

            /*
             | App Store Server API (the modern replacement for verifyReceipt). Provide
             | the credentials from App Store Connect to enable transaction lookups.
             */
            'api' => [
                'key_id' => env('PURCHASES_APPLE_KEY_ID'),
                'issuer_id' => env('PURCHASES_APPLE_ISSUER_ID'),
                'bundle_id' => env('PURCHASES_APPLE_BUNDLE_ID'),
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
