<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers;

use Illuminate\Http\Client\RequestException;
use RoundlyConsulting\Purchases\Exceptions\VerificationException;

/**
 * What every store provider does with client input it sends to a store's API: puts an id
 * into a path as exactly one segment, and tells a client's bad input apart from a store
 * that cannot answer.
 *
 * @internal
 */
final class StoreApi
{
    /**
     * 4xx answers that are not about the client's input: refused credentials (401, 403) and a
     * rate limit (429). Empty this list to turn every 4xx into a VerificationException.
     */
    public const array NOT_ABOUT_THE_INPUT = [401, 403, 429];

    /**
     * Segments for a store API path, each percent-encoded: an id may come from a client, and
     * unescaped it could add a query string (`?expand[]=…`), cut the path short (`#`) or walk
     * it (`../`) to another endpoint. An empty, `.` or `..` segment is refused: no escaping
     * keeps it a segment of its own, because the HTTP client resolves it, onto a list or a
     * parent endpoint.
     *
     * @throws VerificationException
     */
    public static function segments(string $malformed, string ...$segments): string
    {
        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw VerificationException::because($malformed);
            }
        }

        return implode('/', array_map(rawurlencode(...), $segments));
    }

    /**
     * Ask the store about input a client sent up. A 4xx about that input (400 malformed, 404
     * unknown, 410 gone) is the client's mistake, so it is a VerificationException, like any
     * other bad callback input, with the store's answer as its previous exception. A 401 /
     * 403 / 429, a 5xx and a connection failure say nothing about the input: they stay as
     * they are, for the host to retry or answer 500.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $lookup
     * @return TResult
     *
     * @throws VerificationException
     */
    public static function lookUp(callable $lookup, string $rejected): mixed
    {
        try {
            return $lookup();
        } catch (RequestException $e) {
            if (! $e->response->clientError() || in_array($e->response->status(), self::NOT_ABOUT_THE_INPUT, true)) {
                throw $e;
            }

            throw new VerificationException($rejected, previous: $e);
        }
    }
}
