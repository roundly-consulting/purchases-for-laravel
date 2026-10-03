<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Providers\Apple\AppIdentity;
use RoundlyConsulting\Purchases\Providers\Apple\Apple;
use RoundlyConsulting\Purchases\Providers\Apple\AppStoreServerApi;
use RoundlyConsulting\Purchases\Providers\Google\Auth\PushAuthenticator;
use RoundlyConsulting\Purchases\Providers\Google\Auth\ServiceAccountCredentials;
use RoundlyConsulting\Purchases\Providers\Google\Google;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Testing\FakeResult;

/*
 | Owner rule: a typo in a host's config fails loudly and never falls back silently. A junk
 | Stripe tolerance used to cast to 0 — which switches the webhook replay window OFF — and junk
 | URLs, TTLs and queue names quietly became their defaults.
 */

function invokePrivate(object $object, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
}

it('refuses a junk stripe tolerance instead of disabling the replay window (strict config)', function (mixed $tolerance): void {
    config()->set('purchases.settings.stripe.webhook_secret', 'whsec_test');
    config()->set('purchases.settings.stripe.tolerance', $tolerance);

    expect(fn () => app(Stripe::class)->event(stripeSignedRequest(['id' => 'evt_1', 'type' => 'charge.succeeded'])))
        ->toThrow(InvalidConfigurationException::class, '[purchases.settings.stripe.tolerance]');
})->with([
    'word' => 'five',
    'decimal' => '300.5',
    'empty' => '',
    'zero' => 0,
    'negative' => '-1',
]);

it('reads a canonical integer-string stripe tolerance (strict config)', function (): void {
    config()->set('purchases.settings.stripe.webhook_secret', 'whsec_test');
    config()->set('purchases.settings.stripe.tolerance', '60');

    $request = stripeSignedRequest(['id' => 'evt_1', 'type' => 'charge.succeeded'], 'whsec_other');

    expect(fn () => app(Stripe::class)->event($request))->toThrow(VerificationException::class);
});

it('refuses a blank or wrong-typed provider endpoint instead of using the default (strict config)', function (string $key, mixed $value, Closure $read): void {
    config()->set('purchases.settings.apple.sandbox', false);
    config()->set($key, $value);

    expect($read)->toThrow(InvalidConfigurationException::class, "[{$key}]");
})->with([
    'stripe base url' => ['purchases.settings.stripe.base_url', ['x'], function (): void {
        config()->set('purchases.settings.stripe.secret', 'sk_test');
        invokePrivate(app(Stripe::class), 'client');
    }],
    'stripe api version' => ['purchases.settings.stripe.api_version', '', function (): void {
        config()->set('purchases.settings.stripe.secret', 'sk_test');
        invokePrivate(app(Stripe::class), 'client');
    }],
    'google base url' => ['purchases.settings.google.base_url', 1, function (): void {
        config()->set('purchases.settings.google.service_account', ['client_email' => 'a@b.c', 'private_key' => 'k']);
        invokePrivate(app(Google::class), 'client');
    }],
    'apple live url' => ['purchases.settings.apple.url.live', '', fn () => invokePrivate(app(Apple::class), 'getBaseUrl')],
    'apple api live url' => ['purchases.settings.apple.api.url.live', ['x'], fn () => invokePrivate(app(AppStoreServerApi::class), 'baseUrl')],
]);

it('uses the packaged endpoints only when the keys are absent (strict config)', function (): void {
    config()->set('purchases.settings.stripe.secret', 'sk_test');
    config()->set('purchases.settings.stripe.base_url', null);
    config()->set('purchases.settings.stripe.api_version', null);
    config()->set('purchases.settings.apple.sandbox', false);
    config()->set('purchases.settings.apple.url', null);
    config()->set('purchases.settings.apple.api.url', null);

    $client = invokePrivate(app(Stripe::class), 'client');

    expect((new ReflectionProperty($client, 'apiVersion'))->getValue($client))->toBe(Stripe::API_VERSION)
        ->and((new ReflectionProperty($client, 'baseUrl'))->getValue($client))->toBe('https://api.stripe.com/v1')
        ->and(invokePrivate(app(Apple::class), 'getBaseUrl'))->toBe('https://buy.itunes.apple.com')
        ->and(invokePrivate(app(AppStoreServerApi::class), 'baseUrl'))->toBe('https://api.storekit.itunes.apple.com');
});

