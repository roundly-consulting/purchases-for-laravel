<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases;

use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\Commands\InstallCommand;
use RoundlyConsulting\Purchases\Commands\ProvidersCommand;
use RoundlyConsulting\Purchases\Commands\ReplayCommand;
use RoundlyConsulting\Purchases\Commands\VerifyCommand;
use RoundlyConsulting\Purchases\Providers\Resolver;
use RoundlyConsulting\Purchases\Support\PurchaseModel;
use RoundlyConsulting\Purchases\Support\PurchaseNotificationModel;
use RoundlyConsulting\Purchases\Support\PurchaseRefundModel;
use RoundlyConsulting\Purchases\Support\SubscriptionModel;

final class PurchasesServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('purchases')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasRoutes('purchases.php', 'purchases.routes.enabled')
            ->hasCommands([
                InstallCommand::class,
                ProvidersCommand::class,
                VerifyCommand::class,
                ReplayCommand::class,
            ])
            // This package's config holds live payment credentials: Apple's shared
            // secret and App Store private key, a Google service-account key, and
            // Stripe's API key and webhook signing secret. Nothing here renders a
            // value — credentials report as SET/MISSING, host-supplied endpoints as
            // DEFAULT/CUSTOM, and the queue topology as SET/DEFAULT.
            ->contributesToAbout(static fn (): array => [
                'Purchase model' => class_basename(PurchaseModel::class()),
                'Subscription model' => class_basename(SubscriptionModel::class()),
                'Refund model' => class_basename(PurchaseRefundModel::class()),
                'Notification model' => class_basename(PurchaseNotificationModel::class()),
                'Providers' => self::providerCount(),
                'Apple credentials' => self::presence(self::appleConfigured()),
                'Apple environment' => Config::boolean('purchases.settings.apple.sandbox') ? 'SANDBOX' : 'LIVE',
                'Apple clock skew' => self::seconds('purchases.settings.apple.certificate_clock_skew', 60),
                'Google credentials' => self::presence(self::googleConfigured()),
                'Google acknowledgement' => Config::boolean('purchases.settings.google.acknowledge', true) ? 'ON' : 'OFF',
                'Stripe API key' => self::presence(filled(config('purchases.settings.stripe.secret'))),
                'Stripe webhook secret' => self::presence(filled(config('purchases.settings.stripe.webhook_secret'))),
                'Stripe tolerance' => self::seconds('purchases.settings.stripe.tolerance', 300),
                'Provider endpoints' => self::endpoints(),
                'Audit log' => Config::boolean('purchases.audit.enabled', true) ? 'ON' : 'OFF',
                'Queue processing' => self::queue(),
                'Webhook routes' => self::routes(),
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->scoped(Resolver::class);
        $this->app->singleton(PurchasesManager::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The migrations' key-type-aware morph columns are macros, so they must
        // exist before a host runs `php artisan migrate`.
        $this->registerBlueprintMacros();
    }

    /**
     * The number of registered providers, never their class names — a host may
     * register a provider of its own, and that class name is its code, not ours.
     */
    private static function providerCount(): string
    {
        $providers = config('purchases.providers');

        if (! is_array($providers) || $providers === []) {
            return 'NONE';
        }

        return count($providers).' registered';
    }

    /**
     * Whether Apple can be talked to at all: the legacy shared secret, or an App
     * Store Server API key. Presence only — both are credentials.
     */
    private static function appleConfigured(): bool
    {
        return filled(config('purchases.settings.apple.password'))
            || filled(config('purchases.settings.apple.api.private_key'));
    }

    private static function googleConfigured(): bool
    {
        return filled(config('purchases.settings.google.package_name'))
            && filled(config('purchases.settings.google.service_account.client_email'));
    }

    /**
     * Whether any provider endpoint has been pointed somewhere other than its
     * packaged default — a custom base URL is a host's own infrastructure (a proxy,
     * a sandbox gateway), so the destination itself never renders.
     */
    private static function endpoints(): string
    {
        $defaults = [
            'purchases.settings.apple.url.live' => 'https://buy.itunes.apple.com',
            'purchases.settings.apple.url.sandbox' => 'https://sandbox.itunes.apple.com',
            'purchases.settings.apple.api.url.live' => 'https://api.storekit.itunes.apple.com',
            'purchases.settings.apple.api.url.sandbox' => 'https://api.storekit-sandbox.itunes.apple.com',
            'purchases.settings.google.base_url' => 'https://androidpublisher.googleapis.com',
            'purchases.settings.google.service_account.token_uri' => 'https://oauth2.googleapis.com/token',
            'purchases.settings.stripe.base_url' => 'https://api.stripe.com/v1',
        ];

        $overridden = 0;

        foreach ($defaults as $key => $default) {
            if (config($key) !== $default) {
                $overridden++;
            }
        }

        return $overridden === 0 ? 'DEFAULT' : $overridden.' overridden';
    }

    /**
     * The queue connection and queue name are the host's own topology (the #12
     * cosmos-logging rule), so only their presence renders.
     */
    private static function queue(): string
    {
        if (! Config::boolean('purchases.queue.enabled')) {
            return 'OFF';
        }

        return sprintf(
            'ON (connection %s, queue %s)',
            filled(config('purchases.queue.connection')) ? 'SET' : 'DEFAULT',
            filled(config('purchases.queue.queue')) ? 'SET' : 'DEFAULT',
        );
    }

    /**
     * The route prefix is part of the host's public URL space, so it is reported as
     * present, never printed.
     */
    private static function routes(): string
    {
        if (! Config::boolean('purchases.routes.enabled')) {
            return 'OFF';
        }

        $middleware = config('purchases.routes.middleware');
        $count = is_array($middleware) ? count($middleware) : 0;

        return sprintf(
            'ON (prefix %s, %d middleware)',
            filled(config('purchases.routes.prefix')) ? 'SET' : 'DEFAULT',
            $count,
        );
    }

    private static function presence(bool $configured): string
    {
        return $configured ? 'SET' : 'MISSING';
    }

    private static function seconds(string $key, int $default): string
    {
        $value = config($key, $default);

        return (is_int($value) || is_string($value) ? (int) $value : $default).'s';
    }
}
