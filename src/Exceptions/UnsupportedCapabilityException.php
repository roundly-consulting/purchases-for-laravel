<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions;

/**
 * A provider was asked for something it cannot do — e.g. outbound billing from Apple or
 * Google, which only Stripe offers.
 */
final class UnsupportedCapabilityException extends Exception
{
    public static function billing(string $provider): self
    {
        return new self("The [{$provider}] provider does not support outbound billing. Only a registered Stripe provider does.");
    }
}
