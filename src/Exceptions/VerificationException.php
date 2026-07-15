<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions;

class VerificationException extends Exception
{
    /**
     * A certificate in the notification's chain has expired.
     *
     * Deliberately distinct from a signature failure: an expired chain means the
     * signing certificate rotated (or the notification is a replay of an archived
     * one), which is a very different operational problem from a forged payload —
     * so it must never read as "bad signature".
     */
    public static function certificateExpired(string $commonName, string $notAfter): static
    {
        return new static("Apple certificate [{$commonName}] expired at {$notAfter}; the notification's certificate chain is outside its validity period.");
    }

    /**
     * A certificate in the notification's chain is not valid yet — in practice a
     * badly skewed host clock rather than an attack.
     */
    public static function certificateNotYetValid(string $commonName, string $notBefore): static
    {
        return new static("Apple certificate [{$commonName}] is not valid before {$notBefore}; the notification's certificate chain is outside its validity period.");
    }
}