it('refuses a wrong-typed google token uri (strict config)', function (): void {
    expect(fn () => ServiceAccountCredentials::fromConfig(['client_email' => 'a@b.c', 'private_key' => 'k', 'token_uri' => ['x']]))
        ->toThrow(InvalidConfigurationException::class, '[purchases.settings.google.service_account.token_uri]');
});

it('refuses a junk jwks cache ttl instead of using 3600 (strict config)', function (mixed $ttl): void {
    Http::fake(['*' => Http::response(['keys' => [['kid' => 'k1', 'kty' => 'RSA']]])]);

    expect(fn () => invokePrivate(new PushAuthenticator, 'keys', ['jwks_cache_ttl' => $ttl], true))
        ->toThrow(InvalidConfigurationException::class, '[purchases.settings.google.push.jwks_cache_ttl]');
})->with(['hour', '0', '1.5']);

it('refuses a wrong-typed jwks url (strict config)', function (): void {
    expect(fn () => invokePrivate(new PushAuthenticator, 'keys', ['jwks_url' => ['x']], true))
        ->toThrow(InvalidConfigurationException::class, '[purchases.settings.google.push.jwks_url]');
});

it('refuses a non-string push credential instead of reading it as unset (strict config)', function (string $field): void {
    $config = ['audience' => 'aud', 'service_account_email' => 'sa@x.iam', 'token' => 'secret', $field => ['oops']];

    expect(fn () => (new PushAuthenticator)->authenticate(Request::create('/push?token=secret', 'POST'), $config))
        ->toThrow(InvalidConfigurationException::class, "[purchases.settings.google.push.{$field}]");
})->with(['audience', 'service_account_email', 'token']);

it('refuses a wrong-typed apple app id instead of skipping the check (strict config)', function (): void {
    config()->set('purchases.settings.apple.bundle_id', 'com.example.app');
    config()->set('purchases.settings.apple.app_apple_id', ['123']);

    expect(fn () => AppIdentity::fromConfig())
        ->toThrow(InvalidConfigurationException::class, '[purchases.settings.apple.app_apple_id]');
});

it('refuses a blank or wrong-typed queue topology (strict config)', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => new ProcessProviderNotification(FakeResult::purchase()))
        ->toThrow(InvalidConfigurationException::class, "[{$key}]");
})->with([
    'connection ""' => ['purchases.queue.connection', ''],
    'queue array' => ['purchases.queue.queue', ['high']],
]);

it('queues on the configured topology (strict config)', function (): void {
    config()->set('purchases.queue.connection', 'redis');
    config()->set('purchases.queue.queue', 'purchases');

    $job = new ProcessProviderNotification(FakeResult::purchase());

    expect($job->connection)->toBe('redis')->and($job->queue)->toBe('purchases');
});

it('refuses a malformed route prefix or middleware list (strict config)', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => require __DIR__.'/../../routes/purchases.php')
        ->toThrow(InvalidConfigurationException::class, "[{$key}]");
})->with([
    'prefix array' => ['purchases.routes.prefix', ['hooks']],
    'prefix blank' => ['purchases.routes.prefix', ' '],
    'middleware string' => ['purchases.routes.middleware', 'api'],
    'middleware non-string entry' => ['purchases.routes.middleware', ['api', 1]],
]);

it('refuses a malformed provider list instead of registering none (strict config)', function (mixed $providers): void {
    config()->set('purchases.providers', $providers);

    expect(fn () => new Resolver)->toThrow(InvalidConfigurationException::class, '[purchases.providers]');
})->with([
    'a string' => [Stripe::class],
    'not a provider' => [[stdClass::class]],
    'a blank entry' => [['']],
]);

it('reports a junk duration or provider list as INVALID in about (strict config)', function (): void {
    config()->set('purchases.settings.stripe.tolerance', 'five');
    config()->set('purchases.settings.apple.certificate_clock_skew', 'a minute');
    config()->set('purchases.providers', 'Stripe');

    Artisan::call('about', ['--only' => 'purchases']);

    expect(Artisan::output())
        ->toMatch('/Stripe tolerance\s*\.*\s*INVALID/')
        ->toMatch('/Apple clock skew\s*\.*\s*INVALID/')
        ->toMatch('/Providers\s*\.*\s*INVALID/');
});
