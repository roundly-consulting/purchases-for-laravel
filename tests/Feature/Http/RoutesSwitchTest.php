<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException as ToolkitInvalidConfigurationException;
use RoundlyConsulting\Purchases\PurchasesServiceProvider;

/*
 | The webhook routes are opt-in. A switch that is not set — missing from a published config
 | that overrides only part of `routes` (the merge is shallow), `null`, blank, or a bare `env()`
 | with the variable unset — keeps them off. Only an explicit on loads them.
 */

/**
 * Boot the provider again over the current config, as a host app would, and list the webhook
 * routes it registered.
 *
 * @return list<string>
 */
function purchasesWebhookRoutesAfterBoot(): array
{
    $provider = new PurchasesServiceProvider(app());
    $provider->register();
    $provider->boot();

    return collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'purchases.'))
        ->values()
        ->all();
}

test('a_switch_that_is_not_set_keeps_the_api_off', function (string $shape): void {
    match ($shape) {
        'a routes array without the switch' => config()->set('purchases.routes', ['prefix' => 'purchases']),
        'null' => config()->set('purchases.routes.enabled', null),
        'a blank env value' => config()->set('purchases.routes.enabled', ''),
        'a bare env() with the variable unset' => config()->set('purchases.routes.enabled', env('PURCHASES_ROUTES_NEVER_SET_IN_TESTS')),
    };

    expect(purchasesWebhookRoutesAfterBoot())->toBe([]);

    $this->postJson('/purchases/webhooks/stripe', [])->assertNotFound();
})->with(['a routes array without the switch', 'null', 'a blank env value', 'a bare env() with the variable unset']);

test('an_explicit_switch_still_turns_the_api_on', function (mixed $switch): void {
    config()->set('purchases.routes.enabled', $switch);

    expect(purchasesWebhookRoutesAfterBoot())->toBe(['purchases.webhooks']);

    Route::getRoutes()->refreshNameLookups();

    expect(Route::getRoutes()->getByName('purchases.webhooks')?->uri())->toBe('purchases/webhooks/{provider}');
})->with(['true' => [true], 'on' => ['on'], '1' => ['1'], 'yes' => ['yes']]);

test('an_explicit_off_switch_keeps_the_api_off', function (mixed $switch): void {
    config()->set('purchases.routes.enabled', $switch);

    expect(purchasesWebhookRoutesAfterBoot())->toBe([]);
})->with(['false' => [false], 'off' => ['off'], '0' => ['0'], 'no' => ['no']]);

test('a_mistyped_switch_still_throws_rather_than_loading_the_api', function (): void {
    config()->set('purchases.routes.enabled', 'disabled');

    expect(fn (): array => purchasesWebhookRoutesAfterBoot())->toThrow(
        ToolkitInvalidConfigurationException::class,
        'Configuration value [purchases.routes.enabled] must be a boolean (true/false, 1/0, on/off or yes/no), [disabled] given.',
    );
});
