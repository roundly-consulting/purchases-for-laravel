<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Generate a fresh EC P-256 private key for the JWS crypto tests.
 *
 * Some local OpenSSL builds need an explicit config path to create keys, so we
 * fall back to a known config file when one is present.
 */
function generateEcKey(): OpenSSLAsymmetricKey
{
    $options = [
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ];

    foreach (['/opt/homebrew/etc/openssl@3/openssl.cnf', '/usr/local/etc/openssl@3/openssl.cnf', '/etc/ssl/openssl.cnf'] as $config) {
        if (is_file($config)) {
            $options['config'] = $config;
            break;
        }
    }

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
