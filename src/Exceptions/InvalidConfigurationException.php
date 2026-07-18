<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions;

/**
 * Thrown when a package config value is unusable.
 *
 * Deliberately NOT a {@see VerificationException}: a misconfigured host is not a
 * forged notification, and a security check must never be able to fall back to a
 * weaker (or absent) rule because a value was typed wrong.
 */
final class InvalidConfigurationException extends Exception
{
    public static function clockSkew(mixed $value, int $max): self
    {
        $shown = is_scalar($value) ? var_export($value, true) : get_debug_type($value);

        return new self(
            "[purchases.settings.apple.certificate_clock_skew] must be a whole number of seconds between 0 and {$max}; got {$shown}.",
        );
    }
}
