<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Purchases\Tests\TestCase;

/**
 * A host whose owner models are keyed by ULID: `purchases.key_type` is set before the
 * migrations run — what the README tells a host to do — so every owner column is a ULID
 * column. It adds a `companies` table with ULID keys.
 */
abstract class UlidOwnersTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), ['purchases.key_type' => 'ulid']);
    }

    /**
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [...parent::migrationSources(), __DIR__.'/ulid'];
    }
}
