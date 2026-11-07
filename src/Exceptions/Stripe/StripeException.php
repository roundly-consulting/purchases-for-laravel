<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Exceptions\Stripe;

use RoundlyConsulting\Purchases\Exceptions\Exception;

/**
 * Stripe refused or could not answer a billing call. Catch this base class for every Stripe
 * failure, or one of its leaves for a single kind:
 *
 * - {@see StripeCardException} — the card was declined (`card_error`, HTTP 402)
 * - {@see StripeInvalidRequestException} — bad parameters or an unknown id (`invalid_request_error`, HTTP 400 / 404)
 * - {@see StripeAuthenticationException} — the secret key was refused or lacks a permission (HTTP 401 / 403)
 * - {@see StripeRateLimitException} — too many requests (HTTP 429); retry with backoff
 * - {@see StripeIdempotencyException} — the idempotency key was reused with other parameters (`idempotency_error`)
 * - {@see StripeApiException} — anything else Stripe answered (a 5xx, a conflict, a dependency failure)
 * - {@see StripeConnectionException} — Stripe could not be reached
 *
 * The message is Stripe's own, with anything shaped like an API key or webhook secret
 * removed. Neither the HTTP response nor the client is ever attached or passed to the frame
 * that builds it: the request carries the secret key, and a stack trace keeps arguments.
 */
abstract class StripeException extends Exception
{
    /** Stripe's `error.type` (`card_error`, `invalid_request_error`, `idempotency_error`, `api_error`). */
    public protected(set) ?string $stripeType = null;

    /** Stripe's `error.code`, e.g. `resource_missing`, when Stripe gave one. */
    public protected(set) ?string $stripeCode = null;

    /** The card issuer's `error.decline_code`, for a declined card. */
    public protected(set) ?string $declineCode = null;

    /** The request parameter Stripe blamed (`error.param`). */
    public protected(set) ?string $param = null;

    /** The HTTP status Stripe answered, or null when it was not reached. */
    public protected(set) ?int $httpStatus = null;

    /** Stripe's `Request-Id` header, for Stripe support. */
    public protected(set) ?string $requestId = null;

    /** The idempotency key the call was sent with: retry with the same key. */
    public protected(set) ?string $idempotencyKey = null;

    /**
     * The exception for a failed Stripe answer, from its plain parts.
     *
     * It takes no HTTP response and no client: an exception's stack trace keeps the
     * arguments of the frame that built it, and the response's request carries the secret
     * key. The message must already be redacted.
     *
     * @param  array<array-key, mixed>  $error  Stripe's `error` object, its message redacted
     */
    public static function fromError(int $status, array $error, ?string $requestId, ?string $idempotencyKey): self
    {
        $type = self::text($error['type'] ?? null);

        $class = match (true) {
            $type === 'idempotency_error' => StripeIdempotencyException::class,
            $type === 'card_error' => StripeCardException::class,
            $status === 401, $status === 403 => StripeAuthenticationException::class,
            $status === 429 => StripeRateLimitException::class,
            // A conflict (a lock timeout, a concurrent request with the same key) is worth a retry.
            $status === 409 => StripeApiException::class,
            $type === 'invalid_request_error', $status === 400, $status === 404 => StripeInvalidRequestException::class,
            default => StripeApiException::class,
        };

        $exception = new $class(self::text($error['message'] ?? null) ?? "Stripe answered HTTP {$status}.", $status);
        $exception->stripeType = $type;
        $exception->stripeCode = self::text($error['code'] ?? null);
        $exception->declineCode = self::text($error['decline_code'] ?? null);
        $exception->param = self::text($error['param'] ?? null);
        $exception->httpStatus = $status;
        $exception->requestId = $requestId;
        $exception->idempotencyKey = $idempotencyKey;

        return $exception;
    }

    /**
     * Stripe could not be reached. The reason must already be redacted.
     */
    public static function unreachable(string $reason, ?string $idempotencyKey): StripeConnectionException
    {
        $exception = new StripeConnectionException('Stripe could not be reached: '.$reason);
        $exception->idempotencyKey = $idempotencyKey;

        return $exception;
    }

    /**
     * Stripe answered, but not with a JSON object this package can read.
     */
    public static function unreadable(int $status, ?string $idempotencyKey): StripeApiException
    {
        $exception = new StripeApiException("Stripe's answer could not be read (HTTP {$status}).", $status);
        $exception->httpStatus = $status;
        $exception->idempotencyKey = $idempotencyKey;

        return $exception;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
