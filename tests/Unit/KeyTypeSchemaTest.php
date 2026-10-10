<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Testing\Database\DriverMatrix;

function dropPurchasesTable(): void
{
    // purchase_items carries a FK to purchases, so a strict engine refuses to drop the parent
    // while the child is there (MySQL 3730, Postgres 2BP01). Dropping the child first works on
    // every engine; the suite re-migrates everything between tests anyway.
    Schema::dropIfExists('purchase_items');
    Schema::dropIfExists('purchases');
}

function runPurchasesMigration(string $file): void
{
    $migration = require __DIR__.'/../../database/migrations/'.$file;
    $migration->up();
}

function purchasesCreateTable(string $table): string
{
    /** @var list<object{sql: string|null}> $rows */
    $rows = DB::select('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);

    return (string) ($rows[0]->sql ?? '');
}

/** @return array{type: string, nullable: string} */
function purchasesPgColumn(string $table, string $column): array
{
    /** @var list<object{data_type: string, character_maximum_length: int|null, is_nullable: string}> $rows */
    $rows = DB::select(
        'select data_type, character_maximum_length, is_nullable from information_schema.columns where table_name = ? and column_name = ?',
        [$table, $column],
    );

    $row = $rows[0] ?? null;

    if ($row === null) {
        return ['type' => 'MISSING', 'nullable' => 'MISSING'];
    }

    $type = $row->character_maximum_length === null
        ? $row->data_type
        : $row->data_type.'('.$row->character_maximum_length.')';

    return ['type' => $type, 'nullable' => $row->is_nullable];
}

$sqliteOnly = fn (): bool => DriverMatrix::driver() !== 'sqlite';
$pgsqlOnly = fn (): bool => DriverMatrix::driver() !== 'pgsql';

it('creates the polymorphic owner column on purchases and subscriptions', function (): void {
    expect(Schema::hasColumns('purchases', ['owner_type', 'owner_id']))->toBeTrue()
        ->and(Schema::hasColumns('subscriptions', ['owner_type', 'owner_id']))->toBeTrue();
});

/**
 * The core P1 safety property: `morphKey($n, BigInt, nullable: true)` IS `nullableMorphs($n)`.
 * The migration's emitted owner columns must match a table built from raw `nullableMorphs()`.
 */
it('emits a bigint owner morph byte-identical to raw nullableMorphs()', function (): void {
    Schema::dropIfExists('owner_raw_ref');
    Schema::create('owner_raw_ref', function (Blueprint $table): void {
        $table->id();
        $table->nullableMorphs('owner');
    });

    expect(purchasesCreateTable('purchases'))->toContain('"owner_type" varchar, "owner_id" integer')
        ->and(purchasesCreateTable('subscriptions'))->toContain('"owner_type" varchar, "owner_id" integer')
        ->and(purchasesCreateTable('owner_raw_ref'))->toContain('"owner_type" varchar, "owner_id" integer');

    Schema::dropIfExists('owner_raw_ref');
})->skip($sqliteOnly, 'sqlite_master is the sqlite catalog');

/**
 * The headline of P1: a uuid/ulid host gets uuid/ulid owner columns; bigint stays bigint.
 * Postgres tells the three apart. The owner morph keeps the `nullableMorphs()` nullability.
 */
it('renders each configured key type as a distinct real column type', function (string $keyType, string $expected): void {
    config()->set('purchases.key_type', $keyType);

    dropPurchasesTable();
    runPurchasesMigration('2024_01_01_000001_create_purchases_table.php');

    expect(purchasesPgColumn('purchases', 'owner_id'))->toBe(['type' => $expected, 'nullable' => 'YES'])
        ->and(purchasesPgColumn('purchases', 'owner_type')['type'])->toBe('character varying(255)');
})->with([
    'bigint' => ['bigint', 'bigint'],
    'uuid' => ['uuid', 'uuid'],
    'ulid' => ['ulid', 'character(26)'],
])->skip($pgsqlOnly, 'needs the postgres catalog to tell the key types apart');

it('refuses to migrate on an unrecognized key type instead of falling back to bigint', function (): void {
    config()->set('purchases.key_type', 'nonsense');

    dropPurchasesTable();

    // A typo in a host's config must stop the migration, never silently build bigint
    // columns for a uuid/ulid-keyed host.
    expect(function (): void {
        runPurchasesMigration('2024_01_01_000001_create_purchases_table.php');
    })->toThrow(InvalidConfigurationException::class, 'Configuration value [purchases.key_type] must be one of [bigint, uuid, ulid] (case-insensitive), [nonsense] given.');
});
