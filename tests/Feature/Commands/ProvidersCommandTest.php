<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Apple\Apple;
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
