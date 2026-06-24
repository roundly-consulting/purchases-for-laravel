<?php

declare(strict_types=1);

it('publishes config and migrations without migrating', function (): void {
    $this->artisan('purchases:install')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->expectsOutputToContain('Published the Purchases config and migrations.')
        ->assertSuccessful();
});

it('publishes and runs migrations when confirmed', function (): void {
    $this->artisan('purchases:install')
        ->expectsConfirmation('Run the migrations now?', 'yes')
        ->assertSuccessful();
});

it('appends selected provider env keys interactively', function (): void {
    $envPath = base_path('.env');
    @unlink($envPath);
    file_put_contents($envPath, "APP_NAME=Test\n");

    try {
        $this->artisan('purchases:install', ['--providers' => true])
            ->expectsChoice(
                'Which providers would you like to enable?',
                ['apple', 'stripe'],
                ['apple', 'google', 'stripe'],
            )
            ->expectsConfirmation('Run the migrations now?', 'no')
            ->assertSuccessful();

        $contents = (string) file_get_contents($envPath);

        expect($contents)->toContain('APP_NAME=Test')
            ->and($contents)->toContain('PURCHASES_APPLE_KEY_ID=')
            ->and($contents)->toContain('PURCHASES_STRIPE_SECRET=')
            ->and($contents)->not->toContain('PURCHASES_GOOGLE_PACKAGE_NAME=');
    } finally {
        @unlink($envPath);
    }
});
