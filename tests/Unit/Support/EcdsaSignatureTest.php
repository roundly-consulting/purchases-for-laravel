<?php

declare(strict_types=1);

use RoundlyConsulting\Purchases\Exceptions\VerificationException;
use RoundlyConsulting\Purchases\Support\EcdsaSignature;

/**
 * Convert an OpenSSL DER ECDSA signature into the raw 64-byte R||S form that JWS uses.
 */
function derToRawSignature(string $der): string
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

it('produces a DER signature openssl can verify against the real public key', function (): void {
    $key = generateEcKey();

    $message = 'the message to sign';
    openssl_sign($message, $derSignature, $key, OPENSSL_ALGO_SHA256);

    $rawSignature = derToRawSignature($derSignature);
    expect(strlen($rawSignature))->toBe(64);

    $details = openssl_pkey_get_details($key);
    $publicKey = openssl_pkey_get_public($details['key']);

    $reconstructedDer = EcdsaSignature::toDer($rawSignature);

    expect(openssl_verify($message, $reconstructedDer, $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('rejects a tampered signature', function (): void {
    $key = generateEcKey();

    openssl_sign('original', $derSignature, $key, OPENSSL_ALGO_SHA256);
    $raw = derToRawSignature($derSignature);

    // Flip a byte in S.
    $raw[40] = $raw[40] === "\x00" ? "\x01" : "\x00";

    $details = openssl_pkey_get_details($key);
    $publicKey = openssl_pkey_get_public($details['key']);

    expect(openssl_verify('original', EcdsaSignature::toDer($raw), $publicKey, OPENSSL_ALGO_SHA256))->not->toBe(1);
});

it('pads integers whose high bit is set so they stay positive', function (): void {
    // R starts with 0x80 (negative in DER) and must be prefixed with 0x00.
    $signature = str_repeat("\x80", 32).str_repeat("\x01", 32);

    $der = EcdsaSignature::toDer($signature);

    // 0x30 SEQ, then 0x02 INT, length 0x21 (33), then 0x00 pad, then 0x80...
    expect(bin2hex(substr($der, 2, 4)))->toBe('02210080');
});

it('strips superfluous leading zero bytes', function (): void {
    // R is 31 leading zero bytes then 0x05 -> integer value 5, encoded as a single byte.
    $signature = str_repeat("\x00", 31)."\x05".str_repeat("\x01", 32);

    $der = EcdsaSignature::toDer($signature);

    // First integer should encode as a single 0x05 byte: 0x02 (INT) 0x01 (len) 0x05.
    expect(bin2hex(substr($der, 2, 3)))->toBe('020105');
});

it('throws when the signature is not 64 bytes', function (): void {
    EcdsaSignature::toDer('too short');
})->throws(VerificationException::class);

it('encodes a maximal signature as a valid short-form sequence', function (): void {
    // Both integers maximal (high bit set -> padded to 33 bytes each) gives a 70-byte body.
    $signature = str_repeat("\xff", 64);

    $der = EcdsaSignature::toDer($signature);

    // 0x30 SEQUENCE, length 0x46 (70), then two 0x02 INTEGER (0x21 = 33 bytes each).
    expect(bin2hex($der[0]))->toBe('30')
        ->and(bin2hex($der[1]))->toBe('46')
        ->and(strlen($der))->toBe(72);
});
