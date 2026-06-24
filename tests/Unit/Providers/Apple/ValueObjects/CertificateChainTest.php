<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Apple\ValueObjects\CertificateChain;

function makeCertificate(): OpenSSLCertificate
{
    $key = generateEcKey();

    $options = ['digest_alg' => 'sha256'];

    foreach (['/opt/homebrew/etc/openssl@3/openssl.cnf', '/usr/local/etc/openssl@3/openssl.cnf', '/etc/ssl/openssl.cnf'] as $config) {
        if (is_file($config)) {
            $options['config'] = $config;
            break;
        }
    }

    $csr = openssl_csr_new(['commonName' => 'Node'], $key, $options);

    return openssl_csr_sign($csr, null, $key, 1, $options);
}

it('computes intermediate and root fingerprints', function (): void {
    $chain = new CertificateChain(makeCertificate(), makeCertificate(), makeCertificate());

    expect($chain->fingerprint())->toHaveCount(2)
        ->and($chain->fingerprintIs(['nope', 'still-nope']))->toBeFalse();
});

it('reports validity of self-signed certificates in the chain', function (): void {
    $chain = new CertificateChain(makeCertificate(), makeCertificate(), makeCertificate());

    // Self-signed, unrelated certificates do not validate against each other.
    expect($chain->leafIsValid())->toBeFalse()
        ->and($chain->intermediateIsValid())->toBeFalse();
});
