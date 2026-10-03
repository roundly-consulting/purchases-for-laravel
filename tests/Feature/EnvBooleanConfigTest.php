<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException as ToolkitInvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Actions\HandleProviderResultAction;
use RoundlyConsulting\Purchases\Actions\RecordProviderNotificationAction;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Jobs\ProcessProviderNotification;
use RoundlyConsulting\Purchases\Models\PurchaseNotification;
use RoundlyConsulting\Purchases\Providers\Google\Auth\PushAuthenticator;
use RoundlyConsulting\Purchases\Testing\FakeResult;

/*
 | Every boolean switch is a string when it comes from the environment. `PURCHASES_*=1`,
 | `=on` and `=yes` must read as on, `=0`, `=off` and `=no` as off — never the reverse, and
 | never "silently off" because a strict `=== true` met the string "1". The config file hands
 | the raw string through untouched, so the strict reader can refuse a typo instead of the
 | file quietly turning it into the default.
 */

/**
 * Evaluate the shipped config file with one env variable set, the way a host boots it.
 *
 * @return array<string, mixed>
 */
function purchasesConfigWithEnv(string $name, string $value): array
{
    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");

    try {
        /** @var array<string, mixed> */
        return require __DIR__.'/../../config/purchases.php';
    } finally {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);
    }
}

it('reads every boolean env switch as a real boolean', function (string $name, string $path, string $value, bool $expected): void {
    $config = purchasesConfigWithEnv($name, $value);

    expect(data_get($config, $path))->toBe($value)
        ->and(Config::for($config)->boolean($path))->toBe($expected);
})->with([
    'audit 1' => ['PURCHASES_AUDIT_ENABLED', 'audit.enabled', '1', true],
    'audit 0' => ['PURCHASES_AUDIT_ENABLED', 'audit.enabled', '0', false],
    'audit off' => ['PURCHASES_AUDIT_ENABLED', 'audit.enabled', 'off', false],
    'queue 1' => ['PURCHASES_QUEUE_ENABLED', 'queue.enabled', '1', true],
    'queue yes' => ['PURCHASES_QUEUE_ENABLED', 'queue.enabled', 'yes', true],
    'queue no' => ['PURCHASES_QUEUE_ENABLED', 'queue.enabled', 'no', false],
    'routes on' => ['PURCHASES_ROUTES_ENABLED', 'routes.enabled', 'on', true],
    'routes off' => ['PURCHASES_ROUTES_ENABLED', 'routes.enabled', 'off', false],
    'apple sandbox 1' => ['PURCHASES_APPLE_SANDBOX', 'settings.apple.sandbox', '1', true],
    'apple sandbox off' => ['PURCHASES_APPLE_SANDBOX', 'settings.apple.sandbox', 'off', false],
    'google acknowledge 0' => ['PURCHASES_GOOGLE_ACKNOWLEDGE', 'settings.google.acknowledge', '0', false],
    'google acknowledge off' => ['PURCHASES_GOOGLE_ACKNOWLEDGE', 'settings.google.acknowledge', 'off', false],
    'google push authenticate no' => ['PURCHASES_GOOGLE_PUSH_AUTHENTICATE', 'settings.google.push.authenticate', 'no', false],
]);

it('hands a mistyped env switch through raw (strict config)', function (string $name, string $path): void {
    expect(data_get(purchasesConfigWithEnv($name, 'disabled'), $path))->toBe('disabled');
})->with([
    'audit' => ['PURCHASES_AUDIT_ENABLED', 'audit.enabled'],
    'queue' => ['PURCHASES_QUEUE_ENABLED', 'queue.enabled'],
    'routes' => ['PURCHASES_ROUTES_ENABLED', 'routes.enabled'],
    'apple sandbox' => ['PURCHASES_APPLE_SANDBOX', 'settings.apple.sandbox'],
    'google acknowledge' => ['PURCHASES_GOOGLE_ACKNOWLEDGE', 'settings.google.acknowledge'],
    'google push authenticate' => ['PURCHASES_GOOGLE_PUSH_AUTHENTICATE', 'settings.google.push.authenticate'],
]);

