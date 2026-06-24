<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

it('lists configured providers and flags missing config', function (): void {
    config()->set('purchases.providers', [Apple::class, Stripe::class]);
    config()->set('purchases.settings.apple', ['password' => 'secret']);
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

it('warns when no providers are registered', function (): void {
    config()->set('purchases.providers', []);

    $this->artisan('purchases:providers')
        ->expectsOutputToContain('No providers are registered')
        ->assertSuccessful();
});
