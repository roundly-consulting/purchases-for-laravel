<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('purchases.settings.stripe', [
        'secret' => 'sk_test_123',
        'webhook_secret' => 'whsec',
        'api_version' => '2026-05-27.dahlia',
        'base_url' => 'https://api.stripe.com/v1',
        'tolerance' => 300,
    ]);
});

it('reports ok when a provider verifies', function (): void {
    Http::fake(['*/balance' => Http::response(['object' => 'balance'])]);

    $this->artisan('purchases:verify', ['provider' => 'stripe'])
        ->expectsOutputToContain('ok')
        ->assertSuccessful();
});

it('fails when a provider cannot authenticate', function (): void {
    Http::fake(['*/balance' => Http::response('no', 401)]);

    $this->artisan('purchases:verify', ['provider' => 'stripe'])
        ->assertFailed();
});

it('warns when no providers match the filter', function (): void {
    $this->artisan('purchases:verify', ['provider' => 'nonexistent'])
        ->expectsOutputToContain('No matching providers to verify.')
        ->assertSuccessful();
});