it('keeps the switch defaults when the env is unset', function (string $path, bool $default): void {
    expect(data_get(purchasesConfigWithEnv('PURCHASES_UNRELATED', 'x'), $path))->toBe($default);
})->with([
    'audit' => ['audit.enabled', true],
    'queue' => ['queue.enabled', false],
    'routes' => ['routes.enabled', false],
    'apple sandbox' => ['settings.apple.sandbox', false],
    'google acknowledge' => ['settings.google.acknowledge', true],
    'google push authenticate' => ['settings.google.push.authenticate', true],
]);

it('refuses a mistyped switch at its read path (strict config)', function (string $key, Closure $read): void {
    config()->set($key, 'disabled');

    expect($read)->toThrow(
        ToolkitInvalidConfigurationException::class,
        "Configuration value [{$key}] must be a boolean (true/false, 1/0, on/off or yes/no), [disabled] given.",
    );
})->with([
    'audit' => ['purchases.audit.enabled', fn () => app(RecordProviderNotificationAction::class)->execute(FakeResult::purchase('stripe', 'pi_typo'))],
    'queue' => ['purchases.queue.enabled', fn () => app(HandleProviderResultAction::class)->execute(FakeResult::purchase('stripe', 'pi_typo_queue'))],
]);

it('writes an audit row when auditing is switched on with an env string', function (): void {
    config()->set('purchases.audit.enabled', '1');

    expect(app(RecordProviderNotificationAction::class)->execute(FakeResult::purchase('stripe', 'pi_env')))
        ->toBeInstanceOf(PurchaseNotification::class);
});

it('queues recording when the queue is switched on with an env string', function (): void {
    Queue::fake();
    config()->set('purchases.queue.enabled', '1');

    app(HandleProviderResultAction::class)->execute(FakeResult::purchase('stripe', 'pi_env_queue'));

    Queue::assertPushed(ProcessProviderNotification::class);
});

it('reports env-string switches truthfully in about', function (): void {
    config()->set('purchases.audit.enabled', '1');
    config()->set('purchases.queue.enabled', 'on');
    config()->set('purchases.settings.google.acknowledge', 'yes');
    config()->set('purchases.settings.apple.sandbox', '1');

    Artisan::call('about', ['--only' => 'purchases', '--json' => true]);

    /** @var array<string, array<string, string>> $about */
    $about = json_decode(Artisan::output(), true);

    expect($about['purchases']['audit_log'])->toBe('ON')
        ->and($about['purchases']['queue_processing'])->toStartWith('ON')
        ->and($about['purchases']['google_acknowledgement'])->toBe('ON')
        ->and($about['purchases']['apple_environment'])->toBe('SANDBOX');
});

it('refuses a google push when the authenticate switch is not a boolean (strict config)', function (): void {
    app(PushAuthenticator::class)->authenticate(Request::create('/push', 'POST'), ['authenticate' => 'maybe']);
})->throws(
    InvalidConfigurationException::class,
    'Configuration value [purchases.settings.google.push.authenticate] must be a boolean (true/false, 1/0, on/off or yes/no), [maybe] given.',
);

it('skips google push authentication only for a switch that reads as off', function (): void {
    app(PushAuthenticator::class)->authenticate(Request::create('/push', 'POST'), ['authenticate' => 'off']);

    expect(true)->toBeTrue(); // reached: nothing was demanded of the request
});

it('defaults apple to production when the sandbox switch is unset', function (): void {
    // A production host that never sets PURCHASES_APPLE_SANDBOX must not call Apple's
    // sandbox hosts, nor accept Sandbox notifications.
    expect(data_get(purchasesConfigWithEnv('PURCHASES_AUDIT_ENABLED', 'true'), 'settings.apple.sandbox'))->toBeFalse();
});
