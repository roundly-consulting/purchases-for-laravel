<?php

declare(strict_types=1);

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

/*
 * Crypto primitives are crypto-for-laravel's, not ours: HMAC, constant-time
 * compares, RSA/ECDSA sign+verify, key loading, base64(url), and CSPRNG bytes
 * all route through RoundlyConsulting\Crypto.
 *
 * Deliberately NOT banned: the openssl_x509_* family. Apple's App Store
 * notification trust is an X.509 chain pinned to Apple's published WWDR
 * intermediate and G3 root — a *trust policy*, not a generic algorithm. crypto
 * owns algorithms; the certificate-chain decision stays here, in
 * Apple\ValueObjects\CertificateChain and Apple\Jws\JwsVerifier.
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
        'random_bytes',
        'base64_encode',
        'base64_decode',
    ]);

it('keeps apple certificate-chain trust out of crypto')
    ->expect('RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\CertificateChain')
    ->not->toUse('RoundlyConsulting\Crypto');

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

    expect($internal)->not->toBeEmpty();

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
