<?php

declare(strict_types=1);

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

/*
 * Crypto primitives are crypto-for-laravel's, not ours: HMAC, constant-time
 * compares, RSA/ECDSA sign+verify, key loading, X.509 parsing/fingerprints/chain
 * linkage, base64(url), and CSPRNG bytes all route through RoundlyConsulting\Crypto.
 *
 * The openssl_x509_* family used to be exempt, because Apple's chain trust lived
 * here on top of raw OpenSSL. It no longer does: crypto's X509 module supplies the
 * mathematics and the ban is now package-wide. The *trust* ruling — Apple's pinned
 * WWDR/G3 anchors, the chain length, and the validity policy — still lives here, in
 * Apple\Jws\JwsVerifier, and always will.
 */
arch('no crypto primitive is re-implemented locally')
    ->expect('RoundlyConsulting\Purchases')
    ->not->toUse([
        'hash_hmac',
        'hash_equals',
        'openssl_sign',
        'openssl_verify',
        'openssl_pkey_new',
        'openssl_pkey_get_private',
        'openssl_pkey_get_public',
        'openssl_pkey_get_details',
        'openssl_pkey_export',
        'openssl_x509_read',
        'openssl_x509_parse',
        'openssl_x509_export',
        'openssl_x509_fingerprint',
        'openssl_x509_verify',
        'random_bytes',
        'base64_encode',
        'base64_decode',
    ]);

it('calls no openssl function anywhere in src', function (): void {
    $offenders = [];

    foreach (packageSourceFiles() as $file) {
        if (preg_match('/\bopenssl_[a-z0-9_]+\s*\(/i', (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getBasename();
        }
    }

    expect($offenders)->toBe([]);
});

it('builds on crypto-for-laravel rather than a third-party crypto vendor')
    ->expect('RoundlyConsulting\Purchases')
    ->not->toUse([
        'Firebase\JWT',
        'Lcobucci\JWT',
        'Jose\Component',
        'ParagonIE',
        'phpseclib3',
    ]);

it('does not import a crypto class marked @internal', function (): void {
    $internal = [];

    foreach (cryptoSourceFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        if (! str_contains($contents, '@internal') || preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
            continue;
        }

        $internal[] = $namespace[1].'\\'.$file->getBasename('.php');
    }

    // Assert the scan actually covers crypto's OpenSSL gateways by name, so the
    // guard cannot silently stop covering them if a docblock ever moves.
    expect($internal)
        ->toContain('RoundlyConsulting\Crypto\X509\OpenSslX509')
        ->toContain('RoundlyConsulting\Crypto\Signature\OpenSsl');

    $offenders = [];

    foreach (packageSourceFiles() as $file) {
        $contents = (string) file_get_contents($file->getPathname());

        foreach ($internal as $class) {
            if (str_contains($contents, 'use '.$class.';')) {
                $offenders[] = $file->getBasename().' → '.$class;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * @return list<SplFileInfo>
 */
function cryptoSourceFiles(): array
{
    return phpFilesIn(__DIR__.'/../vendor/roundly-consulting/crypto-for-laravel/src');
}

/**
 * @return list<SplFileInfo>
 */
function packageSourceFiles(): array
{
    return phpFilesIn(__DIR__.'/../src');
}

/**
 * @return list<SplFileInfo>
 */
function phpFilesIn(string $directory): array
{
    $files = [];

    /** @var iterable<SplFileInfo> $iterator */
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file;
        }
    }

    return $files;
}
