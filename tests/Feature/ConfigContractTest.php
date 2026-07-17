<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;

/**
 * C — the config contract this package never had, pinned in both directions.
 *
 *  - Forward — every key the code reads is shipped. A key the code reads but the file does
 *    not ship silently resolves to null. That is shops #18, whose whole store-credit
 *    feature read `shops.payments.*` while the file shipped `payment.*`; 330 tests stayed
 *    green because the suite set the same wrong key. For a payments package the equivalent
 *    is a credential or a tolerance that is never actually applied.
 *  - Reverse — every shipped leaf is read. A key the file ships and documents but no code
 *    reads is a dead feature: media #27's `max_file_size` cap that never applied (an upload
 *    endpoint with NO size limit), alerts #24's thrice-documented `escalation` key.
 *
 * Reads are scraped from source TOKENS, never a regex: media #27's near-miss was a regex
 * over raw text satisfied by a *docblock mention*, which stayed green with the fix
 * reverted. A docblock is a comment token here, never a read.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/purchases.php')->toSatisfyConfigContract(
        [__DIR__.'/../../src', __DIR__.'/../../database'],
        [
            // The six model seams are read through the PurchaseModel/SubscriptionModel/…
            // resolvers rather than a bare `config()` token, and the migrations reach the
            // table names through the same accessors. Those are real reads that drive the
            // whole schema, but they are not `config(` calls, so the prefix is what makes
            // them visible to the scraper.
            // Scoped to the real config sections rather than a bare 'purchases.'. The
            // broad prefix counts ANY literal under it as a config read — which sweeps up
            // `hasRoutes('purchases.php', …)`, a ROUTES FILENAME, and reports
            // `purchases.php` as a config key the file does not ship. Naming the sections
            // keeps every genuine seam read visible without inventing that key.
            //
            // No 'purchases.routes.' entry: every key in that section is already read by a
            // plain `config()` token in the provider, so the prefix proved nothing and was
            // removed. Unlike allowUnread/allowUnshipped, extraReadPrefixes is NOT
            // rot-checked — a redundant entry silences no failure and so can never be
            // noticed, while still carrying the false-positive risk described above. That
            // asymmetry is why a prefix earns its place or goes.
            'extraReadPrefixes' => [
                'purchases.models.',
                'purchases.settings.',
                'purchases.audit.',
                'purchases.queue.',
            ],

            // Credentials are read as ARRAY OFFSETS on a section the provider resolved
            // once, not as `config('purchases.settings.apple.api.key_id')` literals — so
            // without these they read as five shipped-but-dead keys. They are not dead:
            // they are the Apple API token, the Google service-account key and the Stripe
            // API version, every one of them load-bearing.
            'sectionVariables' => [
                // `$api = $this->config['api']`, where $this->config is
                // config('purchases.settings.apple') — so $api['key_id'] is a read of
                // purchases.settings.apple.api.key_id.
                'AppStoreServerApi.php' => ['$api' => 'purchases.settings.apple.api'],
                // `fromConfig(array $config)` is handed
                // config('purchases.settings.google.service_account').
                'ServiceAccountCredentials.php' => ['$config' => 'purchases.settings.google.service_account'],
            ],

            // `purchases.settings.stripe.api_version` IS read — `Stripe::client()` does
            // `$this->config['api_version']` and passes it to StripeClient, which sends it
            // as the Stripe-Version header. It cannot be *expressed* here: `sectionVariables`
            // matches a single T_VARIABLE token, and `$this->config` is three tokens
            // (`$this`, `->`, `config`), so a property-backed section is unmappable. That is
            // a scraper limitation, not dead config — REPORTED, not papered over.
            //
            // This entry is rot-proof (a stale allowUnread that silences nothing fails), and
            // the key is independently proven live by the test below, so nothing is lost by
            // parking it here rather than deleting a working feature.
            'allowUnread' => ['purchases.settings.stripe.api_version'],

            // Deliberately NO `excludeFromReverse` for the service provider. It renders
            // the `about` section (a render is not a read), but the toolkit's
            // PackageServiceProvider ALSO does its real `bindFromConfig()` reads in the
            // same file — so excluding it would discard the only reader of every bound key
            // and weaken the reverse direction for nothing.
        ],
    );
});

/**
 * The independent proof that `purchases.settings.stripe.api_version` is alive, standing in
 * for the reverse-direction check that cannot see it (see `allowUnread` above).
 *
 * This is the media #27 bug class — a shipped, documented key that nothing applies — so the
 * key being unverifiable by the scraper is exactly the situation where it deserves a real
 * behavioural pin rather than an allow-list entry and a shrug.
 */
it('applies the configured stripe api version', function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test_x');
    config()->set('purchases.settings.stripe.api_version', '2024-06-20');

    $client = (new ReflectionMethod(Stripe::class, 'client'))
        ->invoke(app(Stripe::class));

    $apiVersion = (new ReflectionProperty($client, 'apiVersion'))->getValue($client);

    expect($apiVersion)->toBe('2024-06-20');
});
