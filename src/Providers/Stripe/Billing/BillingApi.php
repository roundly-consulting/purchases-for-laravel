<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe\Billing;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RoundlyConsulting\Purchases\Exceptions\BillingException;
use RoundlyConsulting\Purchases\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\Purchases\Exceptions\Stripe\StripeException;
use RoundlyConsulting\Purchases\Providers\Stripe\Stripe;
use RoundlyConsulting\Purchases\Providers\Stripe\StripeClient;
use RoundlyConsulting\Purchases\Support\PurchasesConfig;
use Throwable;

/**
 * The outbound half of the Stripe provider: form-encoded calls to Stripe's REST API that
 * return the decoded object or throw a typed {@see StripeException}.
 *
 * Every write carries an `Idempotency-Key` (a read-only POST, the invoice preview, none).
 * Stripe ignores the header on DELETE; it is sent there only to keep one shape, and it is
 * on the exception either way.
 *
 * @internal building block of the billing actions.
 */
final class BillingApi
{
    private ?StripeClient $client = null;

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): BillingResponse
    {
        return $this->send('GET', $path, $query, null);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function post(string $path, array $params, ?string $idempotencyKey): BillingResponse
    {
        return $this->send('POST', $path, $params, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function delete(string $path, array $query, string $idempotencyKey): BillingResponse
    {
        return $this->send('DELETE', $path, $query, $idempotencyKey);
    }

    /**
     * A Stripe API path from ids a caller handed in, each one percent-encoded so it stays one
     * segment. An empty, `.` or `..` id is refused: no escaping keeps it a segment of its own.
     *
     * @throws BillingException
     */
    public static function path(string ...$segments): string
    {
        foreach ($segments as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw BillingException::invalid('Malformed Stripe id.');
            }
        }

        return '/'.implode('/', array_map(rawurlencode(...), $segments));
    }

    /**
     * Stripe's form encoding: a boolean is the word `true` / `false` (PHP would send 1 / 0),
     * and a null is left out, so an optional value nobody set is never sent.
     *
     * @param  array<array-key, mixed>  $params
     * @return array<array-key, mixed>
     */
    public static function encode(array $params): array
    {
        $encoded = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }

            $encoded[$key] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_array($value) => self::encode($value),
                default => $value,
            };
        }

        return $encoded;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function send(string $method, string $path, array $params, ?string $idempotencyKey): BillingResponse
    {
        $client = $this->client();
        $request = $client->pending();
        $params = self::encode($params);

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        }

        try {
            $response = match ($method) {
                'POST' => $request->post($path, $params),
                'DELETE' => $request->withQueryParameters($params)->delete($path),
                default => $request->get($path, $params),
            };
        } catch (ConnectionException $e) {
            throw StripeException::unreachable($client->redact($e->getMessage()), $idempotencyKey);
        }

        if ($response->failed()) {
            // Read here, not in a helper: a helper handed the response would keep it — and
            // its request's Authorization header — in the exception's stack trace.
            $error = $response->json('error');
            $error = is_array($error) ? $error : [];

            if (is_string($error['message'] ?? null)) {
                $error['message'] = $client->redact($error['message']);
            }

            $requestId = $response->header('Request-Id');

            throw StripeException::fromError($response->status(), $error, $requestId !== '' ? $requestId : null, $idempotencyKey);
        }

        $body = $response->json();

        if (! is_array($body) || array_is_list($body) && $body !== []) {
            throw StripeException::unreadable($response->status(), $idempotencyKey);
        }

        /** @var array<string, mixed> $body */
        return new BillingResponse($body, self::date($response));
    }

    private static function date(Response $response): ?CarbonImmutable
    {
        $date = $response->header('Date');

        if ($date === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date);
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): StripeClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $config = config('purchases.settings.stripe');
        $config = is_array($config) ? $config : [];

        $secret = PurchasesConfig::credential($config['secret'] ?? null, 'purchases.settings.stripe.secret');

        if ($secret === null) {
            throw new InvalidConfigurationException('[purchases.settings.stripe.secret] is not set: Stripe billing calls need the secret key.');
        }

        return $this->client = new StripeClient(
            secret: $secret,
            baseUrl: PurchasesConfig::string($config['base_url'] ?? null, 'purchases.settings.stripe.base_url', 'https://api.stripe.com/v1'),
            apiVersion: PurchasesConfig::string($config['api_version'] ?? null, 'purchases.settings.stripe.api_version', Stripe::API_VERSION),
        );
    }
}
