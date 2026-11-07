<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Support;

use Illuminate\Support\Str;
use RoundlyConsulting\Purchases\Exceptions\BillingException;

/**
 * The `Idempotency-Key` every Stripe write is sent with: the caller's, or a fresh UUID.
 *
 * Retrying a call with the key it was first sent with makes Stripe replay the first answer
 * instead of acting twice. A billing exception carries the key it was sent with.
 *
 * @internal
 */
final class IdempotencyKey
{
    /** Stripe's limit. */
    public const int MAX_LENGTH = 255;

    public static function resolve(?string $key): string
    {
        if ($key === null) {
            return (string) Str::uuid();
        }

        return self::validated($key);
    }

    /**
     * A key for one step of a call that sends several requests (a tax id a customer sync
     * adds): the call's key plus the step, so retrying the call replays every step.
     */
    public static function derive(string $key, string $step): string
    {
        return self::validated($key.':'.$step);
    }

    private static function validated(string $key): string
    {
        if (trim($key) === '' || strlen($key) > self::MAX_LENGTH) {
            throw BillingException::invalid('An idempotency key must be 1 to '.self::MAX_LENGTH.' characters long.');
        }

        return $key;
    }
}
