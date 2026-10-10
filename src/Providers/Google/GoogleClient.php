<?php

declare(strict_types=1);

namespace RoundlyConsulting\Purchases\Providers\Google;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Purchases\Providers\Google\Auth\AccessTokenFactory;
use RoundlyConsulting\Purchases\Providers\Google\Auth\ServiceAccountCredentials;

/**
 * Thin wrapper over Laravel's Http client adding the OAuth2 bearer token and base url
 * for the Google Play Developer API.
 */
final class GoogleClient
{
    public function __construct(
        private readonly ServiceAccountCredentials $credentials,
        private readonly string $baseUrl,
        private readonly AccessTokenFactory $tokens = new AccessTokenFactory,
    ) {}

    /**
     * Exchange the service-account credentials for a fresh access token, never a cached one.
     */
    public function refreshToken(): void
    {
        $this->tokens->fresh($this->credentials);
    }

    public function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->tokens->token($this->credentials))
            ->acceptJson()
            ->throw();
    }
}
