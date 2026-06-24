<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Commands;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'purchases:install';

    protected $description = 'Publish the Purchases config and migrations.';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', ['--tag' => 'purchases-config']);
        $this->callSilent('vendor:publish', ['--tag' => 'purchases-migrations']);

        $this->info('Published the Purchases config and migrations.');

        if ($this->confirm('Run the migrations now?', false)) {
            $this->call('migrate');
        }

        return self::SUCCESS;
    }
}
