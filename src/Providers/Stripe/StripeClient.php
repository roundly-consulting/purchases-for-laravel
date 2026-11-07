<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Stripe;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Thin wrapper over Laravel's Http client for Stripe's REST API, pinning the
 * configured API version and authenticating with the secret key.
 */
final class StripeClient
{
    public function __construct(
        #[SensitiveParameter] private readonly string $secret,
        private readonly string $baseUrl,
        private readonly string $apiVersion,
    ) {}

    /**
     * A request that throws the HTTP client's RequestException on a 4xx or 5xx answer.
     */
    public function request(): PendingRequest
    {
        return $this->pending()->throw();
    }

    /**
     * A request that returns every answer, failed or not, for the caller to read.
     */
    public function pending(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->secret)
            ->withHeaders(['Stripe-Version' => $this->apiVersion])
            ->asForm();
    }

    /**
     * The text with the secret key, and anything else shaped like a Stripe key or webhook
     * secret, replaced — for a message that may end up in a log or an error tracker.
     */
    public function redact(string $text): string
    {
        $text = str_replace($this->secret, '[redacted]', $text);

        return (string) preg_replace('/\b(?:sk|rk)_(?:test|live)_[A-Za-z0-9*]+|\bwhsec_[A-Za-z0-9*]+/', '[redacted]', $text);
    }
}
