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
class StripeClient
{
    public function __construct(
        #[SensitiveParameter] private readonly string $secret,
        private readonly string $baseUrl,
        private readonly string $apiVersion,
    ) {}

    public function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->secret)
            ->withHeaders(['Stripe-Version' => $this->apiVersion])
            ->asForm()
            ->throw();
    }
}
