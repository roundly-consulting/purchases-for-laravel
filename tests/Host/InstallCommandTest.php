<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * The publish destination is a real directory inside the Testbench skeleton, so a
 * previous run's files leak into the next one — and because the publisher reuses an
 * existing file for the same migration name, a stale copy masks exactly the
 * duplicate-table failure this policy exists to remove.
 */
function clearPublishedMigrations(): void
{
    foreach (glob(database_path('migrations').'/*_create_{purchase,subscription}*.php', GLOB_BRACE) ?: [] as $file) {
        File::delete($file);
    }
}

beforeEach(fn () => clearPublishedMigrations());
afterEach(fn () => clearPublishedMigrations());

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
