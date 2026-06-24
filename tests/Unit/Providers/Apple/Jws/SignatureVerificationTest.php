<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Providers\Apple\Jws\DecodedToken;
use RoundlyConsulting\Purchases\Providers\Apple\Jws\JwsVerifier;
use RoundlyConsulting\Purchases\Support\Base64Url;

/**
 * Exposes the protected ES256 signature check so the crypto can be tested
 * directly against a real certificate, independent of Apple's CA chain.
 */
final class SignatureProbe extends JwsVerifier
{
    public function check(DecodedToken $token, OpenSSLCertificate $certificate): bool
    {
        return $this->verifySignature($token, $certificate);
    }
}

function opensslConfigOptions(): array
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

function rawSignatureFromDer(string $der): string
{
    $offset = 0;
    $offset++; // SEQUENCE tag
    $offset++; // SEQUENCE length

    $readInteger = function () use (&$offset, $der): string {
        $offset++; // INTEGER tag
        $length = ord($der[$offset++]);
        $value = substr($der, $offset, $length);
        $offset += $length;

        return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
    };

    return $readInteger().$readInteger();
}

it('accepts a genuine ES256 signature and rejects a tampered one', function (): void {
    $key = generateEcKey();
    $options = opensslConfigOptions();

    $csr = openssl_csr_new(['commonName' => 'Leaf'], $key, $options);
    $cert = openssl_csr_sign($csr, null, $key, 1, $options);

    $signingInput = Base64Url::encode('{"alg":"ES256"}').'.'.Base64Url::encode('{"hello":"world"}');
    openssl_sign($signingInput, $derSignature, $key, OPENSSL_ALGO_SHA256);
    $rawSignature = rawSignatureFromDer($derSignature);

    $probe = new SignatureProbe;

    $validToken = new DecodedToken(
        header: ['alg' => 'ES256'],
        claims: ['hello' => 'world'],
        signingInput: $signingInput,
        signature: $rawSignature,
    );

    expect($probe->check($validToken, $cert))->toBeTrue();

    // Tamper with the signing input -> signature no longer matches.
    $tamperedToken = new DecodedToken(
        header: ['alg' => 'ES256'],
        claims: ['hello' => 'tampered'],
        signingInput: $signingInput.'tampered',
        signature: $rawSignature,
    );

    expect($probe->check($tamperedToken, $cert))->toBeFalse();
});
