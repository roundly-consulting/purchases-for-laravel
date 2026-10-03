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
            // The provider settings are read through Support\PurchasesConfig's own strict
            // readers, handed an offset of a section resolved once
            // (`PurchasesConfig::string($this->config['base_url'] ?? null,
            // 'purchases.settings.stripe.base_url', …)`). The full key is a literal argument
            // the scraper does not follow into the reader, so the section prefix is what
            // counts it. (The model, audit, queue and key-type seams go through
            // package-toolkit's readers, which the contract reads natively.)
            //
            // Scoped to the section rather than a bare 'purchases.'. The broad prefix counts
            // ANY literal under it as a config read — which sweeps up
            // `hasRoutes('purchases.php', …)`, a ROUTES FILENAME, and reports
            // `purchases.php` as a config key the file does not ship. Unlike
            // allowUnread/allowUnshipped, extraReadPrefixes is NOT rot-checked — a redundant
            // entry silences no failure and so can never be noticed, while still carrying
            // that false-positive risk. That asymmetry is why a prefix earns its place or goes.
            'extraReadPrefixes' => ['purchases.settings.'],

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
                // `authenticate(Request $request, array $config)` is handed
                // config('purchases.settings.google.push') by Google::notification().
                'PushAuthenticator.php' => ['$config' => 'purchases.settings.google.push'],
            ],

            // `purchases.settings.stripe.api_version` is read through
            // `PurchasesConfig::string($this->config['api_version'] ?? null,
            // 'purchases.settings.stripe.api_version', …)` — the strict reader names its full
            // key as a literal, which the `purchases.settings.` prefix counts. (A bare
            // `$this->config['api_version']` offset is three tokens and was unmappable, so
            // the key used to sit in allowUnread.) The behavioural pin below stays.

            // Deliberately NO `excludeFromReverse` for the service provider. It renders
            // the `about` section (a render is not a read), but the toolkit's
            // PackageServiceProvider ALSO does its real `bindFromConfig()` reads in the
            // same file — so excluding it would discard the only reader of every bound key
            // and weaken the reverse direction for nothing.
        ],
    );
});

/**
 * The independent proof that `purchases.settings.stripe.api_version` is alive, kept as a
 * behavioural pin alongside the reverse-direction check.
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
