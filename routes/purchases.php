<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Purchases\Http\Controllers\WebhookController;

/** @var array<string, mixed> $routes */
$routes = config('purchases.routes', []);

/** @var string $prefix */
$prefix = $routes['prefix'] ?? 'purchases';

/** @var array<int, string> $middleware */
$middleware = $routes['middleware'] ?? ['api'];

Route::prefix($prefix)
    ->middleware($middleware)
    ->group(function (): void {
        Route::post('webhooks/{provider}', WebhookController::class)
            ->name('purchases.webhooks');
    });
