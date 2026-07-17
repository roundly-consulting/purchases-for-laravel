<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Purchases\PurchasesServiceProvider;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * M + P + R for the six purchase/subscription tables.
 *
 * Every table expression here is a non-literal, in two different shapes — the model
 * accessor (`Schema::create(PurchaseModel::new()->getTable())`) and a local variable the
 * FK is constrained onto (`->constrained($purchases)`), because table names follow the
 * configured model. The resolver **never guesses on a non-literal**: an unmapped
 * expression FAILS the assertion rather than silently dropping the edge, which is what
 * keeps `foreignKeys: 2` honest instead of a number that passes over an empty parse.
 */
$migrations = __DIR__.'/../../database/migrations';

$tableResolvers = [
    // Schema::create(...) — the model accessor form.
    'PurchaseModel::new()->getTable()' => 'purchases',
    'PurchaseItemModel::new()->getTable()' => 'purchase_items',
    'PurchaseRefundModel::new()->getTable()' => 'purchase_refunds',
    'PurchaseNotificationModel::new()->getTable()' => 'purchase_notifications',
    'SubscriptionModel::new()->getTable()' => 'subscriptions',
    'SubscriptionItemModel::new()->getTable()' => 'subscription_items',
    // ->constrained(...) — the local-variable form, assigned from the same accessors.
    '$purchases' => 'purchases',
    '$subscriptions' => 'subscriptions',
];

/**
 * M — the structural, engine-independent order pin.
 *
 * Publish order IS run order (directory sort), so a migration that constrains onto a table
 * an earlier one has not created yet is uninstallable in a host. Five packages shipped
 * exactly that under green SQLite suites, because SQLite happily creates a table whose
 * foreign key names a missing parent and only complains at insert time.
 *
 * `foreignKeys: 2` pins the edge count: purchase_items -> purchases, and
 * subscription_items -> subscriptions. Nothing is constrained onto the host's users table
 * — a purchaser can live in any table — and refunds/notifications hang off a purchase by
 * an unconstrained key on purpose.
 */
it('has a runnable migration order', function () use ($migrations, $tableResolvers): void {
    expect($migrations)->toHaveRunnableMigrationOrder(
        foreignKeys: 2,
        tableResolvers: $tableResolvers,
    );
});

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies — a duplicate-table failure (bug #5, on
 * three packages), which is the exact footgun `tests/Host` exists to guard. `count: 6`
 * pins the file count so neither check can pass over an empty or relocated directory.
 */
it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(PurchasesServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(PurchasesServiceProvider::class)->toPublishMigrationsTimestamped('purchases-migrations', 6);
});

/**
 * R — the real-engine proof, both halves. This package's DDL had never met a real engine:
 * the suite ran on SQLite for its whole life, with foreign keys OFF (Laravel's SQLite
 * connector leaves `PRAGMA foreign_keys` off unless the connection sets
 * `foreign_key_constraints`, which the old hand-rolled TestCase did not).
 *
 * `migrations: 6` pins the count, and the expectation additionally fails a set that
 * "applies cleanly" while creating no tables — an empty `up()` otherwise passes and proves
 * nothing.
 */
it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 6);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The negative control — adoptable here because this package has real FK edges, so a
 * reversed order gives Postgres something to refuse. A green FK test proves nothing until
 * you have watched the engine actually reject the broken order (forms #28). This fails
 * loudly if the engine ACCEPTS the reordered set, which is what makes the positive half
 * above meaningful.
 */
it('rejects a child-before-parent order on postgres', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(
        fn (array $files): array => array_reverse($files),
        'pgsql',
    );
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * The driver-truth pin: compares the env-declared driver against what the connection
 * itself answers, so a leg that exports the location vars but not `TESTING_DB_DRIVER` (or
 * a TestCase that decapitates the base case by overriding `defineEnvironment()` without
 * `parent::`) reds instead of quietly running sqlite and reporting green as a "postgres"
 * job. Strictly stronger than reading a skip count by hand.
 */
it('runs on the driver the leg declared', function (): void {
    expect(DatabaseDriver::current())->toBe(DatabaseDriver::from(DriverMatrix::driver()));
});
