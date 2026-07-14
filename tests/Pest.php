<?php

declare(strict_types=1);

use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Es;
use RoundlyConsulting\Crypto\Signature\Key\EcKey;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;
use RoundlyConsulting\Purchases\Tests\HostTestCase;
use RoundlyConsulting\Purchases\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/ArchTest.php', __DIR__.'/Feature', __DIR__.'/Unit');

/*
 * `tests/Host` is exercised as a real host: nothing published, nothing auto-loaded, so
 * the schema starts EMPTY. Migrations are publish-only, and the normal TestCase
 * pre-loads them purely so the rest of the suite has tables — which is exactly what a
 * host does not do, and what `purchases:install` exists to fix. Pest will not let a
 * nested path narrow a broader `in()`, so the two roots are listed side by side.
 */
uses(HostTestCase::class)->in(__DIR__.'/Host');

/**
 * A committed Apple-shaped fixture (tests/Fixtures/apple).
 *
 * The chain fixtures are static on purpose: their fingerprints and validity
 * windows are frozen vectors, so they survive an engine swap and make the
 * expiry matrix deterministic.
 */
function appleFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/apple/'.$name);
}

/**
 * A committed certificate as one `x5c` header entry: standard (padded) base64
 * of the DER — RFC 7515 §4.1.6, NOT base64url.
 */
function appleFixtureX5c(string $name): string
{
    return (string) preg_replace('/-----.*?-----|\s+/', '', appleFixture($name));
}

/**
 * The genuine fixture chain, leaf → root.
 *
 * @return list<string>
 */
function appleFixtureChain(): array
{
    return [
        appleFixtureX5c('leaf.pem'),
        appleFixtureX5c('intermediate.pem'),
        appleFixtureX5c('root.pem'),
    ];
}

/**
 * Mint an Apple-shaped ES256 notification signed by a committed private key.
 *
 * @param  array<string, mixed>  $claims
 * @param  list<string>  $x5c
 */
function appleFixtureToken(array $claims, array $x5c, string $keyFixture = 'leaf-key.pem'): string
{
    $signer = new Es(EcKey::private(appleFixture($keyFixture)));

    return (new Jws)->sign(['x5c' => $x5c], $claims, $signer);
}

/**
 * The production verifier with its anchors swapped for a throwaway CA's, so the
 * positive trust path can be exercised (we cannot sign with Apple's key).
 * Everything else — CHAIN_LENGTH, the linkage check, the validity ruling, the
 * ES256 pin — is the production code.
 *
 * @param  list<string>  $fingerprints  [intermediate, root], SHA-1, lower-case hex
 */
function appleVerifierPinnedTo(array $fingerprints): JwsVerifier
{
    return new class($fingerprints) extends JwsVerifier
    {
        /** @param  list<string>  $pins */
        public function __construct(private readonly array $pins)
        {
            parent::__construct();
        }

        /** @return list<string> */
        protected function fingerprints(): array
        {
            return $this->pins;
        }
    };
}
