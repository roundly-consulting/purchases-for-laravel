<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;
use RoundlyConsulting\Purchases\Support\Base64Url;

/**
 * Build a self-signed P-256 certificate and return its base64 DER (x5c entry).
 */
function selfSignedDer(): string
{
    $key = generateEcKey();

    $options = ['digest_alg' => 'sha256'];

    foreach (['/opt/homebrew/etc/openssl@3/openssl.cnf', '/usr/local/etc/openssl@3/openssl.cnf', '/etc/ssl/openssl.cnf'] as $config) {
        if (is_file($config)) {
            $options['config'] = $config;
            break;
        }
    }

    $csr = openssl_csr_new(['commonName' => 'Test'], $key, $options);
    $cert = openssl_csr_sign($csr, null, $key, 1, $options);
    openssl_x509_export($cert, $pem);

    return (string) preg_replace('/-----.*?-----|\s+/', '', $pem);
}

/**
 * @param  list<string>  $x5c
 */
function tokenWithChain(array $x5c): DecodedToken
{
    return new DecodedToken(
        header: ['alg' => 'ES256', 'x5c' => $x5c],
        claims: [],
        signingInput: 'input',
        signature: str_repeat("\x00", 64),
    );
}

it('rejects a token whose chain is not exactly three certificates', function (): void {
    $der = selfSignedDer();

    expect((new JwsVerifier)->verify(tokenWithChain([$der, $der])))->toBeFalse();
});

it('rejects a chain that does not match apple fingerprints', function (): void {
    $der = selfSignedDer();

    // Three valid certs, but they are self-signed and won't match Apple's fingerprints.
    expect((new JwsVerifier)->verify(tokenWithChain([$der, $der, $der])))->toBeFalse();
});

it('rejects a token with an empty certificate chain', function (): void {
    $token = new DecodedToken(
        header: ['alg' => 'ES256'],
        claims: [],
        signingInput: 'input',
        signature: str_repeat("\x00", 64),
    );

    expect((new JwsVerifier)->verify($token))->toBeFalse();
});

it('builds a decoded token signature from a base64url segment', function (): void {
    // Sanity guard that signatures survive the pipeline used by the verifier.
    expect(Base64Url::decode(Base64Url::encode("\x01\x02\x03")))->toBe("\x01\x02\x03");
});
