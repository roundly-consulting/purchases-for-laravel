<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Apple\ValueObjects;

/**
 * A value object that can be hydrated from a decoded JSON payload.
 */
interface FromRaw
{
    /**
     * @param  array<string, mixed>  $raw
     * @return static
     */
    public static function fromRaw(array $raw): self;
}
