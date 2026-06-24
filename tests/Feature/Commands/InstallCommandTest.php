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
