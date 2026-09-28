<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Commands;

use Illuminate\Console\Command;

use function Laravel\Prompts\multiselect;

final class InstallCommand extends Command
{
    protected $signature = 'purchases:install {--providers : Interactively choose providers and append their env keys}';

    protected $description = 'Publish the Purchases config and migrations.';

    /**
     * The env keys appended for each provider when running interactively.
     *
     * @var array<string, list<string>>
     */
    private const ENV_KEYS = [
        'apple' => [
            '# Apple: the app notifications are accepted for (the Apple ID is required in production)',
            'PURCHASES_APPLE_BUNDLE_ID=',
            'PURCHASES_APPLE_APP_APPLE_ID=',
            'PURCHASES_APPLE_SANDBOX=false',
            '# Apple App Store Server API credentials',
            'PURCHASES_APPLE_KEY_ID=',
            'PURCHASES_APPLE_ISSUER_ID=',
            'PURCHASES_APPLE_PRIVATE_KEY=',
            'PURCHASES_APPLE_PASSWORD=',
        ],
        'google' => [
            '# Google Play Developer API service-account credentials',
            'PURCHASES_GOOGLE_PACKAGE_NAME=',
            'PURCHASES_GOOGLE_CLIENT_EMAIL=',
            'PURCHASES_GOOGLE_PRIVATE_KEY=',
        ],
        'stripe' => [
            '# Stripe API and webhook credentials',
            'PURCHASES_STRIPE_SECRET=',
            'PURCHASES_STRIPE_WEBHOOK_SECRET=',
        ],
    ];

    public function handle(): int
    {
        $this->callSilent('vendor:publish', ['--tag' => 'purchases-config']);
        $this->callSilent('vendor:publish', ['--tag' => 'purchases-migrations']);

        $this->info('Published the Purchases config and migrations.');

        if ($this->option('providers')) {
            $this->appendProviderEnv();
        }

        if ($this->confirm('Run the migrations now?', false)) {
            $this->call('migrate');
        }

        return self::SUCCESS;
    }

    private function appendProviderEnv(): void
    {
        /** @var list<string> $providers */
        $providers = multiselect(
            label: 'Which providers would you like to enable?',
            options: array_keys(self::ENV_KEYS),
            hint: 'Their environment variables will be appended to your .env file.',
        );

        if ($providers === []) {
            return;
        }

        $lines = [''];

        foreach ($providers as $provider) {
            foreach (self::ENV_KEYS[$provider] as $line) {
                $lines[] = $line;
            }

            $lines[] = '';
        }

        $path = base_path('.env');
        $existing = is_file($path) ? (string) file_get_contents($path) : '';

        file_put_contents($path, rtrim($existing)."\n".implode("\n", $lines)."\n");

        $this->info('Appended provider environment keys to .env.');
    }
}
