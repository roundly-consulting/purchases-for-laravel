<?php

declare(strict_types=1);
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Models\PurchaseItem;
use RoundlyConsulting\Purchases\Models\Subscription;
use RoundlyConsulting\Purchases\Models\SubscriptionItem;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

return [
    'models' => [
        'purchase' => Purchase::class,
        'purchase-item' => PurchaseItem::class,
        'subscription' => Subscription::class,
        'subscription-item' => SubscriptionItem::class,
    ],

    'providers' => [
        Apple::class,
        Google::class,
        Stripe::class,
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
