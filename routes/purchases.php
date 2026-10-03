<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Purchases\Http\Controllers\WebhookController;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;

Route::prefix(PurchasesConfig::string(config('purchases.routes.prefix'), 'purchases.routes.prefix', 'purchases'))
    ->middleware(PurchasesConfig::strings(config('purchases.routes.middleware'), 'purchases.routes.middleware', ['api']))
    ->group(function (): void {
        Route::post('webhooks/{provider}', WebhookController::class)
            ->name('purchases.webhooks');
    });
