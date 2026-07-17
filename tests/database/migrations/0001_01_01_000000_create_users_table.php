<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Purchases\Tests\HostTestCase;

/**
 * The host-owned `users` table purchases hang off. This package ships no migration that
 * creates it — the host already has one — so this fixture stands in for the host's own
 * schema.
 *
 * It is a real migration rather than a `Schema::create()` in `defineDatabaseMigrations()`
 * for two reasons the pgsql leg makes load-bearing:
 *
 *  1. `PackageTestCase` resets a real engine by dropping every table and re-migrating, so a
 *     table built outside the migrator is dropped by that reset and never rebuilt — the
 *     second test in a Postgres run would find no `users` table. On sqlite `:memory:` the
 *     old shape happened to work because the database dies with the connection.
 *  2. It lets {@see HostTestCase} say "load nothing" by
 *     emptying `migrationSources()`, which is what a host with nothing published looks
 *     like — the users table included.
 *
 * It sorts first, so it exists before anything that references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
};
