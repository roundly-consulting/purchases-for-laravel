<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use RoundlyConsulting\Purchases\Exceptions\VerificationException;

/**
 * Converts a raw ECDSA P-256 signature (the fixed 64-byte R||S concatenation used
 * by JWS ES256) into the ASN.1 DER SEQUENCE that ext-openssl's verifier expects.
 */
final class EcdsaSignature
{
    /** Byte length of each of R and S for the P-256 curve. */
    private const COORDINATE_LENGTH = 32;

    public static function toDer(string $signature): string
    {
        if (strlen($signature) !== self::COORDINATE_LENGTH * 2) {
            throw VerificationException::because('Invalid ES256 signature length; expected 64 bytes.');
        }

        $r = substr($signature, 0, self::COORDINATE_LENGTH);
        $s = substr($signature, self::COORDINATE_LENGTH);

        $sequence = self::encodeInteger($r).self::encodeInteger($s);

        // For P-256 the DER body is always well under 128 bytes, so a single
        // short-form length byte is always sufficient.
        return "\x30".chr(strlen($sequence)).$sequence;
    }

    private static function encodeInteger(string $value): string
    {
        // Drop superfluous leading zero bytes, but keep one so the value stays positive.
        $value = ltrim($value, "\x00");

        if ($value === '') {
            $value = "\x00";
        }

        // A leading bit of 1 would read as negative in DER, so pad with a zero byte.
        if ((ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".chr(strlen($value)).$value;
    }
}
