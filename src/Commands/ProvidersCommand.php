<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Purchases\PurchasesManager;

final class ProvidersCommand extends Command
{
    protected $signature = 'purchases:providers';

    protected $description = 'List the configured purchase providers and their readiness.';

    public function handle(PurchasesManager $purchases): int
    {
        $rows = [];

        foreach ($purchases->ids() as $id) {
            $rows[] = [$id, $this->isConfigured($id) ? 'yes' : 'missing config'];
        }

        if ($rows === []) {
            $this->warn('No providers are registered in config/purchases.php.');

            return self::SUCCESS;
        }

        $this->table(['Provider', 'Configured'], $rows);

        return self::SUCCESS;
    }

    private function isConfigured(string $id): bool
    {
        return match ($id) {
            // Notifications are only accepted for the configured app — and, in
            // production, its Apple ID — so without those Apple can take no traffic.
            'apple' => filled(config('purchases.settings.apple.bundle_id'))
                && (Config::boolean('purchases.settings.apple.sandbox') || filled(config('purchases.settings.apple.app_apple_id'))),
            'google' => filled(config('purchases.settings.google.package_name'))
                && filled(config('purchases.settings.google.service_account.client_email')),
            'stripe' => filled(config('purchases.settings.stripe.secret'))
                || filled(config('purchases.settings.stripe.webhook_secret')),
            default => true,
        };
    }
}
