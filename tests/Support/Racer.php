<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use Throwable;

/**
 * Real-engine races (the tickets / lifecycle precedent): each operation runs in a forked process
 * on its own database session, all of them released at the same instant. A child never touches
 * the parent's connections (closing one would end the parent's server session): it registers
 * its own, writes its outcome to a file and ends with SIGKILL — no shutdown handlers, no
 * destructors.
 */
final class Racer
{
    public static function available(): bool
    {
        return in_array(DriverMatrix::driver(), ['pgsql', 'mysql'], true)
            && function_exists('pcntl_fork') && function_exists('posix_kill');
    }

    /**
     * @param  list<Closure(): string>  $operations  each returns the outcome to report
     * @return list<string> the outcomes, in the order given
     */
    public static function race(array $operations): array
    {
        $start = microtime(true) + 0.5;
        $runs = [];

        foreach ($operations as $operation) {
            $file = (string) tempnam(sys_get_temp_dir(), 'purchases-race-');
            $pid = pcntl_fork();

            if ($pid === 0) {
                self::child($operation, $file, $start);
            }

            $runs[] = ['pid' => $pid, 'file' => $file];
        }

        $outcomes = [];

        foreach ($runs as $run) {
            pcntl_waitpid($run['pid'], $status);
            $outcomes[] = (string) file_get_contents($run['file']);
            @unlink($run['file']);
        }

        return $outcomes;
    }

    /**
     * @param  Closure(): string  $operation
     */
    private static function child(Closure $operation, string $file, float $start): never
    {
        $outcome = 'error';

        try {
            config()->set('database.connections.racer', DriverMatrix::connectionConfig(DriverMatrix::driver()));
            DB::setDefaultConnection('racer');
            // Connected before the start line, so the race is between the operations.
            DB::connection('racer')->getPdo();

            while (microtime(true) < $start) {
                usleep(1_000);
            }

            $outcome = $operation();
        } catch (Throwable $exception) {
            $outcome = 'error: '.$exception::class.': '.$exception->getMessage();
        }

        file_put_contents($file, $outcome);
        posix_kill(posix_getpid(), SIGKILL);

        exit(1);
    }
}
