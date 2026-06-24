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

    /**
     * Convert an ASN.1 DER ECDSA signature (as produced by openssl_sign) into the
     * fixed 64-byte R||S concatenation that JWS ES256 expects.
     */
    public static function fromDer(string $der): string
    {
        $offset = 0;

        if (($der[$offset] ?? '') !== "\x30") {
            throw VerificationException::because('Invalid DER signature; expected a SEQUENCE.');
        }

        $offset += 2; // SEQUENCE tag + length byte (always short-form for P-256).

        $r = self::readInteger($der, $offset);
        $s = self::readInteger($der, $offset);

        return self::pad($r).self::pad($s);
    }

    private static function readInteger(string $der, int &$offset): string
    {
        if (($der[$offset] ?? '') !== "\x02") {
            throw VerificationException::because('Invalid DER signature; expected an INTEGER.');
        }

        $length = ord($der[$offset + 1]);
        $value = substr($der, $offset + 2, $length);
        $offset += 2 + $length;

        return ltrim($value, "\x00");
    }

    private static function pad(string $value): string
    {
        return str_pad($value, self::COORDINATE_LENGTH, "\x00", STR_PAD_LEFT);
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
