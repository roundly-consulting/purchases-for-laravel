<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Purchases\Contracts\VerifiesConnectivity;
use RoundlyConsulting\Purchases\Purchases;

/**
 * Actively checks each provider's credentials by making a cheap authenticated
 * call, reporting whether they genuinely work rather than just being present.
 */
final class VerifyCommand extends Command
{
    protected $signature = 'purchases:verify {provider? : Limit the check to one provider}';

    protected $description = 'Verify that each provider\'s credentials genuinely work.';

    public function handle(Purchases $purchases): int
    {
        $only = $this->argument('provider');

        $rows = [];
        $failures = 0;

        foreach ($purchases->ids() as $id) {
            if (is_string($only) && $only !== '' && $id !== $only) {
                continue;
            }

            $provider = $purchases->provider($id);

            if (! $provider instanceof VerifiesConnectivity) {
                $rows[] = [$id, 'skipped', 'No connectivity check available.'];

                continue;
            }

            $result = $provider->verifyConnectivity();

            $rows[] = [$id, $result->ok ? 'ok' : 'failed', $result->message];

            if (! $result->ok) {
                $failures++;
            }
        }

        if ($rows === []) {
            $this->warn('No matching providers to verify.');

            return self::SUCCESS;
        }

        $this->table(['Provider', 'Status', 'Detail'], $rows);

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
