<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Purchases\Models\Purchase;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\PurchasesManager;
use RoundlyConsulting\Purchases\PurchasesServiceProvider;

it('merges the packaged config', function (): void {
    expect(config('purchases.models.purchase'))->toBe(Purchase::class)
        ->and(config('purchases.audit.enabled'))->toBeTrue()
        ->and(config('purchases.routes.enabled'))->toBeFalse();
});

it('binds the manager as a singleton and the resolver as scoped', function (): void {
    expect(app(PurchasesManager::class))->toBeInstanceOf(PurchasesManager::class)
        ->and(app(PurchasesManager::class))->toBe(app(PurchasesManager::class))
        ->and(app(Resolver::class))->toBeInstanceOf(Resolver::class);
});

it('registers every console command', function (): void {
    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)->toContain('purchases:install')
        ->toContain('purchases:providers')
        ->toContain('purchases:verify')
        ->toContain('purchases:replay');
});

/**
 * Publish-only migrations (fleet policy). A bare `php artisan migrate` in a host must
 * NOT create the package's tables — the host publishes them first. This pins the
 * policy against a regression that re-adds `loadMigrationsFrom()`.
 */
it('never auto-loads its migrations', function (): void {
    $packageMigrations = realpath(__DIR__.'/../../database/migrations');

    $loaded = array_map(
        static fn (string $path): string => (string) realpath($path),
        app('migrator')->paths(),
    );

    expect($loaded)->not->toContain($packageMigrations);
});

it('publishes each migration into the host migrations directory under a timestamped name', function (): void {
    $paths = ServiceProvider::pathsToPublish(PurchasesServiceProvider::class, 'purchases-migrations');

    expect($paths)->toHaveCount(6);

    foreach ($paths as $source => $target) {
        expect($source)->toEndWith('.php')
            ->and(dirname((string) $target))->toBe(database_path('migrations'))
            ->and(basename((string) $target))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_\w+\.php$/');
    }
});

/**
 * Six migrations, and `purchase_items` / `subscription_items` each carry a real
 * foreign key onto the table created before them. Publishing preserves the source
 * directory's order, so the published timestamps have to sort into the dependency
 * order a host migrates in.
 */
it('publishes timestamps that preserve the dependency order', function (): void {
    $destinations = array_map(
        static fn (string $target): string => basename($target),
        array_values(ServiceProvider::pathsToPublish(PurchasesServiceProvider::class, 'purchases-migrations')),
    );

    $sorted = $destinations;
    sort($sorted);

    expect($sorted)->toBe($destinations);

    $position = static function (string $needle) use ($destinations): int {
        foreach ($destinations as $index => $name) {
            if (str_contains($name, $needle)) {
                return $index;
            }
        }

        return -1;
    };

    // Every foreign-key target is created before the table that references it.
    expect($position('create_purchases_table'))
        ->toBeLessThan($position('create_purchase_items_table'))
        ->and($position('create_subscriptions_table'))
        ->toBeLessThan($position('create_subscription_items_table'));
});

it('keeps every publish tag byte-identical', function (): void {
    $config = ServiceProvider::pathsToPublish(PurchasesServiceProvider::class, 'purchases-config');
    expect(array_values($config))->toBe([config_path('purchases.php')]);

    $routes = ServiceProvider::pathsToPublish(PurchasesServiceProvider::class, 'purchases-routes');
    expect(array_values($routes))->toBe([base_path('routes/purchases.php')]);
});

it('reports the package in about', function (): void {
    $this->artisan('about --only=purchases')
        ->expectsOutputToContain('Purchase model')
        ->assertExitCode(0);
});

/**
 * A — the secret-safe `about` capture, on the package the expectation was BUILT FROM.
 *
 * purchases #13: this is the fleet's most credential-heavy `about` section, and it was
 * guarded by negative assertions against `app(Kernel::class)->output()`, which returns
 * `''`. **Every "does not leak" check was vacuous** — passing against empty output. The
 * leak was caught only because one positive assertion happened to exist.
 *
 * The capture reader was fixed in an earlier retrofit (this test already used
 * `Artisan::output()` and already guarded the guard). Adopting the expectation makes the
 * ordering structural rather than remembered: it asserts (1) output non-empty, (2) every
 * `mustRender` string present, (3) only then that no secret renders — and `mustRender` is
 * required and non-empty, so a negative-only check can never be written here again.
 *
 * `about` runs on production boxes and its output is routinely pasted into issues, so not
 * one configured secret, key, endpoint, queue name or route prefix may appear.
 */
it('never renders a configured secret in about', function (): void {
    $secrets = [
        'apple-shared-secret-xyz',
        '-----BEGIN PRIVATE KEY-----APPLESTOREKEY-----END PRIVATE KEY-----',
        'ABCD123KEY',
        'issuer-4f2c-uuid',
        'com.acme.internal.app',
        '-----BEGIN PRIVATE KEY-----GOOGLEPLAYKEY-----END PRIVATE KEY-----',
        'billing@acme-internal.iam.gserviceaccount.com',
        'sk_live_51AcmeSuperSecret',
        'whsec_AcmeWebhookSigningSecret',
        'https://stripe-proxy.internal.acme.test/v1',
        'https://apple-proxy.internal.acme.test',
        'payments-tenant-7',
        'billing-webhooks',
        'internal-billing-hooks',
    ];

    config()->set('purchases.settings.apple.password', $secrets[0]);
    config()->set('purchases.settings.apple.api.private_key', $secrets[1]);
    config()->set('purchases.settings.apple.api.key_id', $secrets[2]);
    config()->set('purchases.settings.apple.api.issuer_id', $secrets[3]);
    config()->set('purchases.settings.apple.api.bundle_id', $secrets[4]);
    config()->set('purchases.settings.apple.url.live', $secrets[10]);
    config()->set('purchases.settings.google.service_account.private_key', $secrets[5]);
    config()->set('purchases.settings.google.service_account.client_email', $secrets[6]);
    config()->set('purchases.settings.google.package_name', $secrets[4]);
    config()->set('purchases.settings.stripe.secret', $secrets[7]);
    config()->set('purchases.settings.stripe.webhook_secret', $secrets[8]);
    config()->set('purchases.settings.stripe.base_url', $secrets[9]);
    config()->set('purchases.queue.enabled', true);
    config()->set('purchases.queue.connection', $secrets[11]);
    config()->set('purchases.queue.queue', $secrets[12]);
    config()->set('purchases.routes.enabled', true);
    config()->set('purchases.routes.prefix', $secrets[13]);

    expect('purchases')->toLeakNoSecrets(
        secrets: $secrets,
        // The positive half, and it is deliberately REAL rather than a token string: each
        // entry is the safe report standing in for one of the credentials above, so this
        // proves the very lines that could leak actually rendered. A `mustRender` of
        // ['Purchase model'] alone would pass while every credential line was silently
        // absent — which is #13's failure mode wearing a different hat.
        mustRender: [
            // Credentials report presence, never a value.
            'Apple credentials',
            'Google credentials',
            'Stripe API key',
            'Stripe webhook secret',
            'SET',
            // Host-supplied endpoints report a COUNT of overrides, never a URL. Two are
            // overridden above (apple.url.live and stripe.base_url), and pinning the exact
            // count is what proves the line is reporting rather than rendering 'DEFAULT'
            // over a config it never read.
            'Provider endpoints',
            '2 overridden',
            // Queue topology and routes report SET/DEFAULT and ON/OFF.
            'Queue processing',
            'Webhook routes',
            // And the models, which are the section's non-secret content.
            'Purchase model',
            'Subscription model',
        ],
    );
});
