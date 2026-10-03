<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\ServiceProvider;

/**
 * A host that has NOT published the package's migrations yet.
 *
 * The normal {@see TestCase} pre-loads the package's `database/migrations` so every other
 * test has a schema to work against — which is precisely what a real host does NOT do:
 * migrations are publish-only, and nothing auto-loads them.
 *
 * `purchases:install` is the command whose entire job is to close that gap (publish, then
 * migrate), so it has to be exercised against an empty schema. Running it under the normal
 * TestCase would migrate the package's sources *and* the timestamped copies it just
 * published — two differently-named migrations both running the same `Schema::create` —
 * which is the duplicate-table footgun the publish-only policy exists to remove.
 *
 * It is also a host of its own on disk. `purchases:install` writes config/purchases.php,
 * database/migrations and `base_path('.env')` — the last with no use*Path() setter that
 * reaches it — so the app boots from a throwaway mirror of the testbench skeleton, one per
 * test: every entry links back to the real one (same config, same bootstrap cache), except
 * the paths the command writes, which are real sandbox paths. Nothing lands in the shared
 * skeleton every parallel process boots from: a published config/purchases.php left there
 * is loaded by every later test as the host's own, and a `.env` is read by any process that
 * boots while it exists.
 */
abstract class HostTestCase extends TestCase
{
    /**
     * What `purchases:install` writes: never linked back to the skeleton, or the write
     * would land there through the link. Their parent directories are real sandbox
     * directories whose other entries are linked.
     */
    private const array WRITTEN = ['.env', 'config/purchases.php', 'database/migrations'];

    private string $sandbox = '';

    /**
     * Deliberately empty: the schema is whatever `purchases:install` publishes.
     *
     * This overrides `migrationSources()` rather than `defineDatabaseMigrations()`. The
     * base case resolves its sources through this method, so emptying it is the supported
     * way to say "load nothing" — and, unlike overriding the hook, it leaves
     * `PackageTestCase`'s real-engine reset (drop every table, re-migrate) intact, so the
     * tables `purchases:install` creates here do not survive into the next test on a
     * driver leg.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [];
    }

    protected function getApplicationBasePath(): string
    {
        if ($this->sandbox === '') {
            $this->sandbox = sys_get_temp_dir().'/purchases-host-'.bin2hex(random_bytes(6));

            mkdir($this->sandbox, 0777, true);
            $this->mirror(static::applicationBasePath(), '');
        }

        return $this->sandbox;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->sandbox !== '') {
                // Links are unlinked, never followed: the skeleton itself is untouched.
                (new Filesystem)->deleteDirectory($this->sandbox);
                $this->sandbox = '';
            }
        }
    }

    private function mirror(string $skeleton, string $directory): void
    {
        foreach (scandir($skeleton.'/'.$directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = ltrim($directory.'/'.$entry, '/');

            if (in_array($relative, self::WRITTEN, true)) {
                continue;
            }

            if ($this->holdsWrittenPath($relative)) {
                mkdir($this->sandbox.'/'.$relative);
                $this->mirror($skeleton, $relative);

                continue;
            }

            symlink($skeleton.'/'.$relative, $this->sandbox.'/'.$relative);
        }
    }

    private function holdsWrittenPath(string $relative): bool
    {
        foreach (self::WRITTEN as $written) {
            if (str_starts_with($written, $relative.'/')) {
                return true;
            }
        }

        return false;
    }
}
