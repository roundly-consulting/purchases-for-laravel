<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * OpenSSL options for the certificate helpers below.
 *
 * Some local OpenSSL builds ship a config without EC sections, so point at a
 * known-good one when it is present.
 *
 * @return array<string, string>
 */
function opensslOptions(): array
{
    $options = ['digest_alg' => 'sha256'];

    foreach (['/opt/homebrew/etc/openssl@3/openssl.cnf', '/usr/local/etc/openssl@3/openssl.cnf', '/etc/ssl/openssl.cnf'] as $config) {
        if (is_file($config)) {
            $options['config'] = $config;
            break;
        }
    }

    return $options;
}

/**
 * Generate a fresh EC P-256 private key for the JWS crypto tests.
 */
function generateEcKey(): OpenSSLAsymmetricKey
{
    $options = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
        ...opensslOptions(),
    ];

    $key = openssl_pkey_new($options);

    if ($key === false) {
        // Last resort: let OpenSSL use its built-in defaults.
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
    }

    expect($key)->not->toBeFalse();

    return $key;
}

/**
 * The PKCS#8 private PEM for an OpenSSL key.
 */
function privatePem(OpenSSLAsymmetricKey $key): string
{
    openssl_pkey_export($key, $pem, null, opensslOptions());

    return (string) $pem;
}

/**
 * Issue a certificate for $key, signed by $issuer (self-signed when null).
 *
 * @param  array{0: OpenSSLCertificate, 1: OpenSSLAsymmetricKey}|null  $issuer
 */
function issueCertificate(string $commonName, OpenSSLAsymmetricKey $key, ?array $issuer = null): OpenSSLCertificate
{
    $options = opensslOptions();

    $csr = openssl_csr_new(['commonName' => $commonName], $key, $options);

    return openssl_csr_sign(
        $csr,
        $issuer[0] ?? null,
        $issuer[1] ?? $key,
        3650,
        $options,
    );
}

/**
 * The base64 DER body of a certificate — one `x5c` header entry.
 */
function x5cEntry(OpenSSLCertificate $certificate): string
{
    openssl_x509_export($certificate, $pem);

    return (string) preg_replace('/-----.*?-----|\s+/', '', (string) $pem);
}

/**
 * The SHA-1 fingerprint a pinned chain is compared against.
 */
function certificateFingerprint(OpenSSLCertificate $certificate): string
{
    return (string) openssl_x509_fingerprint($certificate);
}
