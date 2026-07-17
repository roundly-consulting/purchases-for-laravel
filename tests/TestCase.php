<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Purchases\PurchasesServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider purchases hard-requires, in registration order. A host
     * auto-discovers these; the suite must list them or the test environment is a
     * fiction. Crypto is not optional here — it owns the JWS verification, the ES256
     * signature and the X.509 chain handling that Apple's notifications are validated
     * with.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            PurchasesServiceProvider::class,
        ];
    }

    /**
     * The six purchase/subscription tables, named by provider class (never by filename),
     * plus the host-owned `users` fixture purchases hang off.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            __DIR__.'/database/migrations',
            PurchasesServiceProvider::class,
        ];
    }
}
