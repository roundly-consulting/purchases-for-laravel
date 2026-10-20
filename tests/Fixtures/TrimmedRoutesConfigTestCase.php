<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Fixtures;

use RoundlyConsulting\Purchases\Tests\TestCase;

/**
 * A host whose published `config/purchases.php` sets only the part of `routes` it changes. The
 * config merge is shallow, so `routes.enabled` is gone from the merged config.
 *
 * Pest binds a test case per DIRECTORY, not per file, so this lives in tests/RoutesSwitch.
 */
abstract class TrimmedRoutesConfigTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [...parent::configBeforeBoot(), 'purchases.routes' => ['prefix' => 'billing-hooks']];
    }
}
