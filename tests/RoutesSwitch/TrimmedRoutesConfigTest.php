<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('a_published_routes_array_without_the_switch_keeps_the_api_off', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'purchases.'));

    expect(config('purchases.routes'))->toBe(['prefix' => 'billing-hooks'])
        ->and($names)->toHaveCount(0);

    $this->postJson('/billing-hooks/webhooks/stripe', [])->assertNotFound();
});
