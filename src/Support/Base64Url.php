<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use RoundlyConsulting\Purchases\Exceptions\VerificationException;

/**
 * RFC 7515 base64url encoding/decoding (unpadded), as used by JWS.
 */
final class Base64Url
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $remainder = strlen($value) % 4;

        if ($remainder !== 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        if ($decoded === false) {
            throw VerificationException::because('Invalid base64url segment.');
        }

        return $decoded;
    }
}
