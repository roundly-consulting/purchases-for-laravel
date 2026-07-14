<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests;

/**
 * A host that has NOT published the package's migrations yet.
 *
 * The normal {@see TestCase} pre-loads the package's `database/migrations` so every
 * other test has a schema to work against — which is precisely what a real host does
 * NOT do: migrations are publish-only, and nothing auto-loads them.
 *
 * `purchases:install` is the command whose entire job is to close that gap (publish,
 * then migrate), so it has to be exercised against an empty schema. Running it under
 * the normal TestCase would migrate the package's sources *and* the timestamped copies
 * it just published — two differently-named migrations both running the same
 * `Schema::create` — which is the duplicate-table footgun the publish-only policy
 * exists to remove.
 */
abstract class HostTestCase extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        // Deliberately empty: the schema is whatever `purchases:install` publishes.
    }
}
