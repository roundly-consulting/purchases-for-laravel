<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Purchases\PurchasesServiceProvider;
use RoundlyConsulting\Purchases\Tests\HostTestCase;

/**
 * The install writes into a per-test mirror of the skeleton ({@see HostTestCase}), never the
 * testbench skeleton the parallel suite boots from — so no previous run's published copy
 * can mask the duplicate-table failure the publish-only policy exists to remove.
 */
it('installs into the sandbox, never the shared skeleton', function (): void {
    expect(base_path('.env'))->toContain('purchases-host-')
        ->and(database_path('migrations'))->toContain('purchases-host-')
        ->and(array_values(ServiceProvider::pathsToPublish(PurchasesServiceProvider::class, 'purchases-config')))
        ->toBe([config_path('purchases.php')])
        ->and(config_path('purchases.php'))->toContain('purchases-host-');
});

it('publishes config and migrations without migrating', function (): void {
    $this->artisan('purchases:install')
        ->expectsConfirmation('Run the migrations now?', 'no')
        ->expectsOutputToContain('Published the Purchases config and migrations.')
        ->assertSuccessful();

    // Publish-only: the files land, but nothing has run them.
    expect(glob(database_path('migrations').'/*_create_purchases_table.php'))->toHaveCount(1)
        ->and(Schema::hasTable('purchases'))->toBeFalse();
});

it('publishes and runs migrations when confirmed', function (): void {
    expect(Schema::hasTable('purchases'))->toBeFalse();

    $this->artisan('purchases:install')
        ->expectsConfirmation('Run the migrations now?', 'yes')
        ->assertSuccessful();

    // The whole host flow: publish, then migrate. All six run once each, in
    // dependency order, from an empty schema.
    expect(Schema::hasTable('purchases'))->toBeTrue()
        ->and(Schema::hasTable('purchase_items'))->toBeTrue()
        ->and(Schema::hasTable('subscriptions'))->toBeTrue()
        ->and(Schema::hasTable('subscription_items'))->toBeTrue()
        ->and(Schema::hasTable('purchase_refunds'))->toBeTrue()
        ->and(Schema::hasTable('purchase_notifications'))->toBeTrue();
});

it('appends selected provider env keys interactively', function (): void {
    $envPath = base_path('.env');
    file_put_contents($envPath, "APP_NAME=Test\n");

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
});
