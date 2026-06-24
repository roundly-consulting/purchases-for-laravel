<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Purchases\Providers\Resolver;

final class PurchasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/purchases.php', 'purchases');

        $this->app->scoped(Resolver::class);
        $this->app->singleton(Purchases::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('purchases.routes.enabled', false) === true) {
            $this->loadRoutesFrom(__DIR__.'/../routes/purchases.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                Commands\InstallCommand::class,
                Commands\ProvidersCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/purchases.php' => config_path('purchases.php'),
            ], 'purchases-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'purchases-migrations');

            $this->publishes([
                __DIR__.'/../routes/purchases.php' => base_path('routes/purchases.php'),
            ], 'purchases-routes');
        }
    }
}
