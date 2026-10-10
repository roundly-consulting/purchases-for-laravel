<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

it('lists configured providers and flags missing config', function (): void {
    config()->set('purchases.providers', [Apple::class, Stripe::class]);
    config()->set('purchases.settings.apple', ['bundle_id' => 'com.example.app', 'app_apple_id' => '1', 'sandbox' => false]);
    config()->set('purchases.settings.stripe', ['secret' => null, 'webhook_secret' => null]);

    $this->artisan('purchases:providers')
        ->expectsTable(
            ['Provider', 'Configured'],
            [
                ['apple', 'yes'],
                ['stripe', 'missing config'],
            ],
        )
        ->assertSuccessful();
});

it('flags apple as missing config until notifications can be bound to an app', function (array $apple, string $expected): void {
    config()->set('purchases.providers', [Apple::class]);
    config()->set('purchases.settings.apple', $apple);

    $this->artisan('purchases:providers')
        ->expectsTable(['Provider', 'Configured'], [['apple', $expected]])
        ->assertSuccessful();
})->with([
    'credentials but no bundle id' => [['password' => 'secret', 'api' => ['private_key' => 'key']], 'missing config'],
    'production without an apple id' => [['bundle_id' => 'com.example.app', 'sandbox' => false], 'missing config'],
    'the sandbox needs no apple id' => [['bundle_id' => 'com.example.app', 'sandbox' => true], 'yes'],
]);

it('warns when no providers are registered', function (): void {
    config()->set('purchases.providers', []);

    $this->artisan('purchases:providers')
        ->expectsOutputToContain('No providers are registered')
        ->assertSuccessful();
});

/*
 * Stripe takes no traffic without both keys: the webhook secret verifies every event, and the
 * secret key answers which invoice a PaymentIntent pays (API versions since 2025-03-31).
 */
it('flags stripe as missing config until both of its keys are set', function (array $stripe, string $expected): void {
    config()->set('purchases.providers', [Stripe::class]);
    config()->set('purchases.settings.stripe', $stripe);

    $this->artisan('purchases:providers')
        ->expectsTable(['Provider', 'Configured'], [['stripe', $expected]])
        ->assertSuccessful();
})->with([
    'only the webhook secret' => [['secret' => null, 'webhook_secret' => 'whsec_1'], 'missing config'],
    'only the secret key' => [['secret' => 'sk_1', 'webhook_secret' => ''], 'missing config'],
    'both keys' => [['secret' => 'sk_1', 'webhook_secret' => 'whsec_1'], 'yes'],
]);

/*
 * Google pushes are authenticated fail-closed: with authentication on (the default) and no
 * OIDC pair or URL token configured, every RTDN is refused — so Google takes no traffic yet.
 */
it('flags google as missing config until its pushes can be authenticated', function (array $push, string $expected): void {
    config()->set('purchases.providers', [Google::class]);
    config()->set('purchases.settings.google', [
        'package_name' => 'com.example.app',
        'service_account' => ['client_email' => 'svc@example.iam.gserviceaccount.com'],
        'push' => $push,
    ]);

    $this->artisan('purchases:providers')
        ->expectsTable(['Provider', 'Configured'], [['google', $expected]])
        ->assertSuccessful();
})->with([
    'no push authentication configured' => [['authenticate' => true], 'missing config'],
    'nothing set at all' => [[], 'missing config'],
    'an oidc audience without its service account' => [['audience' => 'https://example.com/push'], 'missing config'],
    'a switch that is not a boolean' => [['authenticate' => 'maybe', 'token' => 'secret'], 'missing config'],
    'a url token' => [['token' => 'secret'], 'yes'],
    'an oidc audience and service account' => [['audience' => 'https://example.com/push', 'service_account_email' => 'push@example.iam.gserviceaccount.com'], 'yes'],
    'authentication switched off' => [['authenticate' => false], 'yes'],
]);
