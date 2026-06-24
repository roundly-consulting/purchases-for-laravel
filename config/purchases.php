<?php

declare(strict_types=1);
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Purchase;
use RoundlyConsulting\Purchases\PurchaseItem;
use RoundlyConsulting\Purchases\Subscription;
use RoundlyConsulting\Purchases\SubscriptionItem;

return [
    'models' => [
        'purchase' => Purchase::class,
        'purchase-item' => PurchaseItem::class,
        'subscription' => Subscription::class,
        'subscription-item' => SubscriptionItem::class,
    ],

    'providers' => [
        Apple::class,
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
