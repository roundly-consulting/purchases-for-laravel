<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Purchases\PurchasesServiceProvider;

/**
 * The package ships six CREATEs, and two of them carry a real foreign key:
 * `purchase_items` → `purchases` and `subscription_items` → `subscriptions`.
 * Publishing preserves the source directory's order, so that order has to be
 * runnable end to end from an empty database — every table must exist before
 * anything references it.
 *
 * SQLite happily creates a table referencing a missing parent (it only complains at
 * insert time), so these tests are the *committed* pin; the order was additionally
 * proved against a real PostgreSQL server, which rejects a dangling foreign key at
 * DDL time — with a negative control that watched it do so.
 *
 * These tests run the *published* files, under their published names, into a database
 * that starts empty — which is what a host actually does.
 */
beforeEach(function (): void {
    $this->publishedPath = sys_get_temp_dir().'/purchases-migration-order-'.bin2hex(random_bytes(6));
    $this->publishedDatabase = $this->publishedPath.'/database.sqlite';

    File::makeDirectory($this->publishedPath, recursive: true);
    File::put($this->publishedDatabase, '');

    foreach (ServiceProvider::pathsToPublish(PurchasesServiceProvider::class, 'purchases-migrations') as $source => $target) {
        File::copy($source, $this->publishedPath.'/'.basename((string) $target));
    }

    config()->set('database.connections.published', [
        'driver' => 'sqlite',
        'database' => $this->publishedDatabase,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->publishedPath);
});

it('migrates the published files clean from an empty database', function (): void {
    $schema = Schema::connection('published');

    expect($schema->hasTable('purchases'))->toBeFalse();

    $this->artisan('migrate', [
        '--database' => 'published',
        '--path' => $this->publishedPath,
        '--realpath' => true,
    ])->assertExitCode(0);

    expect($schema->hasTable('purchases'))->toBeTrue()
        ->and($schema->hasTable('purchase_items'))->toBeTrue()
        ->and($schema->hasTable('subscriptions'))->toBeTrue()
        ->and($schema->hasTable('subscription_items'))->toBeTrue()
        ->and($schema->hasTable('purchase_refunds'))->toBeTrue()
        ->and($schema->hasTable('purchase_notifications'))->toBeTrue();
});

it('keeps every foreign key intact in the published schema', function (): void {
    $this->artisan('migrate', [
        '--database' => 'published',
        '--path' => $this->publishedPath,
        '--realpath' => true,
    ])->assertExitCode(0);

    $schema = Schema::connection('published');

    $foreignKeys = static fn (string $table): array => array_map(
        static fn (array $key): string => $key['columns'][0].' → '.$key['foreign_table'],
        $schema->getForeignKeys($table),
    );

    // The CREATE order is load-bearing, not incidental: each child really does
    // constrain onto a table created before it, and on the packaged column name —
    // never one derived from the configured class.
    expect($foreignKeys('purchase_items'))->toContain('purchase_id → purchases')
        ->and($foreignKeys('subscription_items'))->toContain('subscription_id → subscriptions');

    // `purchase_refunds.purchase_id` is deliberately a plain indexed column with no
    // constraint (a refund can arrive before its purchase is matched).
    expect($foreignKeys('purchase_refunds'))->toBe([]);
});
