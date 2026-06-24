<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Purchases\Purchases;

final class ProvidersCommand extends Command
{
    protected $signature = 'purchases:providers';

    protected $description = 'List the configured purchase providers and their readiness.';

    public function handle(Purchases $purchases): int
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
            'apple' => filled(config('purchases.settings.apple.password'))
                || filled(config('purchases.settings.apple.api.private_key')),
            'google' => filled(config('purchases.settings.google.package_name'))
                && filled(config('purchases.settings.google.service_account.client_email')),
            'stripe' => filled(config('purchases.settings.stripe.secret'))
                || filled(config('purchases.settings.stripe.webhook_secret')),
            default => true,
        };
    }
}
